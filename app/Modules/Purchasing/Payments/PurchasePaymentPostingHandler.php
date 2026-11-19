<?php

namespace App\Modules\Purchasing\Payments;

use App\Models\User;
use App\Modules\Accounting\Allocation\PaymentAllocator;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Purchasing\Models\PurchasePaymentAllocation;
use App\Modules\Purchasing\Models\Supplier;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posting Pembayaran Pembelian. Jurnal: Dr Utang Usaha (nominal, subledger pemasok) . Cr Kas/Bank.
 * Jurnal hanya bergantung pada nominal, jadi alokasi ke faktur dikerjakan di markPosted (di dalam transaksi
 * posting, setelah dokumen dikunci): faktur dikunci berurutan id, alokasi dihitung (ALG-06), sisa tagihan dan
 * status_pembayaran diperbarui. Alokasi yang tidak bisa dipenuhi melempar PostingException sehingga SELURUH
 * posting (nomor, jurnal, saldo ringkas) batal dan draft tetap Draft.
 */
class PurchasePaymentPostingHandler extends AbstractPostingHandler
{
    public const DOC = 'purchase_payment';

    public function journalRules(): array
    {
        return [
            new JournalRule(self::DOC, 'D', 'utang_usaha', '0', each: 'debit'),
            new JournalRule(self::DOC, 'K', 'utang_usaha', '0', each: 'kredit'),
        ];
    }

    public function docType(): string
    {
        return self::DOC;
    }

    public function feature(): string
    {
        return 'pembayaran_pembelian';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = PurchasePayment::query();
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id)->load('allocations');
    }

    public function tanggal(Model $doc): string
    {
        return $doc->tanggal->toDateString();
    }

    public function status(Model $doc): string
    {
        return (string) $doc->status;
    }

    public function validate(Model $doc, User $user): void
    {
        if (! Supplier::query()->where('contact_id', $doc->contact_id)->exists()) {
            throw PostingException::rule('Pemasok pembayaran tidak ditemukan.');
        }
        if (Money::cmp((string) $doc->nominal, '0') <= 0) {
            throw PostingException::rule('Nominal pembayaran harus lebih dari 0.');
        }
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $bank = DB::table('bank_accounts')->where('company_id', $doc->company_id)->where('id', $doc->rekening_pembayaran_id)->first();
        if (! $bank) {
            throw PostingException::rule('Rekening kas/bank pembayaran tidak ditemukan.');
        }

        // akun utang pemasok sama dengan yang dipakai Faktur Pembelian (override pemasok, selain itu akun sistem)
        $sup = Supplier::query()->where('contact_id', $doc->contact_id)->first();
        $utang = ['amount' => Money::round((string) $doc->nominal), 'contact_id' => (int) $doc->contact_id, 'memo' => $doc->memo]
            + ($sup?->akun_utang_override_id ? ['account_id' => (int) $sup->akun_utang_override_id] : ['account_key' => 'utang_usaha']);

        return [
            'lists' => [
                'debit' => [$utang],
                'kredit' => [['amount' => Money::round((string) $doc->nominal), 'account_id' => (int) $bank->account_id, 'memo' => $doc->memo]],
            ],
            'contact_id' => (int) $doc->contact_id,
        ];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        $nominal = Money::round((string) $doc->nominal);

        // 1. kunci faktur terbuka berurutan id, lalu hitung alokasi (ALG-06)
        $invoices = PurchasePaymentService::openRows((int) $doc->contact_id, $doc->tanggal->toDateString(), true);
        $rows = PurchasePaymentService::allocatorRows($invoices);

        try {
            if ($doc->allocations->isNotEmpty()) { // manual: alokasi dari draft dicek ulang terhadap sisa yang sudah terkunci
                $final = PaymentAllocator::manual(
                    $nominal,
                    $doc->allocations->map(fn ($a) => ['invoice_id' => (int) $a->purchase_invoice_id, 'nominal' => (string) $a->nominal_dialokasikan])->all(),
                    collect($rows)->keyBy('id')->all(),
                );
            } else {
                $auto = PaymentAllocator::auto($nominal, $rows);
                if (Money::cmp($auto['sisa'], '0') > 0) {
                    throw new DomainException("Nominal melebihi total sisa tagihan pemasok (kelebihan {$auto['sisa']}). Kurangi nominal; kelebihan bayar sebagai uang muka belum didukung.");
                }
                $final = $auto['allocations'];
            }
        } catch (DomainException $e) {
            throw PostingException::rule($e->getMessage());
        }

        // 2. perbarui faktur dan simpan alokasi final
        $byId = $invoices->keyBy('id');
        PurchasePaymentAllocation::query()->where('purchase_payment_id', $doc->getKey())->delete();
        foreach ($final as $a) {
            /** @var PurchaseInvoice $inv */
            $inv = $byId[$a['invoice_id']];
            $dibayar = Money::round(Money::add((string) $inv->total_dibayar, $a['nominal']));
            $sisa = Money::round(Money::sub((string) $inv->sisa_tagihan, $a['nominal']));
            $inv->forceFill([
                'total_dibayar' => $dibayar, 'sisa_tagihan' => $sisa,
                'status_pembayaran' => Money::isZero($sisa) ? 'lunas' : 'sebagian', 'updated_by' => $user->getKey(),
            ])->save();

            PurchasePaymentAllocation::create([
                'purchase_payment_id' => $doc->getKey(), 'purchase_invoice_id' => $inv->getKey(),
                'nominal_dialokasikan' => $a['nominal'], 'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
        }

        $doc->forceFill([
            'nomor' => $result->nomor, 'status' => 'posted', 'total' => $nominal, 'journal_id' => $result->journalIds[0] ?? null,
            'diposting_oleh' => $user->getKey(), 'diposting_pada' => now(), 'updated_by' => $user->getKey(),
        ])->save();
        $doc->load('allocations');
    }

    /** Pembayaran yang sudah dicocokkan di rekonsiliasi bank tidak boleh di-void sebelum rekonsiliasi dibuka. */
    public function isReconciled(Model $doc): bool
    {
        return DB::table('reconciliation_items as ri')
            ->join('journal_lines as jl', fn ($j) => $j->on('jl.id', '=', 'ri.journal_line_id')->on('jl.company_id', '=', 'ri.company_id'))
            ->join('journals as j', fn ($j) => $j->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id'))
            ->where('ri.company_id', $doc->company_id)->where('j.source_type', self::DOC)->where('j.source_id', $doc->getKey())
            ->exists();
    }

    /** Void: kembalikan sisa tagihan dan status_pembayaran faktur. Baris alokasi dibiarkan sebagai riwayat. */
    public function restoreSources(Model $doc): void
    {
        $allocs = PurchasePaymentAllocation::query()->where('purchase_payment_id', $doc->getKey())->orderBy('purchase_invoice_id')->get();
        $invoices = PurchaseInvoice::query()->whereIn('id', $allocs->pluck('purchase_invoice_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($allocs as $a) {
            $inv = $invoices[$a->purchase_invoice_id] ?? null;
            if (! $inv) {
                continue;
            }
            $dibayar = Money::round(Money::sub((string) $inv->total_dibayar, (string) $a->nominal_dialokasikan));
            $sisa = Money::round(Money::add((string) $inv->sisa_tagihan, (string) $a->nominal_dialokasikan));
            $inv->forceFill([
                'total_dibayar' => $dibayar, 'sisa_tagihan' => $sisa,
                'status_pembayaran' => Money::isZero($dibayar) ? 'belum' : 'sebagian',
            ])->save();
            $invoices[$a->purchase_invoice_id] = $inv;
        }
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill([
            'status' => 'void', 'void_oleh' => $user->getKey(), 'void_pada' => now(), 'alasan_void' => $reason, 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return ['contact_id' => (int) $doc->contact_id, 'nominal' => (string) $doc->nominal, 'rekening_id' => $doc->rekening_pembayaran_id];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo;
    }
}
