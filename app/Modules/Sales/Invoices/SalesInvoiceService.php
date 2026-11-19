<?php

namespace App\Modules\Sales\Invoices;

use App\Modules\Accounting\Approval\ApprovalService;
use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Sales\Customers\CustomerService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesException;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemUnitConversion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draft Faktur Penjualan (UC-33). Hanya Draft yang boleh diubah/dihapus. Nomor, stok, HPP, dan jurnal terjadi saat Posting
 * (orkestrator ALG-02 + SalesInvoicePostingHandler), bukan di sini. Total dihitung ALG-03 agar tampilan draft = hasil posting.
 *
 * Baris barang: item_id (+ gudang; default gudang bawaan). Baris jasa/akun: item_id bertipe jasa, atau account_id (akun pendapatan).
 * Harga dan diskon: mengubah harga dari harga jual master atau memberi diskon butuh hak khusus ubah_harga_diskon;
 * role bernilai batas (Kasir 5%) tidak boleh melewati batasnya (Tahap 2 bagian 4).
 */
class SalesInvoiceService
{
    public const DOC = 'sales_invoice';

    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
        private PeriodService $periods,
        private TaxCalculator $taxes,
        private PermissionChecker $perm,
        private CustomerService $customers,
    ) {
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'contact_id' => ['required', 'integer'],
            'payment_term_id' => ['nullable', 'integer'],
            'harga_termasuk_pajak' => ['sometimes', 'boolean'],
            'diskon' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'biaya_kirim' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
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

    public function find(int $id): SalesInvoice
    {
        return SalesInvoice::query()->with('lines')->find($id) ?? throw SalesException::notFound('Faktur penjualan');
    }

    public function create(array $data, User $user): SalesInvoice
    {
        [$header, $lines] = $this->prepare($data, $user);

        return DB::transaction(function () use ($header, $lines, $user) {
            $doc = SalesInvoice::create($header + ['status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'create', null, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function update(int $id, array $data, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = SalesInvoice::query()->lockForUpdate()->find($id) ?? throw SalesException::notFound('Faktur penjualan');
            $doc = app(ApprovalService::class)->releaseIfPending($doc, self::DOC); // 6A.4: ubah dokumen pending = batalkan pengajuan
            if ($doc->status !== 'draft') {
                throw SalesException::notDraft((string) $doc->status);
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw SalesException::conflict();
            }

            [$header, $lines] = $this->prepare($data, $user);
            $before = $this->snapshot($doc->load('lines'));

            $doc->forceFill($header + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            SalesInvoiceLine::query()->where('sales_invoice_id', $doc->getKey())->delete();
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'update', $before, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $doc = SalesInvoice::query()->lockForUpdate()->find($id) ?? throw SalesException::notFound('Faktur penjualan');
            if ($doc->status !== 'draft') {
                throw SalesException::notDraft((string) $doc->status);
            }
            $this->audit->log(self::DOC, $id, 'delete', $this->snapshot($doc->load('lines')), null);
            SalesInvoiceLine::query()->where('sales_invoice_id', $id)->delete();
            $doc->delete();
        });
    }

    /** Peringatan (bukan penolakan) untuk tampilan: limit kredit terlampaui (E2). */
    public function warnings(SalesInvoice $doc): array
    {
        $cust = Customer::query()->where('contact_id', $doc->contact_id)->first();
        if (! $cust || Money::isZero((string) $cust->limit_kredit) || $doc->status !== 'draft') {
            return [];
        }
        $proyeksi = Money::add($this->customers->outstanding((int) $doc->contact_id), (string) $doc->total);

        return Money::cmp($proyeksi, (string) $cust->limit_kredit) > 0
            ? [['kode' => 'credit_limit', 'pesan' => "Piutang menjadi {$proyeksi}, melebihi limit kredit {$cust->limit_kredit}."]]
            : [];
    }

    /** Konversi qty satuan input ke satuan dasar barang. */
    public static function toBaseQty(Item $item, ?int $unitId, string $qty): string
    {
        if ($unitId === null || (int) $unitId === (int) $item->unit_id) {
            return Money::round($qty);
        }
        $conv = ItemUnitConversion::query()->where('item_id', $item->getKey())->where('unit_id', $unitId)->first()
            ?? throw new SalesException("Barang {$item->kode} tidak punya konversi untuk satuan #{$unitId}.", 'unit_conversion_missing');

        return Money::round(Money::mul($qty, (string) $conv->rasio_ke_dasar));
    }

    // ------------------------------------------------------------------------------------------- internal

    /** @return array{0: array, 1: list<array>} */
    private function prepare(array $data, User $user): array
    {
        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);
        $cid = $this->current->id();

        $cust = Customer::query()->where('contact_id', (int) ($data['contact_id'] ?? 0))->first()
            ?? throw new SalesException('Pelanggan tidak ditemukan.', 'customer_not_found');
        if (! DB::table('contacts')->where('company_id', $cid)->where('id', $cust->contact_id)->where('is_aktif', true)->exists()) {
            throw new SalesException('Pelanggan nonaktif tidak bisa dipakai transaksi.', 'customer_inactive');
        }

        $termId = $data['payment_term_id'] ?? $cust->payment_term_id;
        $jatuhTempo = $tanggal;
        if ($termId !== null) {
            $term = PaymentTerm::query()->where('is_aktif', true)->find($termId) ?? throw SalesException::notFound('Syarat pembayaran');
            $jatuhTempo = Carbon::parse($tanggal)->addDays((int) $term->jumlah_hari)->toDateString();
        }

        $inclusive = (bool) ($data['harga_termasuk_pajak'] ?? false);
        $diskonHeader = Money::round((string) ($data['diskon'] ?? '0'));
        $ongkir = Money::round((string) ($data['biaya_kirim'] ?? '0'));
        $lines = $this->prepareLines($data['lines'] ?? [], $user);

        $calc = $this->taxes->calculate([
            'tanggal' => $tanggal, 'harga_termasuk_pajak' => $inclusive, 'diskon_header' => $diskonHeader, 'ongkir' => $ongkir,
            'lines' => array_map(fn ($l) => ['qty' => $l['qty'], 'harga' => $l['harga'], 'diskon_persen' => $l['diskon_persen'], 'tax_code_id' => $l['tax_code_id']], $lines),
        ]);
        foreach ($lines as $i => &$l) {
            $l['jumlah'] = $calc['lines'][$i]['netto'];
        }
        unset($l);

        if (Money::cmp($calc['total'], '0') <= 0) {
            throw new SalesException('Total faktur harus lebih dari 0.', 'total_zero');
        }

        return [[
            'tanggal' => $tanggal, 'contact_id' => (int) $cust->contact_id, 'kode_mata_uang' => $this->current->company()->mata_uang_dasar, 'kurs' => '1',
            'subtotal' => $calc['subtotal'], 'diskon' => $calc['diskon_header'], 'dpp' => $calc['dpp'], 'total_pajak' => $calc['total_pajak'],
            'total' => $calc['total'], 'jatuh_tempo' => $jatuhTempo, 'payment_term_id' => $termId,
            'biaya_kirim' => $ongkir, 'harga_termasuk_pajak' => $inclusive, 'total_dibayar' => '0', 'sisa_tagihan' => '0',
            'status_pembayaran' => 'belum', 'memo' => $data['memo'] ?? null,
        ], $lines];
    }

    private function prepareLines(array $raw, User $user): array
    {
        if ($raw === []) {
            throw new SalesException('Faktur harus punya minimal satu baris.', 'no_lines');
        }

        $canPrice = $this->perm->can($user, 'khusus', 'ubah_harga_diskon');
        $limit = $this->perm->limit($user, 'khusus', 'ubah_harga_diskon'); // persen; null = tanpa batas
        $defaultWh = DB::table('warehouses')->where('company_id', $this->current->id())->where('is_default', true)->where('is_aktif', true)->value('id');

        $out = [];
        foreach (array_values($raw) as $i => $l) {
            $n = $i + 1;
            $item = null;
            if (! empty($l['item_id'])) {
                $item = Item::query()->find((int) $l['item_id']) ?? throw new SalesException("Baris {$n}: barang tidak ditemukan.", 'item_not_found');
                if (! $item->is_aktif) {
                    throw new SalesException("Baris {$n}: barang {$item->kode} nonaktif.", 'item_inactive');
                }
            } elseif (empty($l['account_id'])) {
                throw new SalesException("Baris {$n}: isi barang/jasa atau akun pendapatan.", 'line_target');
            }

            $accountId = null;
            if ($item === null) {
                $a = Account::query()->find((int) $l['account_id']) ?? throw new SalesException("Baris {$n}: akun tidak ditemukan.", 'account_not_found');
                if ($a->tipe !== 'revenue' || ! $a->isPostable()) {
                    throw new SalesException("Baris {$n}: akun {$a->kode} bukan akun pendapatan yang bisa dipakai.", 'account_invalid');
                }
                $accountId = (int) $a->getKey();
            }

            $qty = Money::round((string) ($l['qty'] ?? '0'));
            if (Money::cmp($qty, '0') <= 0) {
                throw new SalesException("Baris {$n}: qty harus lebih dari 0.", 'line_qty');
            }

            $harga = isset($l['harga']) && $l['harga'] !== null && $l['harga'] !== '' ? Money::round((string) $l['harga']) : ($item ? Money::round((string) $item->harga_jual) : null);
            if ($harga === null) {
                throw new SalesException("Baris {$n}: harga wajib diisi.", 'line_price');
            }
            $diskon = Money::round((string) ($l['diskon_persen'] ?? '0'));

            // hak ubah harga/diskon (Tahap 2 bagian 4)
            $hargaMaster = $item ? Money::round((string) $item->harga_jual) : null;
            if ($hargaMaster !== null && Money::cmp($harga, $hargaMaster) !== 0 && ! $canPrice) {
                throw new SalesException("Baris {$n}: Anda tidak berhak mengubah harga dari harga jual master.", 'price_forbidden');
            }
            if (Money::cmp($diskon, '0') > 0) {
                if (! $canPrice) {
                    throw new SalesException("Baris {$n}: Anda tidak berhak memberi diskon.", 'discount_forbidden');
                }
                if ($limit !== null && Money::cmp($diskon, (string) $limit) > 0) {
                    throw new SalesException("Baris {$n}: diskon {$diskon}% melebihi batas peran Anda ({$limit}%).", 'discount_limit');
                }
            }

            $warehouseId = null;
            $unitId = isset($l['unit_id']) && $l['unit_id'] !== null ? (int) $l['unit_id'] : null;
            if ($item && $item->isBarang()) {
                $warehouseId = (int) ($l['warehouse_id'] ?? $defaultWh ?? 0);
                $wh = DB::table('warehouses')->where('company_id', $this->current->id())->where('id', $warehouseId)->first();
                if (! $wh || ! $wh->is_aktif) {
                    throw new SalesException("Baris {$n}: gudang tidak ditemukan atau nonaktif.", 'warehouse_invalid');
                }
                self::toBaseQty($item, $unitId, $qty); // validasi konversi lebih awal
            }

            $taxId = array_key_exists('tax_code_id', $l) ? $l['tax_code_id'] : $item?->tax_code_jual_id;
            if ($taxId !== null) {
                $tax = TaxCode::query()->find((int) $taxId) ?? throw new SalesException("Baris {$n}: kode pajak tidak ditemukan.", 'tax_not_found');
                if (! in_array($tax->jenis, ['ppn', 'ppnbm'], true)) {
                    throw new SalesException("Baris {$n}: kode pajak {$tax->kode} ({$tax->jenis}) belum didukung di faktur penjualan.", 'tax_kind_unsupported');
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

    private function writeLines(SalesInvoice $doc, array $lines): void
    {
        foreach ($lines as $l) {
            SalesInvoiceLine::query()->create($l + ['company_id' => $doc->company_id, 'sales_invoice_id' => $doc->getKey()]);
        }
    }

    private function snapshot(SalesInvoice $doc, ?array $lines = null): array
    {
        return [
            'tanggal' => $doc->tanggal?->toDateString(), 'contact_id' => $doc->contact_id, 'total' => (string) $doc->total,
            'lines' => $lines ?? $doc->lines->map(fn ($l) => $l->only(['line_no', 'item_id', 'account_id', 'qty', 'harga', 'diskon_persen', 'tax_code_id']))->all(),
        ];
    }
}
