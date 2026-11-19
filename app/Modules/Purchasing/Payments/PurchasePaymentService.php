<?php

namespace App\Modules\Purchasing\Payments;

use App\Modules\Accounting\Approval\ApprovalService;
use App\Models\User;
use App\Modules\Accounting\Allocation\PaymentAllocator;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Purchasing\Models\PurchasePaymentAllocation;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\PurchasingException;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draft Pembayaran Pembelian (UC-44, P4). Mode alokasi ditentukan oleh isi draft:
 *  - 'allocations' diisi  = MANUAL: dicek terhadap faktur terbuka (disimpan sebagai baris alokasi draft);
 *  - 'allocations' kosong = OTOMATIS: dihitung saat Posting, jatuh tempo terlama dulu (ALG-06), karena sisa
 *    tagihan baru pasti setelah faktur dikunci di dalam transaksi posting.
 * Keputusan MVP: nominal harus habis dialokasikan. Kelebihan bayar (jadi Uang Muka Pembelian) menunggu modul
 * Uang Muka (Fase 2); diskon pelunasan cepat juga belum, karena analisis tidak menetapkan akun diskon pembelian.
 */
class PurchasePaymentService
{
    public function __construct(private CurrentCompany $current, private AuditLogger $audit, private PeriodService $periods)
    {
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'contact_id' => ['required', 'integer'],
            'rekening_id' => ['required', 'integer'],
            'nominal' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.purchase_invoice_id' => ['required', 'integer'],
            'allocations.*.nominal' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }

    public function find(int $id): PurchasePayment
    {
        return PurchasePayment::query()->findOrFail($id)->load('allocations');
    }

    public function create(array $data, User $user): PurchasePayment
    {
        [$header, $allocs] = $this->prepare($data);

        return DB::transaction(function () use ($header, $allocs, $user) {
            $doc = PurchasePayment::create($header + ['status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            $this->writeAllocations($doc, $allocs, $user);
            $this->audit->log('purchase_payment', (int) $doc->getKey(), 'create', null, $this->snapshot($doc, $allocs));

            return $doc->load('allocations');
        });
    }

    public function update(int $id, array $data, User $user): PurchasePayment
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = PurchasePayment::query()->lockForUpdate()->findOrFail($id);
            $doc = app(ApprovalService::class)->releaseIfPending($doc, 'purchase_payment'); // 6A.4: ubah dokumen pending = batalkan pengajuan
            if ($doc->status !== 'draft') {
                throw PurchasingException::notDraft((string) $doc->status);
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw PurchasingException::conflict();
            }

            [$header, $allocs] = $this->prepare($data);
            $before = $this->snapshot($doc->load('allocations'));
            $doc->forceFill($header + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            PurchasePaymentAllocation::query()->where('purchase_payment_id', $doc->getKey())->delete();
            $this->writeAllocations($doc, $allocs, $user);
            $this->audit->log('purchase_payment', (int) $doc->getKey(), 'update', $before, $this->snapshot($doc, $allocs));

            return $doc->load('allocations');
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $doc = PurchasePayment::query()->lockForUpdate()->findOrFail($id);
            if ($doc->status !== 'draft') {
                throw PurchasingException::notDraft((string) $doc->status);
            }
            $this->audit->log('purchase_payment', (int) $doc->getKey(), 'delete', $this->snapshot($doc->load('allocations')), null);
            PurchasePaymentAllocation::query()->where('purchase_payment_id', $doc->getKey())->delete();
            $doc->delete();
        });
    }

    /** Faktur pemasok yang masih terbuka, urut jatuh tempo (layar P4), plus saran alokasi otomatis bila nominal diberikan. */
    public function openInvoices(int $contactId, ?string $nominal = null, ?string $tanggal = null): array
    {
        $rows = self::openRows($contactId, $tanggal)->map(fn (PurchaseInvoice $i) => [
            'id' => (int) $i->id, 'nomor' => $i->nomor, 'no_faktur_pemasok' => $i->no_faktur_pemasok,
            'tanggal' => $i->tanggal->toDateString(), 'jatuh_tempo' => $i->jatuh_tempo->toDateString(),
            'total' => (string) $i->total, 'sisa' => (string) $i->sisa_tagihan,
        ])->sortBy([['jatuh_tempo', 'asc'], ['tanggal', 'asc'], ['id', 'asc']])->values();

        $out = ['faktur' => $rows->all(), 'total_sisa' => Money::round(Money::sum($rows->pluck('sisa')->all()))];
        if ($nominal !== null) {
            $a = PaymentAllocator::auto($nominal, $rows->all());
            $out['saran_alokasi'] = $a['allocations'];
            $out['belum_teralokasi'] = $a['sisa'];
        }

        return $out;
    }

    /** Faktur terbuka pemasok; urut id agar urutan penguncian selalu sama (anti-deadlock, ALG-02). */
    public static function openRows(int $contactId, ?string $maxTanggal = null, bool $lock = false): Collection
    {
        $q = PurchaseInvoice::query()->where('contact_id', $contactId)->where('status', 'posted')->where('sisa_tagihan', '>', 0);
        if ($maxTanggal !== null) {
            $q->where('tanggal', '<=', $maxTanggal);
        }
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->orderBy('id')->get();
    }

    /** Bentuk faktur terbuka untuk PaymentAllocator. */
    public static function allocatorRows(Collection $invoices): array
    {
        return $invoices->map(fn (PurchaseInvoice $i) => [
            'id' => (int) $i->id, 'sisa' => (string) $i->sisa_tagihan,
            'jatuh_tempo' => $i->jatuh_tempo->toDateString(), 'tanggal' => $i->tanggal->toDateString(),
        ])->all();
    }

    private function prepare(array $data): array
    {
        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);
        $cid = $this->current->id();

        $sup = Supplier::query()->where('contact_id', (int) $data['contact_id'])->first()
            ?? throw new PurchasingException('Pemasok tidak ditemukan.', 'supplier_not_found');
        if (! DB::table('contacts')->where('company_id', $cid)->where('id', $sup->contact_id)->where('is_aktif', true)->exists()) {
            throw new PurchasingException('Pemasok nonaktif tidak bisa dipakai transaksi.', 'supplier_inactive');
        }

        // rekening dibaca langsung dari tabelnya: modul bisnis tidak saling memanggil (Tahap 8)
        $bank = DB::table('bank_accounts')->where('company_id', $cid)->where('id', (int) $data['rekening_id'])->first();
        if (! $bank) {
            throw new PurchasingException('Rekening kas/bank tidak ditemukan.', 'bank_account_not_found');
        }
        if (! $bank->is_aktif) {
            throw new PurchasingException('Rekening kas/bank nonaktif tidak bisa dipakai transaksi.', 'bank_account_inactive');
        }

        $nominal = Money::round((string) $data['nominal']);
        if (Money::cmp($nominal, '0') <= 0) {
            throw new PurchasingException('Nominal pembayaran harus lebih dari 0.', 'amount_invalid');
        }

        $allocs = [];
        if (! empty($data['allocations'])) {
            $open = self::openRows((int) $sup->contact_id, $tanggal)->keyBy('id')->map(fn ($i) => ['sisa' => (string) $i->sisa_tagihan])->all();
            try {
                $allocs = PaymentAllocator::manual($nominal, array_map(fn ($a) => [
                    'invoice_id' => (int) $a['purchase_invoice_id'], 'nominal' => $a['nominal'],
                ], $data['allocations']), $open);
            } catch (DomainException $e) {
                throw new PurchasingException($e->getMessage(), 'allocation_invalid');
            }
        }

        return [[
            'tanggal' => $tanggal, 'contact_id' => (int) $sup->contact_id, 'rekening_pembayaran_id' => (int) $bank->id,
            'nominal' => $nominal, 'total' => $nominal, 'memo' => $data['memo'] ?? null,
            'kode_mata_uang' => $this->current->company()->mata_uang_dasar, 'kurs' => '1',
        ], $allocs];
    }

    private function writeAllocations(PurchasePayment $doc, array $allocs, User $user): void
    {
        foreach ($allocs as $a) {
            PurchasePaymentAllocation::create([
                'purchase_payment_id' => $doc->getKey(), 'purchase_invoice_id' => $a['invoice_id'],
                'nominal_dialokasikan' => $a['nominal'], 'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
        }
    }

    private function snapshot(PurchasePayment $d, ?array $allocs = null): array
    {
        return [
            'tanggal' => $d->tanggal?->toDateString(), 'contact_id' => $d->contact_id, 'rekening_id' => $d->rekening_pembayaran_id,
            'nominal' => (string) $d->nominal, 'memo' => $d->memo,
            'alokasi' => $allocs ?? $d->allocations->map(fn ($a) => ['invoice_id' => $a->purchase_invoice_id, 'nominal' => (string) $a->nominal_dialokasikan])->all(),
        ];
    }
}
