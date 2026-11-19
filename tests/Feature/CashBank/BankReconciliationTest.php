<?php

namespace Tests\Feature\CashBank;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Documents\CashPaymentService;
use App\Modules\CashBank\Documents\CashReceiptService;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\CashBank\Reconciliation\BankReconciliationService;
use App\Modules\CashBank\Reconciliation\BankStatementService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Rekonsiliasi Bank, sesi + ALG-09 (7C-2). BELUM pernah dijalankan oleh penulisnya: jalankan di MySQL 8 asli. */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private BankAccount $bca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = $this->newCompany($this->owner);
        $this->as($this->owner, $this->co);
        $this->bca = app(BankAccountService::class)->create(['account_id' => $this->acc('1-1120'), 'nama_bank' => 'BCA'], $this->owner);
    }

    // ------------------------------------------------------------------------------------------ pembantu

    private function newCompany(User $o): Company
    {
        return app(CreateCompany::class)->handle($o, ['nama' => 'PT Rekon ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function as(User $u, Company $c): void
    {
        app(CurrentCompany::class)->set($c, CompanyUser::where(['company_id' => $c->id, 'user_id' => $u->id])->firstOrFail());
        app(\App\Modules\Platform\Access\PermissionChecker::class)->flush();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create(['company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active', 'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id')]);

        return $u;
    }

    private function acc(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private function svc(): BankReconciliationService
    {
        return app(BankReconciliationService::class);
    }

    /** Penerimaan terposting ke BCA; mengembalikan id baris jurnal bank. */
    private function terima(string $tgl, string $nominal): int
    {
        $id = (int) app(CashReceiptService::class)->create(['tanggal' => $tgl, 'rekening_id' => $this->bca->id, 'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => $nominal]]], $this->owner)->id;
        $r = app(PostingOrchestrator::class)->post('cash_receipt', $id, $this->owner);

        return $this->bankLine($r->journalIds);
    }

    private function bayar(string $tgl, string $nominal): int
    {
        $id = (int) app(CashPaymentService::class)->create(['tanggal' => $tgl, 'rekening_id' => $this->bca->id, 'lines' => [['account_id' => $this->acc('6-2000'), 'nominal' => $nominal]]], $this->owner)->id;
        $r = app(PostingOrchestrator::class)->post('cash_payment', $id, $this->owner);

        return $this->bankLine($r->journalIds);
    }

    private function bankLine(array $journalIds): int
    {
        return (int) DB::table('journal_lines')->whereIn('journal_id', $journalIds)->where('account_id', $this->acc('1-1120'))->value('id');
    }

    /** Koran Oktober: awal 0; +5.000.000 (02/10), -2.000.000 (05/10), -150.000 biaya admin (31/10); akhir 2.850.000. */
    private function koranOktober(): int
    {
        return (int) app(BankStatementService::class)->createManual([
            'bank_account_id' => $this->bca->id, 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-31', 'saldo_awal' => '0', 'saldo_akhir' => '2850000',
            'lines' => [
                ['tanggal' => '2026-10-02', 'deskripsi' => 'TRF CV SINAR', 'kredit' => '5000000'],
                ['tanggal' => '2026-10-05', 'deskripsi' => 'BAYAR PT ABADI', 'debit' => '2000000'],
                ['tanggal' => '2026-10-31', 'deskripsi' => 'BIAYA ADMIN', 'debit' => '150000'],
            ],
        ], $this->owner)->id;
    }

    /** Buku: +5.000.000 (02/10), -2.000.000 (05/10), -1.000.000 (30/10, belum di koran = transaksi beredar). */
    private function bukuOktober(): array
    {
        return ['a' => $this->terima('2026-10-02', '5000000'), 'b' => $this->bayar('2026-10-05', '2000000'), 'c' => $this->bayar('2026-10-30', '1000000')];
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "Diharapkan {$expected}, didapat {$actual}");
    }

    private function assertErr(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Seharusnya ditolak: {$code}");
        } catch (CashBankException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function lineId(int $stmtId, int $no): int
    {
        return (int) DB::table('bank_statement_lines')->where('bank_statement_id', $stmtId)->where('line_no', $no)->value('id');
    }

    // ------------------------------------------------------------------------------------------ tes

    public function test_sesi_menghitung_saldo_buku_transaksi_beredar_dan_selisih(): void
    {
        $this->bukuOktober();
        $rec = $this->svc()->create($this->koranOktober(), $this->owner);
        $f = $this->svc()->figures($rec);

        $this->assertSame('berjalan', $f['status']);
        $this->assertMoney('2850000', $f['saldo_koran']);
        $this->assertMoney('2000000', $f['saldo_buku']);
        $this->assertMoney('-1000000', $f['transaksi_beredar']);
        $this->assertMoney('3000000', $f['saldo_buku_disesuaikan']);
        $this->assertMoney('-150000', $f['selisih']);
        $this->assertSame(3, $f['baris_koran_belum_cocok']);
        $this->assertFalse($f['bisa_selesai']);
        $this->assertMoney('-150000', DB::table('bank_reconciliations')->where('id', $rec->id)->value('selisih')); // tersimpan
    }

    public function test_satu_sesi_per_rekening_dan_periode(): void
    {
        $st = $this->koranOktober();
        $this->svc()->create($st, $this->owner);
        $this->assertErr('reconciliation_exists', fn () => $this->svc()->create($st, $this->owner));
    }

    public function test_auto_match_menyimpan_skor_dan_menandai_baris(): void
    {
        $b = $this->bukuOktober();
        $st = $this->koranOktober();
        $rec = $this->svc()->create($st, $this->owner);

        $r = $this->svc()->autoMatch($rec->id, null, $this->owner);

        $this->assertSame(2, $r['dicocokkan']);
        $this->assertSame([], $r['saran']);
        $items = DB::table('reconciliation_items')->where('bank_reconciliation_id', $rec->id)->orderBy('bank_statement_line_id')->get();
        $this->assertSame([$b['a'], $b['b']], $items->pluck('journal_line_id')->map(fn ($x) => (int) $x)->all());
        $this->assertSame(['otomatis', 'otomatis'], $items->pluck('tipe_cocok')->all());
        $this->assertMoney('0.8', $items[0]->skor);
        $this->assertSame(['otomatis', 'otomatis', 'belum'], DB::table('bank_statement_lines')->where('bank_statement_id', $st)->orderBy('line_no')->pluck('status_cocok')->all());
        $this->assertSame(1, $r['detail']['baris_koran_belum_cocok']);
    }

    public function test_dua_penerimaan_nominal_sama_beda_tanggal_tidak_tertukar(): void
    {
        $j1 = $this->terima('2026-10-02', '1000000');
        $j2 = $this->terima('2026-10-04', '1000000');
        $st = (int) app(BankStatementService::class)->createManual([
            'bank_account_id' => $this->bca->id, 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-31', 'saldo_awal' => '0', 'saldo_akhir' => '2000000',
            'lines' => [['tanggal' => '2026-10-02', 'deskripsi' => 'SETOR A', 'kredit' => '1000000'], ['tanggal' => '2026-10-04', 'deskripsi' => 'SETOR B', 'kredit' => '1000000']],
        ], $this->owner)->id;
        $rec = $this->svc()->create($st, $this->owner);

        $this->svc()->autoMatch($rec->id, null, $this->owner);

        $this->assertSame((int) $j1, (int) DB::table('reconciliation_items')->where('bank_statement_line_id', $this->lineId($st, 1))->value('journal_line_id'));
        $this->assertSame((int) $j2, (int) DB::table('reconciliation_items')->where('bank_statement_line_id', $this->lineId($st, 2))->value('journal_line_id'));
        $this->assertTrue($this->svc()->figures($rec)['bisa_selesai']);
    }

    public function test_suggestions_tidak_menulis_apa_pun(): void
    {
        $this->terima('2026-10-04', '800000'); // koran 02/10 vs buku 04/10: skor 0.6 = saran
        $st = (int) app(BankStatementService::class)->createManual([
            'bank_account_id' => $this->bca->id, 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-31', 'saldo_awal' => '0', 'saldo_akhir' => '800000',
            'lines' => [['tanggal' => '2026-10-02', 'deskripsi' => 'SETOR', 'kredit' => '800000']],
        ], $this->owner)->id;
        $rec = $this->svc()->create($st, $this->owner);

        $r = $this->svc()->suggest($rec->id);
        $this->assertSame([], $r['auto']);
        $this->assertCount(1, $r['saran']);
        $this->assertSame(0, DB::table('reconciliation_items')->count());

        $a = $this->svc()->autoMatch($rec->id, null, $this->owner);
        $this->assertSame(0, $a['dicocokkan']);
        $this->assertCount(1, $a['saran']);
        $this->assertSame(0, DB::table('reconciliation_items')->count());
    }

    public function test_cocok_manual_menolak_nominal_atau_arah_berbeda_lalu_bisa_dilepas(): void
    {
        $b = $this->bukuOktober();
        $st = $this->koranOktober();
        $rec = $this->svc()->create($st, $this->owner);

        $this->assertErr('amount_or_direction_mismatch', fn () => $this->svc()->matchManual($rec->id, $this->lineId($st, 1), $b['b'], $this->owner)); // 5jt masuk vs 2jt keluar
        $this->assertErr('amount_or_direction_mismatch', fn () => $this->svc()->matchManual($rec->id, $this->lineId($st, 2), $b['c'], $this->owner)); // 2jt vs 1jt

        $f = $this->svc()->matchManual($rec->id, $this->lineId($st, 1), $b['a'], $this->owner);
        $this->assertSame(2, $f['baris_koran_belum_cocok']);
        $this->assertSame('manual', DB::table('bank_statement_lines')->where('id', $this->lineId($st, 1))->value('status_cocok'));
        $this->assertErr('already_matched', fn () => $this->svc()->matchManual($rec->id, $this->lineId($st, 1), $b['a'], $this->owner));

        $itemId = (int) DB::table('reconciliation_items')->where('bank_reconciliation_id', $rec->id)->value('id');
        $f = $this->svc()->unmatch($rec->id, $itemId, $this->owner);
        $this->assertSame(3, $f['baris_koran_belum_cocok']);
        $this->assertSame('belum', DB::table('bank_statement_lines')->where('id', $this->lineId($st, 1))->value('status_cocok'));
    }

    public function test_transaksi_baru_dari_baris_koran_menutup_selisih_lalu_selesai_dan_terkunci(): void
    {
        $b = $this->bukuOktober();
        $st = $this->koranOktober();
        $rec = $this->svc()->create($st, $this->owner);
        $this->svc()->autoMatch($rec->id, null, $this->owner);

        $this->assertErr('unmatched_statement_lines', fn () => $this->svc()->complete($rec->id, $this->owner));

        $r = $this->svc()->createTransaction($rec->id, $this->lineId($st, 3), $this->acc('6-2000'), null, $this->owner);
        $this->assertSame('cash_payment', $r['doc_type']);
        $this->assertMoney('0', $r['detail']['selisih']);
        $this->assertTrue($r['detail']['bisa_selesai']);
        $this->assertSame('transaksi_baru', DB::table('reconciliation_items')->where('bank_statement_line_id', $this->lineId($st, 3))->value('tipe_cocok'));
        $this->assertSame('posted', app(CashPaymentService::class)->find($r['doc_id'])->status);

        $done = $this->svc()->complete($rec->id, $this->owner);
        $this->assertSame('selesai', $done->status);
        $this->assertSame($this->owner->id, (int) $done->diselesaikan_oleh);

        // terkunci
        $this->assertErr('reconciliation_locked', fn () => $this->svc()->autoMatch($rec->id, null, $this->owner));
        $this->assertErr('reconciliation_locked', fn () => $this->svc()->unmatch($rec->id, 1, $this->owner));
        $this->assertErr('reconciliation_locked', fn () => $this->svc()->delete($rec->id, $this->owner));

        // baris yang sudah direkonsiliasi tidak bisa di-void (aturan 7C-1/Kas & Bank)
        $docId = (int) DB::table('journals')->join('journal_lines', 'journal_lines.journal_id', '=', 'journals.id')->where('journal_lines.id', $b['a'])->value('journals.source_id');
        $this->expectException(PostingException::class);
        app(PostingOrchestrator::class)->void('cash_receipt', $docId, 'coba', $this->owner);
    }

    public function test_buka_kembali_perlu_alasan_dan_ditolak_bila_periode_closed(): void
    {
        $this->bukuOktober();
        $st = $this->koranOktober();
        $rec = $this->svc()->create($st, $this->owner);
        $this->svc()->autoMatch($rec->id, null, $this->owner);
        $this->svc()->createTransaction($rec->id, $this->lineId($st, 3), $this->acc('6-2000'), null, $this->owner);
        $this->svc()->complete($rec->id, $this->owner);

        $this->assertErr('reason_required', fn () => $this->svc()->reopen($rec->id, '  ', $this->owner));

        $period = app(PeriodService::class)->periodFor('2026-10-15');
        app(PeriodService::class)->close($period, $this->owner);
        $this->assertErr('period_closed', fn () => $this->svc()->reopen($rec->id, 'koreksi', $this->owner));

        app(PeriodService::class)->reopen($period->refresh(), $this->owner, 'uji');
        $this->assertSame('dibuka_kembali', $this->svc()->reopen($rec->id, 'koreksi', $this->owner)->status);
        $this->svc()->unmatch($rec->id, (int) DB::table('reconciliation_items')->where('bank_reconciliation_id', $rec->id)->value('id'), $this->owner); // bisa diubah lagi
    }

    public function test_hapus_sesi_mengembalikan_status_baris_koran(): void
    {
        $this->bukuOktober();
        $st = $this->koranOktober();
        $rec = $this->svc()->create($st, $this->owner);
        $this->svc()->autoMatch($rec->id, null, $this->owner);

        $this->svc()->delete($rec->id, $this->owner);

        $this->assertSame(0, DB::table('bank_reconciliations')->count());
        $this->assertSame(0, DB::table('reconciliation_items')->count());
        $this->assertSame(['belum', 'belum', 'belum'], DB::table('bank_statement_lines')->where('bank_statement_id', $st)->orderBy('line_no')->pluck('status_cocok')->all());
    }

    public function test_jurnal_yang_di_void_tidak_menjadi_kandidat_atau_beredar(): void
    {
        $idA = (int) app(CashReceiptService::class)->create(['tanggal' => '2026-10-02', 'rekening_id' => $this->bca->id, 'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '5000000']]], $this->owner)->id;
        app(PostingOrchestrator::class)->post('cash_receipt', $idA, $this->owner);
        app(PostingOrchestrator::class)->void('cash_receipt', $idA, 'salah input', $this->owner);

        $st = (int) app(BankStatementService::class)->createManual([
            'bank_account_id' => $this->bca->id, 'periode_mulai' => '2026-10-01', 'periode_selesai' => '2026-10-31', 'saldo_awal' => '0', 'saldo_akhir' => '100',
            'lines' => [['tanggal' => '2026-10-03', 'deskripsi' => 'BUNGA', 'kredit' => '100']],
        ], $this->owner)->id;
        $rec = $this->svc()->create($st, $this->owner);
        $f = $this->svc()->figures($rec);

        $this->assertSame(0, $f['baris_buku_belum_cocok']);
        $this->assertMoney('0', $f['saldo_buku']); // asli + pembalik saling hapus
        $this->assertCount(0, $this->svc()->worklist($rec->id)['buku']);
    }

    public function test_isolasi_antar_perusahaan(): void
    {
        $this->bukuOktober();
        $rec = $this->svc()->create($this->koranOktober(), $this->owner);

        $other = User::factory()->create();
        $co2 = $this->newCompany($other);
        $this->as($other, $co2);

        $this->expectException(ModelNotFoundException::class);
        $this->svc()->suggest($rec->id);
    }

    public function test_http_hak_akses_dan_alur_dasar(): void
    {
        $this->bukuOktober();
        $st = $this->koranOktober();
        $h = ['X-Company-Id' => $this->co->public_id];

        Sanctum::actingAs($this->member('Akuntan'));
        $id = $this->withHeaders($h)->postJson('/api/v1/bank-reconciliations', ['bank_statement_id' => $st])->assertCreated()->assertJsonPath('data.baris_koran_belum_cocok', 3)->json('data.id');
        $this->withHeaders($h)->postJson("/api/v1/bank-reconciliations/{$id}/auto-match")->assertOk()->assertJsonPath('data.dicocokkan', 2);
        $this->withHeaders($h)->getJson("/api/v1/bank-reconciliations/{$id}")->assertOk()->assertJsonCount(1, 'data.koran')->assertJsonCount(1, 'data.buku')->assertJsonCount(2, 'data.item');
        $this->withHeaders($h)->postJson("/api/v1/bank-reconciliations/{$id}/complete")->assertStatus(422)->assertJsonPath('code', 'unmatched_statement_lines');
        $this->withHeaders($h)->getJson('/api/v1/bank-reconciliations?bank_account_id=' . $this->bca->id)->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->member('Kasir')); // Kasir tidak punya akses rekonsiliasi
        $this->withHeaders($h)->getJson('/api/v1/bank-reconciliations')->assertForbidden();
        $this->withHeaders($h)->postJson("/api/v1/bank-reconciliations/{$id}/auto-match")->assertForbidden();

        Sanctum::actingAs($this->member('Admin')); // Admin hanya lihat
        $this->withHeaders($h)->getJson("/api/v1/bank-reconciliations/{$id}")->assertOk();
        $this->withHeaders($h)->postJson("/api/v1/bank-reconciliations/{$id}/complete")->assertForbidden();
    }
}
