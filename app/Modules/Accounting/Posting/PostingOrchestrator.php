<?php

namespace App\Modules\Accounting\Posting;

use App\Models\User;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Numbering\NumberingService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * ALG-02 (posting) dan ALG-07 tingkat dokumen (void). Satu-satunya jalan dokumen akuntansi menjadi Posted/Void.
 *
 * post():
 *   hak akses -> status -> periode Open dan tanggal >= mulai pembukuan -> validasi dokumen
 *   BEGIN: kunci dokumen -> nomor (ALG-01) -> pajak (ALG-03) -> stok (ALG-05) -> jurnal (ALG-04, 8 validasi) ->
 *          perbarui dokumen/sumber/saldo terbuka -> audit -> outbox  COMMIT
 *   Satu langkah gagal = semua batal: tidak ada nomor, jurnal, mutasi stok, ataupun saldo ringkas yang tersisa,
 *   dokumen tetap draft. Efek samping non-akuntansi (notifikasi, hitung ulang HPP, komisi) hanya lewat outbox,
 *   sehingga kegagalannya tidak membatalkan posting.
 *
 * void():
 *   hak Void -> alasan -> status posted -> belum direkonsiliasi -> tidak ada turunan aktif ->
 *   BEGIN: jurnal pembalik semua jurnal aktif dokumen -> stok dibalik (nilai asli) -> sumber dipulihkan -> Void -> audit COMMIT
 */
class PostingOrchestrator
{
    private const DEADLOCK_RETRIES = 3;

    public function __construct(
        private PostingHandlerRegistry $handlers,
        private PermissionChecker $permissions,
        private PeriodService $periods,
        private NumberingService $numbering,
        private TaxCalculator $taxes,
        private StockMovementApplier $stock,
        private JournalRuleEngine $engine,
        private JournalService $journals,
        private AuditLogger $audit,
        private CurrentCompany $current,
    ) {
    }

    /**
     * @param bool $approved true hanya dari alur approval: dokumen berstatus pending_approval boleh diposting
     *                       (disetujui oleh $user, yang bukan pembuatnya; aturan itu dijaga modul approval).
     */
    public function post(string $docType, int $docId, User $user, bool $approved = false): PostingResult
    {
        $h = $this->handlers->get($docType);

        // pemeriksaan murah di luar transaksi (ALG-02); dicek ulang setelah dokumen dikunci
        $preview = $h->find($docId);
        if (! $this->permissions->can($user, $h->feature(), 'post', $preview)) {
            throw PostingException::forbidden($h->feature(), 'post');
        }
        $this->assertPostableStatus($h->status($preview), $approved);
        $this->periods->assertPostable($h->tanggal($preview));
        $h->validate($preview, $user);

        return DB::transaction(function () use ($h, $docType, $docId, $user, $approved) {
            $doc = $h->find($docId, true);
            $statusSebelum = $h->status($doc);
            $this->assertPostableStatus($statusSebelum, $approved); // klik ganda / posting paralel

            $tanggal = $h->tanggal($doc);
            $this->periods->assertPostable($tanggal);

            $nomor = $this->numbering->next($docType, $tanggal);

            $taxInput = $h->taxInput($doc);
            $taxes = $taxInput === null ? null : $this->taxes->calculate($taxInput);

            $stock = $this->stock->apply($docType, $docId, $tanggal, $h->stockMovements($doc));

            $drafts = $this->engine->build(
                $docType,
                $h->ruleContext($doc, $taxes, $stock),
                $tanggal,
                $docId,
                (int) $user->getKey(),
                $h->journalMemo($doc),
            );

            $journalIds = [];
            foreach ($drafts as $draft) {
                $journalIds[] = (int) $this->journals->post($draft)->getKey(); // 8 validasi + saldo ringkas
            }

            $result = new PostingResult($nomor, $taxes, $journalIds, $stock);
            $h->markPosted($doc, $result, $user);

            $this->audit->log($docType, $docId, 'post', ['status' => $statusSebelum], [
                'nomor' => $nomor, 'tanggal' => $tanggal, 'jurnal' => $journalIds,
            ], null, $this->current->id(), (int) $user->getKey());

            $this->outbox('DocumentPosted', $docType, $docId, $h->eventPayload($doc) + ['nomor' => $nomor, 'tanggal' => $tanggal]);

            return $result;
        }, self::DEADLOCK_RETRIES);
    }

    public function void(string $docType, int $docId, string $reason, User $user): void
    {
        $reason = trim($reason);
        $h = $this->handlers->get($docType);

        $preview = $h->find($docId);
        if (! $this->permissions->can($user, $h->feature(), 'void', $preview)) {
            throw PostingException::forbidden($h->feature(), 'void');
        }
        if ($reason === '') {
            throw PostingException::reasonRequired();
        }

        DB::transaction(function () use ($h, $docType, $docId, $reason, $user) {
            $doc = $h->find($docId, true);

            $status = $h->status($doc);
            if ($status !== 'posted') {
                throw PostingException::wrongStatus($status, 'posted');
            }
            if ($h->isReconciled($doc)) {
                throw PostingException::reconciled();
            }
            $blockers = $h->blockers($doc);
            if ($blockers !== []) {
                throw PostingException::hasActiveChildren($blockers);
            }

            // jurnal pembalik: tanggal asli bila periode Open, selain itu mengikuti Preferensi (JournalService::reverse)
            $reversalDate = null;
            foreach ($this->journals->activeJournalsOf($docType, $docId) as $journal) {
                $rev = $this->journals->reverse($journal, $reason, (int) $user->getKey());
                $reversalDate ??= $rev->tanggal->toDateString();
            }

            $this->stock->reverse($docType, $docId, $reversalDate ?? $h->tanggal($doc));
            $h->restoreSources($doc);
            $h->markVoid($doc, $reason, $user);

            $this->audit->log($docType, $docId, 'void', ['status' => 'posted'], ['status' => 'void'], $reason, $this->current->id(), (int) $user->getKey());
            $this->outbox('DocumentVoided', $docType, $docId, $h->eventPayload($doc));
        }, self::DEADLOCK_RETRIES);
    }

    private function assertPostableStatus(string $status, bool $approved): void
    {
        $allowed = $approved ? ['pending_approval'] : ['draft'];
        if (! in_array($status, $allowed, true)) {
            throw PostingException::wrongStatus($status, implode(' atau ', $allowed));
        }
    }

    /** Ditulis di transaksi yang sama; worker mengirimnya setelah commit. */
    private function outbox(string $event, string $docType, int $docId, array $extra): void
    {
        DB::table('outbox_events')->insert([
            'company_id' => $this->current->id(),
            'event_type' => $event,
            'payload' => json_encode(['doc_type' => $docType, 'doc_id' => $docId] + $extra, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'percobaan' => 0,
            'created_at' => now(),
        ]);
    }
}
