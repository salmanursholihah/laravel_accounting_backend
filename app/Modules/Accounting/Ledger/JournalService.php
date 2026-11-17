<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Accounting\Numbering\NumberingService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\CompanySetting;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mesin jurnal. SATU-SATUNYA tempat yang menulis ke journals dan journal_lines (Tahap 8 bagian 3).
 *
 * post()    : 8 validasi, nomor tanpa celah (ALG-01), simpan jurnal + baris, perbarui account_balances, audit.
 *             Semua di satu transaksi; bila dipanggil dari orkestrator posting (ALG-02) ia ikut transaksi
 *             pemanggil, sehingga satu gagal = semua batal.
 * reverse() : jurnal pembalik (ALG-07 pada tingkat jurnal). Jurnal asli tidak pernah diubah.
 */
class JournalService
{
    public function __construct(
        private CurrentCompany $current,
        private PeriodService $periods,
        private NumberingService $numbering,
        private JournalValidator $validator,
        private AccountBalanceService $balances,
        private AuditLogger $audit,
    ) {
    }

    public function post(JournalDraft $d): Journal
    {
        $userId = $d->userId ?? Auth::id() ?? throw JournalException::noUser();

        return DB::transaction(function () use ($d, $userId) {
            $this->validator->assertSource($d);
            $norm = JournalValidator::normalizeLines($d->lines);          // aturan 1, 2
            $this->validator->assertAccounts($norm['lines']);             // aturan 3
            $this->validator->assertContacts($norm['lines']);
            $period = $this->periods->assertPostable($d->tanggal);        // aturan 4, 5

            $nomor = $this->numbering->next('journal', $d->tanggal);      // aturan 8
            $companyId = $this->current->id();
            $tanggal = CarbonImmutable::parse($d->tanggal)->toDateString();

            $journal = Journal::create([
                'company_id' => $companyId,
                'nomor' => $nomor,
                'tanggal' => $tanggal,
                'period_id' => $period->getKey(),
                'tipe' => $d->tipe,
                'source_type' => $d->sourceType,
                'source_id' => $d->sourceId,
                'reversed_of_id' => $d->reversedOfId,
                'is_opening' => $d->tipe === 'opening',
                'is_closing' => $d->tipe === 'closing',
                'memo' => $d->memo !== null ? mb_substr($d->memo, 0, 500) : null,
                'total_debit' => $norm['total_debit'],
                'total_kredit' => $norm['total_kredit'],
                'branch_id' => $d->branchId,
                'created_by' => $userId,
            ]); // created_at diisi otomatis oleh Eloquent

            JournalLine::insert(array_map(fn (array $l) => [
                'company_id' => $companyId,
                'journal_id' => $journal->getKey(),
                'line_no' => $l['line_no'],
                'account_id' => $l['account_id'],
                'debit' => $l['debit'],
                'kredit' => $l['kredit'],
                'memo' => $l['memo'],
                'contact_id' => $l['contact_id'],
                'tanggal' => $tanggal,
            ], $norm['lines']));

            $this->balances->apply($period, $norm['lines'], $d->tipe === 'closing');

            $this->audit->log('journal', (int) $journal->getKey(), 'post', null, [
                'nomor' => $nomor, 'tanggal' => $tanggal, 'tipe' => $d->tipe,
                'source_type' => $d->sourceType, 'source_id' => $d->sourceId,
                'total' => $norm['total_debit'],
            ], null, $companyId, $userId);

            return $journal;
        });
    }

    /**
     * Membalik satu jurnal. Tanggal pembalik: tanggal asli bila periodenya Open; bila terkunci mengikuti Preferensi
     * (today = hari ini, next_open_period = awal periode terbuka berikutnya). Jurnal penutup tidak bisa dibalik di sini
     * (hanya lewat buka kembali tahun buku oleh Owner), jurnal pembalik tidak bisa dibalik lagi.
     */
    public function reverse(Journal|int $journal, string $reason, ?int $userId = null): Journal
    {
        $userId ??= Auth::id() ?? throw JournalException::noUser();
        $reason = trim($reason);
        if ($reason === '') {
            throw JournalException::reasonRequired();
        }

        return DB::transaction(function () use ($journal, $reason, $userId) {
            $id = $journal instanceof Journal ? $journal->getKey() : $journal;
            // kunci baris asli agar dua void bersamaan terserialisasi
            $orig = Journal::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($orig->tipe === 'reversal') {
                throw JournalException::notReversible('Jurnal pembalik tidak bisa dibalik lagi. Buat jurnal baru bila perlu.');
            }
            if ($orig->tipe === 'closing') {
                throw JournalException::notReversible('Jurnal penutup hanya bisa dibatalkan lewat pembukaan kembali tahun buku oleh Owner.');
            }
            if (Journal::query()->where('reversed_of_id', $orig->getKey())->exists()) {
                throw JournalException::alreadyReversed($orig->nomor);
            }

            $tanggalBalik = $this->reversalDate($orig);

            $lines = array_map(fn ($l) => [
                'account_id' => (int) $l->account_id,
                'debit' => (string) $l->kredit,   // sisi dibalik
                'kredit' => (string) $l->debit,
                'memo' => $l->memo,
                'contact_id' => $l->contact_id,
            ], JournalLine::query()->where('journal_id', $orig->getKey())->orderBy('line_no')->get()->all());

            try {
                $rev = $this->post(new JournalDraft(
                    tanggal: $tanggalBalik,
                    tipe: 'reversal',
                    lines: $lines,
                    memo: mb_substr("Pembalik {$orig->nomor}: {$reason}", 0, 500),
                    // jurnal dokumen: tetap menunjuk dokumen sumber; jurnal manual: menunjuk jurnal asalnya
                    sourceType: $orig->source_id ? $orig->source_type : 'journal',
                    sourceId: (int) ($orig->source_id ?? $orig->getKey()),
                    reversedOfId: (int) $orig->getKey(),
                    branchId: $orig->branch_id ? (int) $orig->branch_id : null,
                    userId: $userId,
                ));
            } catch (QueryException $e) {
                // uq_journals_reversed: pengaman terakhir bila dua proses lolos bersamaan
                if (($e->errorInfo[0] ?? null) === '23000') {
                    throw JournalException::alreadyReversed($orig->nomor);
                }
                throw $e;
            }

            $this->audit->log('journal', (int) $orig->getKey(), 'void', ['nomor' => $orig->nomor], [
                'jurnal_pembalik_id' => (int) $rev->getKey(), 'jurnal_pembalik' => $rev->nomor,
                'tanggal_pembalik' => $tanggalBalik,
            ], $reason, (int) $this->current->id(), $userId);

            return $rev;
        });
    }

    /** Jurnal aktif (bukan pembalik, belum dibalik) milik sebuah dokumen sumber. Dipakai void dokumen (ALG-07). */
    public function activeJournalsOf(string $sourceType, int $sourceId): Collection
    {
        return Journal::query()
            ->where('journals.source_type', $sourceType)
            ->where('journals.source_id', $sourceId)
            ->active()
            ->orderBy('journals.id')
            ->get();
    }

    private function reversalDate(Journal $orig): string
    {
        $asli = CarbonImmutable::parse($orig->tanggal)->toDateString();

        if ($this->periods->isPostable($asli)) {
            return $asli;
        }

        $mode = CompanySetting::current()->tanggal_void_periode_terkunci;

        if ($mode === 'next_open_period') {
            $next = AccountingPeriod::query()
                ->where('status', 'open')
                ->where('tanggal_mulai', '>', $asli)
                ->orderBy('tanggal_mulai')
                ->first();
            if (! $next) {
                throw JournalException::notReversible('Tidak ada periode terbuka setelah periode jurnal ini. Buka periode atau ubah Preferensi.');
            }
            $date = $next->tanggal_mulai->toDateString();
        } else {
            $date = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        }

        $this->periods->assertPostable($date); // periode tujuan wajib Open

        return $date;
    }
}
