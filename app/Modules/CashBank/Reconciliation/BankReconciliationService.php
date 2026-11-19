<?php

namespace App\Modules\CashBank\Reconciliation;

use App\Models\User;
use App\Modules\Accounting\Approval\ApprovalService;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Support\Money;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Documents\CashPaymentService;
use App\Modules\CashBank\Documents\CashReceiptService;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\CashBank\Models\BankReconciliation;
use App\Modules\CashBank\Models\BankStatement;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\ApprovalRule;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi Bank (UC-27, ALG-09). Satu sesi = satu rekening koran (rekening + periode yang sama persis).
 *
 * Arah (sisi bank): kredit koran = uang masuk = baris jurnal bank DEBIT; debit koran = uang keluar = baris jurnal bank KREDIT.
 *
 * Rumus selisih (analisis: koran == buku + item belum cocok yang dijelaskan):
 *   saldo_buku    = SUM(debit - kredit) seluruh baris jurnal akun bank sampai periode_selesai
 *   beredar       = SUM(debit - kredit) baris jurnal bank yang belum dicocokkan (bukan saldo awal, bukan jurnal yang dibalik/pembalik)
 *   selisih       = saldo_koran - (saldo_buku - beredar)
 * Baris koran yang belum cocok TIDAK dianggap dijelaskan: harus dicocokkan atau dibuatkan transaksi baru dulu.
 * Selesai hanya bila selisih = 0 dan tidak ada baris koran belum cocok. Sesi 'selesai' terkunci.
 */
class BankReconciliationService
{
    public const TOLERANSI_DEFAULT = 3;

    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
        private PostingOrchestrator $orchestrator,
        private CashReceiptService $receipts,
        private CashPaymentService $payments,
        private ApprovalService $approvals,
    ) {
    }

    // ------------------------------------------------------------------------------------------ sesi

    public function create(int $statementId, User $user): BankReconciliation
    {
        $st = BankStatement::query()->findOrFail($statementId);
        $bank = BankAccount::query()->findOrFail((int) $st->bank_account_id);

        return DB::transaction(function () use ($st, $bank, $user) {
            DB::table('bank_accounts')->where('company_id', $this->current->id())->where('id', $bank->getKey())->lockForUpdate()->first();

            $ada = BankReconciliation::query()->where('bank_account_id', $bank->getKey())->where('periode_selesai', $st->periode_selesai->toDateString())->first();
            if ($ada) {
                throw new CashBankException("Rekonsiliasi untuk rekening dan periode ini sudah ada (#{$ada->id}, status {$ada->status}).", 'reconciliation_exists', 409);
            }

            $rec = BankReconciliation::create([
                'bank_account_id' => $bank->getKey(),
                'periode_mulai' => $st->periode_mulai->toDateString(), 'periode_selesai' => $st->periode_selesai->toDateString(),
                'saldo_koran' => (string) $st->saldo_akhir, 'saldo_buku' => '0', 'selisih' => '0', 'status' => 'berjalan',
                'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->refresh($rec);
            $this->audit->log('bank_reconciliation', (int) $rec->getKey(), 'create', null, ['bank_statement_id' => $st->getKey(), 'periode' => $rec->periode_mulai->toDateString() . ' s/d ' . $rec->periode_selesai->toDateString()]);

            return $rec->refresh();
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $cid = $this->current->id();

            $lineIds = DB::table('reconciliation_items')->where('company_id', $cid)->where('bank_reconciliation_id', $id)->whereNotNull('bank_statement_line_id')->pluck('bank_statement_line_id')->all();
            DB::table('reconciliation_items')->where('company_id', $cid)->where('bank_reconciliation_id', $id)->delete();
            foreach (array_chunk($lineIds, 500) as $chunk) {
                DB::table('bank_statement_lines')->where('company_id', $cid)->whereIn('id', $chunk)->update(['status_cocok' => 'belum', 'updated_at' => now()]);
            }
            $this->audit->log('bank_reconciliation', $id, 'delete', ['status' => $rec->status, 'item' => count($lineIds)], null);
            $rec->delete();
        });
    }

    public function complete(int $id, User $user): BankReconciliation
    {
        return DB::transaction(function () use ($id, $user) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $f = $this->refresh($rec);

            if ($f['baris_koran_belum_cocok'] > 0) {
                throw new CashBankException("Masih ada {$f['baris_koran_belum_cocok']} baris rekening koran yang belum dicocokkan. Cocokkan atau buat transaksi baru dulu.", 'unmatched_statement_lines');
            }
            if (Money::cmp($f['selisih'], '0') !== 0) {
                throw new CashBankException("Selisih belum nol ({$f['selisih']}). Saldo koran {$f['saldo_koran']}, saldo buku disesuaikan {$f['saldo_buku_disesuaikan']}.", 'difference_not_zero');
            }

            $rec->forceFill(['status' => 'selesai', 'diselesaikan_oleh' => $user->getKey(), 'diselesaikan_pada' => now(), 'updated_by' => $user->getKey()])->save();
            $this->audit->log('bank_reconciliation', $id, 'complete', null, ['saldo_koran' => $f['saldo_koran'], 'saldo_buku' => $f['saldo_buku'], 'selisih' => $f['selisih']]);

            return $rec->refresh();
        });
    }

    /** Buka kembali sesi selesai. Ditolak bila ada periode akuntansi Closed yang beririsan (Tahap 4 bagian 7). */
    public function reopen(int $id, string $alasan, User $user): BankReconciliation
    {
        if (trim($alasan) === '') {
            throw new CashBankException('Alasan wajib diisi.', 'reason_required');
        }

        return DB::transaction(function () use ($id, $alasan, $user) {
            $rec = $this->lock($id);
            if ($rec->status !== 'selesai') {
                throw new CashBankException('Hanya rekonsiliasi berstatus selesai yang bisa dibuka kembali.', 'not_completed');
            }
            $closed = DB::table('accounting_periods')->where('company_id', $this->current->id())->where('status', 'closed')
                ->where('tanggal_mulai', '<=', $rec->periode_selesai->toDateString())->where('tanggal_selesai', '>=', $rec->periode_mulai->toDateString())->exists();
            if ($closed) {
                throw new CashBankException('Ada periode akuntansi Closed yang beririsan dengan rekonsiliasi ini. Buka kunci periodenya dulu.', 'period_closed');
            }

            $rec->forceFill(['status' => 'dibuka_kembali', 'diselesaikan_oleh' => null, 'diselesaikan_pada' => null, 'updated_by' => $user->getKey()])->save();
            $this->audit->log('bank_reconciliation', $id, 'reopen', ['status' => 'selesai'], ['status' => 'dibuka_kembali', 'alasan' => $alasan]);

            return $rec->refresh();
        });
    }

    // ------------------------------------------------------------------------------------------ pencocokan

    /** Pratinjau ALG-09 tanpa menulis apa pun. */
    public function suggest(int $id, ?int $toleransi = null): array
    {
        $rec = BankReconciliation::query()->findOrFail($id);

        return $this->buildPlan($rec, $toleransi ?? self::TOLERANSI_DEFAULT);
    }

    /** Terapkan pasangan skor >= 0.8; saran (0.5-0.8) dikembalikan tanpa disimpan. */
    public function autoMatch(int $id, ?int $toleransi, User $user): array
    {
        return DB::transaction(function () use ($id, $toleransi, $user) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $tol = $toleransi ?? self::TOLERANSI_DEFAULT;
            $plan = $this->buildPlan($rec, $tol);

            $now = now();
            $rows = [];
            $lineIds = [];
            foreach ($plan['auto'] as $p) {
                $rows[] = [
                    'company_id' => $rec->company_id, 'bank_reconciliation_id' => $rec->getKey(), 'bank_statement_line_id' => $p['stmt_id'], 'journal_line_id' => $p['journal_line_id'],
                    'tipe_cocok' => 'otomatis', 'skor' => number_format($p['skor'], 4, '.', ''), 'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
                ];
                $lineIds[] = $p['stmt_id'];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('reconciliation_items')->insert($chunk);
            }
            foreach (array_chunk($lineIds, 500) as $chunk) {
                DB::table('bank_statement_lines')->where('company_id', $rec->company_id)->whereIn('id', $chunk)->update(['status_cocok' => 'otomatis', 'updated_at' => $now]);
            }

            $f = $this->refresh($rec);
            $this->audit->log('bank_reconciliation', $id, 'auto_match', null, ['dicocokkan' => count($rows), 'saran' => count($plan['saran']), 'toleransi_hari' => $tol]);

            return ['dicocokkan' => count($rows), 'saran' => $plan['saran'], 'detail' => $f];
        });
    }

    public function matchManual(int $id, int $statementLineId, int $journalLineId, User $user): array
    {
        return DB::transaction(function () use ($id, $statementLineId, $journalLineId, $user) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $st = $this->statementFor($rec);
            $bank = $this->bankOf($rec);
            $cid = $this->current->id();

            $line = DB::table('bank_statement_lines')->where('company_id', $cid)->where('bank_statement_id', $st->getKey())->where('id', $statementLineId)->first()
                ?? throw new CashBankException('Baris rekening koran tidak ada di rekening koran sesi ini.', 'line_not_found', 404);
            if ($this->isMatched('bank_statement_line_id', $statementLineId)) {
                throw new CashBankException('Baris rekening koran ini sudah dicocokkan.', 'already_matched', 409);
            }

            $jl = $this->bookQuery($rec, $bank)->where('jl.id', $journalLineId)->selectRaw('jl.id, jl.tanggal, jl.debit, jl.kredit')->first()
                ?? throw new CashBankException('Baris jurnal bukan kandidat: bukan akun rekening ini, sudah dicocokkan, dibalik, saldo awal, atau melewati akhir periode.', 'journal_line_not_candidate', 404);

            if (self::arahKoran($line) !== self::arahBuku($jl) || Money::cmp((string) self::nominalKoran($line), (string) self::nominalBuku($jl)) !== 0) {
                throw new CashBankException('Arah dan nominal harus sama. Kredit koran = debit buku (uang masuk); debit koran = kredit buku (uang keluar).', 'amount_or_direction_mismatch');
            }

            $now = now();
            try {
                DB::table('reconciliation_items')->insert([
                    'company_id' => $cid, 'bank_reconciliation_id' => $id, 'bank_statement_line_id' => $statementLineId, 'journal_line_id' => $journalLineId,
                    'tipe_cocok' => 'manual', 'skor' => null, 'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000') {
                    throw new CashBankException('Salah satu baris sudah dicocokkan oleh proses lain.', 'already_matched', 409);
                }
                throw $e;
            }
            DB::table('bank_statement_lines')->where('company_id', $cid)->where('id', $statementLineId)->update(['status_cocok' => 'manual', 'updated_at' => $now]);

            $f = $this->refresh($rec);
            $this->audit->log('bank_reconciliation', $id, 'match_manual', null, ['bank_statement_line_id' => $statementLineId, 'journal_line_id' => $journalLineId]);

            return $f;
        });
    }

    public function unmatch(int $id, int $itemId, User $user): array
    {
        return DB::transaction(function () use ($id, $itemId) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $cid = $this->current->id();

            $item = DB::table('reconciliation_items')->where('company_id', $cid)->where('bank_reconciliation_id', $id)->where('id', $itemId)->first()
                ?? throw new CashBankException('Pasangan tidak ditemukan di sesi ini.', 'item_not_found', 404);

            DB::table('reconciliation_items')->where('company_id', $cid)->where('id', $itemId)->delete();
            if ($item->bank_statement_line_id) {
                DB::table('bank_statement_lines')->where('company_id', $cid)->where('id', $item->bank_statement_line_id)->update(['status_cocok' => 'belum', 'updated_at' => now()]);
            }

            $f = $this->refresh($rec);
            $this->audit->log('bank_reconciliation', $id, 'unmatch', ['bank_statement_line_id' => $item->bank_statement_line_id, 'journal_line_id' => $item->journal_line_id, 'tipe_cocok' => $item->tipe_cocok], null);

            return $f;
        });
    }

    /**
     * Buat transaksi baru dari baris koran yang belum cocok (biaya admin, bunga): kredit koran -> Penerimaan Kas & Bank,
     * debit koran -> Pembayaran Kas & Bank, lawan akun = $accountId, tanggal = tanggal baris koran. Dokumen langsung diposting
     * (ALG-02) dan dipasangkan sebagai 'transaksi_baru'. Bila dokumen kena aturan approval, seluruhnya dibatalkan.
     */
    public function createTransaction(int $id, int $statementLineId, int $accountId, ?string $memo, User $user): array
    {
        return DB::transaction(function () use ($id, $statementLineId, $accountId, $memo, $user) {
            $rec = $this->lock($id);
            $this->assertEditable($rec);
            $st = $this->statementFor($rec);
            $bank = $this->bankOf($rec);
            $cid = $this->current->id();

            $line = DB::table('bank_statement_lines')->where('company_id', $cid)->where('bank_statement_id', $st->getKey())->where('id', $statementLineId)->first()
                ?? throw new CashBankException('Baris rekening koran tidak ada di rekening koran sesi ini.', 'line_not_found', 404);
            if ($this->isMatched('bank_statement_line_id', $statementLineId)) {
                throw new CashBankException('Baris rekening koran ini sudah dicocokkan.', 'already_matched', 409);
            }

            $masuk = self::arahKoran($line) === 'masuk';
            $svc = $masuk ? $this->receipts : $this->payments;
            $docType = $masuk ? 'cash_receipt' : 'cash_payment';

            $doc = $svc->create([
                'tanggal' => $line->tanggal, 'rekening_id' => $bank->getKey(), 'memo' => $memo ?: $line->deskripsi,
                'lines' => [['account_id' => $accountId, 'deskripsi' => $line->deskripsi, 'nominal' => self::nominalKoran($line)]],
            ], $user);

            if ($this->approvals->requirementFor($docType, $doc, $user, ApprovalRule::AKSI_POST)) {
                throw new CashBankException('Dokumen ini memerlukan persetujuan. Buat lewat menu Penerimaan/Pembayaran, setujui, lalu cocokkan manual.', 'approval_required');
            }

            $result = $this->orchestrator->post($docType, (int) $doc->getKey(), $user);
            $jl = DB::table('journal_lines')->where('company_id', $cid)->whereIn('journal_id', $result->journalIds)->where('account_id', $bank->account_id)->first()
                ?? throw new CashBankException('Baris jurnal bank dari dokumen baru tidak ditemukan.', 'journal_line_missing', 500);

            $now = now();
            DB::table('reconciliation_items')->insert([
                'company_id' => $cid, 'bank_reconciliation_id' => $id, 'bank_statement_line_id' => $statementLineId, 'journal_line_id' => $jl->id,
                'tipe_cocok' => 'transaksi_baru', 'skor' => null, 'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
            ]);
            DB::table('bank_statement_lines')->where('company_id', $cid)->where('id', $statementLineId)->update(['status_cocok' => 'manual', 'updated_at' => $now]);

            $f = $this->refresh($rec);
            $this->audit->log('bank_reconciliation', $id, 'create_transaction', null, ['bank_statement_line_id' => $statementLineId, 'doc_type' => $docType, 'doc_id' => (int) $doc->getKey(), 'nomor' => $result->nomor]);

            return ['doc_type' => $docType, 'doc_id' => (int) $doc->getKey(), 'nomor' => $result->nomor, 'detail' => $f];
        });
    }

    // ------------------------------------------------------------------------------------------ baca

    /** Angka sesi; juga disimpan ke bank_reconciliations (saldo_buku, selisih). */
    public function refresh(BankReconciliation $rec): array
    {
        $f = $this->figures($rec);
        if ($rec->status !== 'selesai') {
            DB::table('bank_reconciliations')->where('company_id', $rec->company_id)->where('id', $rec->getKey())->update(['saldo_buku' => $f['saldo_buku'], 'selisih' => $f['selisih'], 'updated_at' => now()]);
        }

        return $f;
    }

    public function figures(BankReconciliation $rec): array
    {
        $cid = $this->current->id();
        $bank = $this->bankOf($rec);

        $s = DB::table('journal_lines')->where('company_id', $cid)->where('account_id', $bank->account_id)->where('tanggal', '<=', $rec->periode_selesai->toDateString())
            ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(kredit),0) AS k')->first();
        $buku = Money::sub((string) $s->d, (string) $s->k);

        $o = $this->bookQuery($rec, $bank)->selectRaw('COUNT(*) AS n, COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.kredit),0) AS k')->first();
        $beredar = Money::sub((string) $o->d, (string) $o->k);
        $disesuaikan = Money::sub($buku, $beredar);
        $koran = Money::round((string) $rec->saldo_koran, 4);

        $u = $this->unmatchedStatementQuery($rec)->selectRaw('COUNT(*) AS n')->first();
        $cocok = DB::table('reconciliation_items')->where('company_id', $cid)->where('bank_reconciliation_id', $rec->getKey())->count();
        $selisih = Money::sub($koran, $disesuaikan);

        return [
            'id' => (int) $rec->getKey(), 'bank_account_id' => (int) $rec->bank_account_id, 'status' => $rec->status,
            'periode_mulai' => $rec->periode_mulai->toDateString(), 'periode_selesai' => $rec->periode_selesai->toDateString(),
            'saldo_koran' => $koran, 'saldo_buku' => $buku, 'transaksi_beredar' => $beredar, 'saldo_buku_disesuaikan' => $disesuaikan, 'selisih' => $selisih,
            'baris_koran_belum_cocok' => (int) $u->n, 'baris_buku_belum_cocok' => (int) $o->n, 'item_cocok' => $cocok,
            'bisa_selesai' => $rec->status !== 'selesai' && (int) $u->n === 0 && Money::cmp($selisih, '0') === 0,
        ];
    }

    /** @return array{koran: list<array>, buku: list<array>, item: list<array>} daftar kerja layar P7 (maks 500 per bagian) */
    public function worklist(int $id): array
    {
        $rec = BankReconciliation::query()->findOrFail($id);
        $bank = $this->bankOf($rec);

        $koran = $this->unmatchedStatementQuery($rec)->select('l.id', 'l.line_no', 'l.tanggal', 'l.deskripsi', 'l.debit', 'l.kredit')->orderBy('l.line_no')->limit(500)->get()
            ->map(fn ($l) => ['id' => (int) $l->id, 'line_no' => (int) $l->line_no, 'tanggal' => $l->tanggal, 'deskripsi' => $l->deskripsi, 'arah' => self::arahKoran($l), 'nominal' => Money::round((string) self::nominalKoran($l), 4)])->values()->all();

        $buku = $this->bookQuery($rec, $bank)->select('jl.id', 'jl.tanggal', 'jl.debit', 'jl.kredit', 'jl.memo as line_memo', 'j.nomor', 'j.memo as journal_memo')->orderBy('jl.tanggal')->orderBy('jl.id')->limit(500)->get()
            ->map(fn ($b) => ['journal_line_id' => (int) $b->id, 'tanggal' => $b->tanggal, 'nomor_jurnal' => $b->nomor, 'memo' => $b->journal_memo ?: $b->line_memo, 'arah' => self::arahBuku($b), 'nominal' => Money::round((string) self::nominalBuku($b), 4)])->values()->all();

        $item = DB::table('reconciliation_items')->where('company_id', $this->current->id())->where('bank_reconciliation_id', $id)->orderBy('id')->limit(500)->get()
            ->map(fn ($i) => ['id' => (int) $i->id, 'bank_statement_line_id' => $i->bank_statement_line_id ? (int) $i->bank_statement_line_id : null, 'journal_line_id' => $i->journal_line_id ? (int) $i->journal_line_id : null, 'tipe_cocok' => $i->tipe_cocok, 'skor' => $i->skor !== null ? (string) $i->skor : null])->values()->all();

        return ['koran' => $koran, 'buku' => $buku, 'item' => $item];
    }

    // ------------------------------------------------------------------------------------------ internal

    private function buildPlan(BankReconciliation $rec, int $tol): array
    {
        $bank = $this->bankOf($rec);

        $stmt = $this->unmatchedStatementQuery($rec)->select('l.id', 'l.tanggal', 'l.deskripsi', 'l.debit', 'l.kredit')->get()
            ->map(fn ($l) => ['id' => (int) $l->id, 'tanggal' => (string) $l->tanggal, 'arah' => self::arahKoran($l), 'nominal' => (string) self::nominalKoran($l), 'deskripsi' => $l->deskripsi])->all();

        $book = $this->bookQuery($rec, $bank)->select('jl.id', 'jl.tanggal', 'jl.debit', 'jl.kredit', 'jl.memo as line_memo', 'j.nomor', 'j.memo as journal_memo')->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'tanggal' => (string) $b->tanggal, 'arah' => self::arahBuku($b), 'nominal' => (string) self::nominalBuku($b), 'referensi' => trim($b->nomor . ' ' . $b->journal_memo . ' ' . $b->line_memo)])->all();

        return ReconciliationMatcher::plan($stmt, $book, max(0, $tol));
    }

    /** Baris jurnal bank yang masih bisa dicocokkan. Pemanggil menambahkan select(). */
    private function bookQuery(BankReconciliation $rec, BankAccount $bank): Builder
    {
        return DB::table('journal_lines as jl')
            ->join('journals as j', fn ($x) => $x->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id'))
            ->where('jl.company_id', $this->current->id())
            ->where('jl.account_id', $bank->account_id)
            ->where('jl.tanggal', '<=', $rec->periode_selesai->toDateString())
            ->where('j.is_opening', false)
            ->whereNull('j.reversed_of_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journals as r')->whereColumn('r.reversed_of_id', 'j.id')->whereColumn('r.company_id', 'j.company_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reconciliation_items as ri')->whereColumn('ri.journal_line_id', 'jl.id')->whereColumn('ri.company_id', 'jl.company_id'));
    }

    private function unmatchedStatementQuery(BankReconciliation $rec): Builder
    {
        $st = $this->statementFor($rec);

        return DB::table('bank_statement_lines as l')
            ->where('l.company_id', $this->current->id())->where('l.bank_statement_id', $st->getKey())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reconciliation_items as ri')->whereColumn('ri.bank_statement_line_id', 'l.id')->whereColumn('ri.company_id', 'l.company_id'));
    }

    private function statementFor(BankReconciliation $rec): BankStatement
    {
        return BankStatement::query()->where('bank_account_id', $rec->bank_account_id)
            ->where('periode_mulai', $rec->periode_mulai->toDateString())->where('periode_selesai', $rec->periode_selesai->toDateString())->first()
            ?? throw new CashBankException('Rekening koran untuk sesi ini sudah tidak ada. Hapus sesi ini lalu buat ulang dari rekening koran yang benar.', 'statement_missing');
    }

    private function bankOf(BankReconciliation $rec): BankAccount
    {
        return BankAccount::query()->findOrFail((int) $rec->bank_account_id);
    }

    private function lock(int $id): BankReconciliation
    {
        return BankReconciliation::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertEditable(BankReconciliation $rec): void
    {
        if ($rec->isLocked()) {
            throw new CashBankException('Rekonsiliasi sudah selesai dan terkunci. Buka kembali dulu untuk mengubahnya.', 'reconciliation_locked', 409);
        }
    }

    private function isMatched(string $column, int $id): bool
    {
        return DB::table('reconciliation_items')->where('company_id', $this->current->id())->where($column, $id)->exists();
    }

    private static function arahKoran(object $l): string
    {
        return Money::cmp((string) $l->kredit, '0') > 0 ? 'masuk' : 'keluar';
    }

    private static function nominalKoran(object $l): string
    {
        return Money::cmp((string) $l->kredit, '0') > 0 ? (string) $l->kredit : (string) $l->debit;
    }

    private static function arahBuku(object $b): string
    {
        return Money::cmp((string) $b->debit, '0') > 0 ? 'masuk' : 'keluar';
    }

    private static function nominalBuku(object $b): string
    {
        return Money::cmp((string) $b->debit, '0') > 0 ? (string) $b->debit : (string) $b->kredit;
    }
}
