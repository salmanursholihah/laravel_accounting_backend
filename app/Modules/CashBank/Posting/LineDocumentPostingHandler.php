<?php

namespace App\Modules\CashBank\Posting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\CashBank\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Handler posting untuk dokumen Kas & Bank bergaya header + baris: Penerimaan, Pembayaran, Pencatatan Beban.
 * Satu kelas, dikonfigurasi lewat konstruktor, karena ketiganya hanya berbeda arah uang dan kolom rekening.
 *
 * Arah 'in'  (uang masuk) : sisi bank = DEBIT;  sisi lawan (baris, pajak keluaran) = KREDIT.
 * Arah 'out' (uang keluar): sisi bank = KREDIT; sisi lawan (baris, pajak masukan) = DEBIT.
 * Beban dengan jenis_pembayaran 'utang': sisi bank diganti Utang Usaha dengan kontak (subledger utang).
 * Pembulatan pajak (ALG-03): positif ke sisi lawan, negatif ke sisi bank, sehingga jurnal selalu seimbang.
 * Mesin jurnal (ALG-04) menerima semuanya sebagai daftar ('lists'): akun tiap baris berasal dari dokumen.
 */
class LineDocumentPostingHandler extends AbstractPostingHandler
{
    /**
     * @param class-string<Model> $modelClass
     * @param 'in'|'out' $side
     */
    public function __construct(
        private string $docType,
        private string $feature,
        private string $modelClass,
        private string $side,
        private string $bankColumn,
    ) {
    }

    /** Aturan jurnal ALG-04. Kunci akun pada aturan hanya cadangan; tiap item daftar membawa akunnya sendiri. */
    public function journalRules(): array
    {
        return [
            new JournalRule($this->docType, 'D', 'pendapatan_lain', '0', each: 'debit'),
            new JournalRule($this->docType, 'K', 'pendapatan_lain', '0', each: 'kredit'),
        ];
    }

    public function docType(): string
    {
        return $this->docType;
    }

    public function feature(): string
    {
        return $this->feature;
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = ($this->modelClass)::query();
        if ($lock) {
            $q->lockForUpdate();
        }
        $doc = $q->findOrFail($id);
        $doc->load('lines');

        return $doc;
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
        if ($doc->lines->isEmpty()) {
            throw PostingException::rule('Dokumen tidak punya baris.');
        }
        if ($this->isDebt($doc) && $doc->contact_id === null) {
            throw PostingException::rule('Beban utang wajib punya kontak (pemasok).');
        }
        if ($this->side === 'out' && (bool) ($doc->dapat_dikreditkan ?? true) === true && $this->hasTax($doc) && empty($doc->no_faktur_pajak)) {
            throw PostingException::rule('PPN Masukan hanya dikreditkan dengan nomor faktur pajak.');
        }
    }

    public function taxInput(Model $doc): ?array
    {
        if (! $this->hasTax($doc)) {
            return null; // tanpa pajak: total = jumlah nominal apa adanya, tanpa pembulatan rupiah
        }

        return [
            'tanggal' => $this->tanggal($doc),
            'lines' => $doc->lines->values()->map(fn ($l) => [
                'qty' => '1', 'harga' => (string) $l->nominal, 'tax_code_id' => $l->tax_code_id,
            ])->all(),
        ];
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $in = $this->side === 'in';
        $creditable = $in ? false : (bool) ($doc->dapat_dikreditkan ?? true);
        $lawan = []; // sisi lawan bank
        $bank = [];  // sisi bank

        // kontak hanya dibawa ke baris akun piutang/utang (subledger), bukan ke akun pendapatan/beban
        $subledger = Account::query()->whereIn('id', $doc->lines->pluck('account_id')->all())
            ->whereIn('sub_tipe', ['receivable', 'payable'])->pluck('id')->flip();

        foreach ($doc->lines->values() as $i => $l) {
            $amount = $taxes ? $taxes['lines'][$i]['dasar'] : Money::round((string) $l->nominal);

            // PPN Masukan yang tidak dapat dikreditkan masuk biaya baris itu sendiri
            if ($taxes && ! $in && ! $creditable) {
                foreach ($taxes['lines'][$i]['pajak'] as $p) {
                    $amount = Money::add($amount, $p['pajak']);
                }
            }

            $lawan[] = [
                'amount' => $amount, 'account_id' => (int) $l->account_id,
                'memo' => $l->deskripsi ?: $doc->memo,
                'contact_id' => $subledger->has((int) $l->account_id) ? $doc->contact_id : null,
            ];
        }

        $total = $taxes ? $taxes['total'] : Money::round(Money::sum($doc->lines->pluck('nominal')->all()));

        if ($taxes && ($in || $creditable)) {
            foreach ($taxes['pajak'] as $kode => $p) {
                [$accountId, $key] = $this->taxAccount($p);
                $lawan[] = ['amount' => $p['pajak'], 'account_id' => $accountId, 'account_key' => $key, 'memo' => $kode, 'contact_id' => null];
            }
        }

        $bankItem = ['amount' => $total, 'memo' => $doc->memo];
        if ($this->isDebt($doc)) {
            $bankItem += ['account_key' => 'utang_usaha', 'contact_id' => $doc->contact_id];
        } else {
            $bankItem += ['account_id' => $this->bankLedgerAccount($doc), 'contact_id' => null];
        }
        $bank[] = $bankItem;

        $bulat = $taxes['pembulatan'] ?? '0';
        if (Money::cmp($bulat, '0') > 0) {
            $lawan[] = ['amount' => $bulat, 'account_key' => 'pembulatan', 'memo' => 'Pembulatan', 'contact_id' => null];
        } elseif (Money::cmp($bulat, '0') < 0) {
            $bank[] = ['amount' => Money::sub('0', $bulat), 'account_key' => 'pembulatan', 'memo' => 'Pembulatan', 'contact_id' => null];
        }

        return [
            'lists' => $in ? ['debit' => $bank, 'kredit' => $lawan] : ['debit' => $lawan, 'kredit' => $bank],
            'contact_id' => $doc->contact_id,
        ];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        $total = $result->taxes ? $result->taxes['total'] : Money::round(Money::sum($doc->lines->pluck('nominal')->all()));

        $doc->forceFill([
            'nomor' => $result->nomor,
            'status' => 'posted',
            'total' => $total,
            'journal_id' => $result->journalIds[0] ?? null,
            'diposting_oleh' => $user->getKey(),
            'diposting_pada' => now(),
            'updated_by' => $user->getKey(),
        ])->save();

        if ($result->taxes) {
            $this->writeDocumentTaxes($doc, $result, $user);
        }
    }

    public function isReconciled(Model $doc): bool
    {
        return self::journalsReconciled($this->docType, (int) $doc->getKey(), (int) $doc->company_id);
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill([
            'status' => 'void', 'void_oleh' => $user->getKey(), 'void_pada' => now(),
            'alasan_void' => $reason, 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return [
            'contact_id' => $doc->contact_id,
            'rekening_id' => $doc->{$this->bankColumn},
            'total' => (string) $doc->total,
        ];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo;
    }

    /** Dipakai kedua handler Kas & Bank: baris jurnal bank dokumen ini sudah dicocokkan di rekonsiliasi? */
    public static function journalsReconciled(string $docType, int $docId, int $companyId): bool
    {
        return DB::table('reconciliation_items as ri')
            ->join('journal_lines as jl', fn ($j) => $j->on('jl.id', '=', 'ri.journal_line_id')->on('jl.company_id', '=', 'ri.company_id'))
            ->join('journals as j', fn ($j) => $j->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id'))
            ->where('ri.company_id', $companyId)
            ->where('j.source_type', $docType)
            ->where('j.source_id', $docId)
            ->exists();
    }

    private function isDebt(Model $doc): bool
    {
        return ($doc->jenis_pembayaran ?? 'kas_bank') === 'utang';
    }

    private function hasTax(Model $doc): bool
    {
        return $doc->lines->contains(fn ($l) => $l->tax_code_id !== null);
    }

    private function bankLedgerAccount(Model $doc): int
    {
        $bank = BankAccount::query()->find((int) $doc->{$this->bankColumn});
        if (! $bank) {
            throw PostingException::rule('Rekening kas/bank dokumen tidak ditemukan.');
        }

        return (int) $bank->account_id;
    }

    /** @return array{0: ?int, 1: ?string} [account_id, account_key] akun pajak: dari kode pajak, selain itu akun sistem */
    private function taxAccount(array $p): array
    {
        if ($this->side === 'in') {
            if ($p['akun_penjualan_id']) {
                return [(int) $p['akun_penjualan_id'], null];
            }

            return [null, $p['jenis'] === 'ppnbm' ? 'utang_ppnbm' : 'ppn_keluaran'];
        }

        if ($p['jenis'] !== 'ppn') {
            throw PostingException::rule("Pajak {$p['kode']} ({$p['jenis']}) belum didukung di dokumen pembayaran.");
        }

        return $p['akun_pembelian_id'] ? [(int) $p['akun_pembelian_id'], null] : [null, 'ppn_masukan'];
    }

    private function writeDocumentTaxes(Model $doc, PostingResult $result, User $user): void
    {
        $rows = [];
        foreach ($doc->lines->values() as $i => $l) {
            foreach ($result->taxes['lines'][$i]['pajak'] as $p) {
                $rows[] = [
                    'company_id' => $doc->company_id, 'doc_type' => $this->docType, 'doc_id' => $doc->getKey(),
                    'line_id' => $l->getKey(), 'tax_code_id' => $p['tax_code_id'],
                    'dpp' => $p['dpp'], 'tarif' => $p['tarif'], 'nominal' => $p['pajak'],
                    'created_by' => $user->getKey(), 'created_at' => now(), 'updated_by' => $user->getKey(), 'updated_at' => now(),
                ];
            }
        }
        if ($rows) {
            DB::table('document_taxes')->insert($rows);
        }
    }
}
