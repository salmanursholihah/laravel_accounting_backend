<?php

namespace App\Modules\Purchasing\Invoices;

use App\Modules\Accounting\Approval\ApprovalService;
use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\PurchasingException;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemUnitConversion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draft Faktur Pembelian (UC-42), langsung tanpa PO/Penerimaan (MVP). Hanya Draft yang boleh diubah/dihapus.
 * Nomor, stok masuk, dan jurnal terjadi saat Posting (orkestrator ALG-02 + PurchaseInvoicePostingHandler).
 *
 * Baris barang: item_id bertipe barang (+ gudang; default gudang bawaan) -> stok masuk.
 * Baris jasa/biaya: item jasa atau account_id (akun beban/HPP/aset tetap).
 * Nomor faktur pemasok wajib dan unik per pemasok (mencegah input ganda); void tidak melepaskan nomornya.
 */
class PurchaseInvoiceService
{
    public const DOC = 'purchase_invoice';

    /** Akun non-barang yang boleh dipakai baris faktur pembelian. */
    private const TIPE_AKUN = ['expense', 'cogs', 'other_expense'];

    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
        private PeriodService $periods,
        private TaxCalculator $taxes,
    ) {
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'contact_id' => ['required', 'integer'],
            'no_faktur_pemasok' => ['required', 'string', 'max:80'],
            'no_faktur_pajak' => ['nullable', 'string', 'max:50'],
            'dapat_dikreditkan' => ['sometimes', 'boolean'],
            'payment_term_id' => ['nullable', 'integer'],
            'harga_termasuk_pajak' => ['sometimes', 'boolean'],
            'diskon' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.deskripsi' => ['nullable', 'string', 'max:500'],
            'lines.*.qty' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.harga' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.diskon_persen' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'lines.*.tax_code_id' => ['nullable', 'integer'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
        ];
    }

    public function find(int $id): PurchaseInvoice
    {
        return PurchaseInvoice::query()->with('lines')->find($id) ?? throw PurchasingException::notFound('Faktur pembelian');
    }

    public function create(array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $user) {
            [$header, $lines] = $this->prepare($data, null);
            $doc = PurchaseInvoice::create($header + ['status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'create', null, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function update(int $id, array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = PurchaseInvoice::query()->lockForUpdate()->find($id) ?? throw PurchasingException::notFound('Faktur pembelian');
            $doc = app(ApprovalService::class)->releaseIfPending($doc, self::DOC); // 6A.4: ubah dokumen pending = batalkan pengajuan
            if ($doc->status !== 'draft') {
                throw PurchasingException::notDraft((string) $doc->status);
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw PurchasingException::conflict();
            }

            [$header, $lines] = $this->prepare($data, (int) $doc->getKey());
            $before = $this->snapshot($doc->load('lines'));
            $doc->forceFill($header + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            PurchaseInvoiceLine::query()->where('purchase_invoice_id', $doc->getKey())->delete();
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'update', $before, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $doc = PurchaseInvoice::query()->lockForUpdate()->find($id) ?? throw PurchasingException::notFound('Faktur pembelian');
            if ($doc->status !== 'draft') {
                throw PurchasingException::notDraft((string) $doc->status);
            }
            $this->audit->log(self::DOC, $id, 'delete', $this->snapshot($doc->load('lines')), null);
            PurchaseInvoiceLine::query()->where('purchase_invoice_id', $id)->delete();
            $doc->delete();
        });
    }

    /** Peringatan (bukan penolakan): harga beli berbeda dari harga beli standar barang; PPN tidak dikreditkan. */
    public function warnings(PurchaseInvoice $doc): array
    {
        if ($doc->status !== 'draft') {
            return [];
        }
        $out = [];
        $items = Item::query()->whereIn('id', $doc->lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
        foreach ($doc->lines as $l) {
            $it = $l->item_id ? $items[$l->item_id] : null;
            if ($it && Money::cmp((string) $it->harga_beli_standar, '0') > 0 && Money::cmp(Money::round((string) $l->harga), Money::round((string) $it->harga_beli_standar)) !== 0) {
                $out[] = ['kode' => 'price_differs', 'pesan' => "Baris {$l->line_no}: harga {$l->harga} berbeda dari harga beli standar {$it->harga_beli_standar}."];
            }
        }
        if (Money::cmp((string) $doc->total_pajak, '0') > 0 && (! $doc->dapat_dikreditkan || trim((string) $doc->no_faktur_pajak) === '')) {
            $out[] = ['kode' => 'tax_not_creditable', 'pesan' => 'PPN tidak dikreditkan (faktur pajak kosong atau ditandai tidak dapat dikreditkan); masuk ke biaya perolehan.'];
        }

        return $out;
    }

    /** Konversi qty satuan input ke satuan dasar barang. */
    public static function toBaseQty(Item $item, ?int $unitId, string $qty): string
    {
        if ($unitId === null || (int) $unitId === (int) $item->unit_id) {
            return Money::round($qty);
        }
        $conv = ItemUnitConversion::query()->where('item_id', $item->getKey())->where('unit_id', $unitId)->first()
            ?? throw new PurchasingException("Barang {$item->kode} tidak punya konversi untuk satuan #{$unitId}.", 'unit_conversion_missing');

        return Money::round(Money::mul($qty, (string) $conv->rasio_ke_dasar));
    }

    // ------------------------------------------------------------------------------------------- internal

    /** @return array{0: array, 1: list<array>} */
    private function prepare(array $data, ?int $ignoreId): array
    {
        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);
        $cid = $this->current->id();

        $sup = Supplier::query()->where('contact_id', (int) ($data['contact_id'] ?? 0))->first()
            ?? throw new PurchasingException('Pemasok tidak ditemukan.', 'supplier_not_found');
        if (! DB::table('contacts')->where('company_id', $cid)->where('id', $sup->contact_id)->where('is_aktif', true)->exists()) {
            throw new PurchasingException('Pemasok nonaktif tidak bisa dipakai transaksi.', 'supplier_inactive');
        }

        $noFaktur = trim((string) ($data['no_faktur_pemasok'] ?? ''));
        if ($noFaktur === '') {
            throw new PurchasingException('Nomor faktur pemasok wajib diisi.', 'supplier_invoice_required');
        }
        self::assertSupplierInvoiceUnique((int) $sup->contact_id, $noFaktur, $ignoreId);

        $termId = $data['payment_term_id'] ?? $sup->payment_term_id;
        $jatuhTempo = $tanggal;
        if ($termId !== null) {
            $term = PaymentTerm::query()->where('is_aktif', true)->find($termId) ?? throw PurchasingException::notFound('Syarat pembayaran');
            $jatuhTempo = Carbon::parse($tanggal)->addDays((int) $term->jumlah_hari)->toDateString();
        }

        $inclusive = (bool) ($data['harga_termasuk_pajak'] ?? false);
        $diskonHeader = Money::round((string) ($data['diskon'] ?? '0'));
        $lines = $this->prepareLines($data['lines'] ?? []);

        $calc = $this->taxes->calculate([
            'tanggal' => $tanggal, 'harga_termasuk_pajak' => $inclusive, 'diskon_header' => $diskonHeader, 'ongkir' => '0',
            'lines' => array_map(fn ($l) => ['qty' => $l['qty'], 'harga' => $l['harga'], 'diskon_persen' => $l['diskon_persen'], 'tax_code_id' => $l['tax_code_id']], $lines),
        ]);
        foreach ($lines as $i => &$l) {
            $l['jumlah'] = $calc['lines'][$i]['netto'];
        }
        unset($l);

        if (Money::cmp($calc['total'], '0') <= 0) {
            throw new PurchasingException('Total faktur harus lebih dari 0.', 'total_zero');
        }

        return [[
            'tanggal' => $tanggal, 'contact_id' => (int) $sup->contact_id, 'kode_mata_uang' => $this->current->company()->mata_uang_dasar, 'kurs' => '1',
            'subtotal' => $calc['subtotal'], 'diskon' => $calc['diskon_header'], 'dpp' => $calc['dpp'], 'total_pajak' => $calc['total_pajak'],
            'total' => $calc['total'], 'no_faktur_pemasok' => $noFaktur, 'jatuh_tempo' => $jatuhTempo, 'payment_term_id' => $termId,
            'no_faktur_pajak' => ($data['no_faktur_pajak'] ?? null) ?: null, 'dapat_dikreditkan' => (bool) ($data['dapat_dikreditkan'] ?? true),
            'harga_termasuk_pajak' => $inclusive, 'total_dibayar' => '0', 'sisa_tagihan' => '0',
            'status_pembayaran' => 'belum', 'memo' => $data['memo'] ?? null,
        ], $lines];
    }

    /** Dipanggil juga saat posting. DB juga menegakkan unik (company, pemasok, nomor) lintas status termasuk void. */
    public static function assertSupplierInvoiceUnique(int $contactId, string $noFaktur, ?int $ignoreId): void
    {
        $q = PurchaseInvoice::query()->where('contact_id', $contactId)->where('no_faktur_pemasok', $noFaktur);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($dup = $q->first(['id', 'nomor', 'status'])) {
            $ref = $dup->nomor ?: "draft #{$dup->id}";
            throw new PurchasingException("Nomor faktur pemasok '{$noFaktur}' sudah dicatat untuk pemasok ini ({$ref}, {$dup->status}); nomor tidak dipakai ulang, termasuk setelah void.", 'supplier_invoice_duplicate');
        }
    }

    private function prepareLines(array $raw): array
    {
        if ($raw === []) {
            throw new PurchasingException('Faktur harus punya minimal satu baris.', 'no_lines');
        }

        $defaultWh = DB::table('warehouses')->where('company_id', $this->current->id())->where('is_default', true)->where('is_aktif', true)->value('id');
        $out = [];
        foreach (array_values($raw) as $i => $l) {
            $n = $i + 1;
            $item = null;
            if (! empty($l['item_id'])) {
                $item = Item::query()->find((int) $l['item_id']) ?? throw new PurchasingException("Baris {$n}: barang tidak ditemukan.", 'item_not_found');
                if (! $item->is_aktif) {
                    throw new PurchasingException("Baris {$n}: barang {$item->kode} nonaktif.", 'item_inactive');
                }
            } elseif (empty($l['account_id'])) {
                throw new PurchasingException("Baris {$n}: isi barang/jasa atau akun beban.", 'line_target');
            }

            $accountId = null;
            if (! empty($l['account_id'])) {
                $a = Account::query()->find((int) $l['account_id']) ?? throw new PurchasingException("Baris {$n}: akun tidak ditemukan.", 'account_not_found');
                $okTipe = in_array($a->tipe, self::TIPE_AKUN, true) || ($a->tipe === 'asset' && $a->sub_tipe === 'fixed_asset');
                if (! $okTipe || ! $a->isPostable()) {
                    throw new PurchasingException("Baris {$n}: akun {$a->kode} bukan akun beban/HPP/aset tetap yang bisa dipakai.", 'account_invalid');
                }
                if ($item && $item->isBarang()) {
                    throw new PurchasingException("Baris {$n}: baris barang persediaan tidak memakai akun manual (akun ditentukan barang/kategori).", 'account_not_allowed');
                }
                $accountId = (int) $a->getKey();
            }

            $qty = Money::round((string) ($l['qty'] ?? '0'));
            if (Money::cmp($qty, '0') <= 0) {
                throw new PurchasingException("Baris {$n}: qty harus lebih dari 0.", 'line_qty');
            }
            $harga = isset($l['harga']) && $l['harga'] !== null && $l['harga'] !== ''
                ? Money::round((string) $l['harga'])
                : ($item ? Money::round((string) $item->harga_beli_standar) : null);
            if ($harga === null) {
                throw new PurchasingException("Baris {$n}: harga wajib diisi.", 'line_price');
            }
            $diskon = Money::round((string) ($l['diskon_persen'] ?? '0'));

            $warehouseId = null;
            $unitId = isset($l['unit_id']) && $l['unit_id'] !== null ? (int) $l['unit_id'] : null;
            if ($item && $item->isBarang()) {
                $warehouseId = (int) ($l['warehouse_id'] ?? $defaultWh ?? 0);
                $wh = DB::table('warehouses')->where('company_id', $this->current->id())->where('id', $warehouseId)->first();
                if (! $wh || ! $wh->is_aktif) {
                    throw new PurchasingException("Baris {$n}: gudang tidak ditemukan atau nonaktif.", 'warehouse_invalid');
                }
                self::toBaseQty($item, $unitId, $qty); // validasi konversi lebih awal
            }

            $taxId = array_key_exists('tax_code_id', $l) ? $l['tax_code_id'] : $item?->tax_code_beli_id;
            if ($taxId !== null) {
                $tax = TaxCode::query()->find((int) $taxId) ?? throw new PurchasingException("Baris {$n}: kode pajak tidak ditemukan.", 'tax_not_found');
                if (! in_array($tax->jenis, ['ppn', 'ppnbm'], true)) {
                    throw new PurchasingException("Baris {$n}: kode pajak {$tax->kode} ({$tax->jenis}) belum didukung di faktur pembelian.", 'tax_kind_unsupported');
                }
            }

            $out[] = [
                'line_no' => $n, 'item_id' => $item?->getKey(), 'account_id' => $accountId, 'deskripsi' => $l['deskripsi'] ?? $item?->nama,
                'qty' => $qty, 'unit_id' => $unitId, 'harga' => $harga, 'diskon_persen' => $diskon,
                'tax_code_id' => $taxId !== null ? (int) $taxId : null, 'jumlah' => '0', 'warehouse_id' => $warehouseId,
                'qty_terpenuhi' => '0', 'qty_sisa' => '0',
            ];
        }

        return $out;
    }

    private function writeLines(PurchaseInvoice $doc, array $lines): void
    {
        foreach ($lines as $l) {
            PurchaseInvoiceLine::query()->create($l + ['company_id' => $doc->company_id, 'purchase_invoice_id' => $doc->getKey()]);
        }
    }

    private function snapshot(PurchaseInvoice $doc, ?array $lines = null): array
    {
        return [
            'tanggal' => $doc->tanggal?->toDateString(), 'contact_id' => $doc->contact_id, 'no_faktur_pemasok' => $doc->no_faktur_pemasok, 'total' => (string) $doc->total,
            'lines' => $lines ?? $doc->lines->map(fn ($l) => $l->only(['line_no', 'item_id', 'account_id', 'qty', 'harga', 'diskon_persen', 'tax_code_id']))->all(),
        ];
    }
}
