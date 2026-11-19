<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Ledger\AccountBalanceService;
use App\Modules\Accounting\Ledger\GeneralJournalService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Ledger\JournalException;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\Close\FiscalYearCloseService;
use App\Modules\Accounting\Periods\Close\PeriodCloseException;
use App\Modules\Accounting\Periods\Close\PeriodCloseService;
use App\Modules\Accounting\Periods\FiscalYear;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\Documents\BankTransferService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Recalc\StockRecalcService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proses Akhir Bulan dan Tutup Tahun (Step 7B, ALG-11).
 * BELUM pernah dijalankan oleh penulisnya (tanpa MySQL/vendor); jalankan di MySQL 8 asli.
 */
class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = $this->newCompany($this->owner);
        $this->as($this->owner, $this->co);
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => 'PT Tutup ' . uniqid(), 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function as(User $u, ?Company $c = null): void
    {
        $c ??= $this->co;
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

    /** Jurnal umum Dr $dr / Cr $cr sebesar $nominal pada $tanggal. */
    private function book(string $tanggal, string $dr, string $cr, string $nominal): Journal
    {
        return app(GeneralJournalService::class)->createGeneral([
            'tanggal' => $tanggal, 'memo' => 'uji',
            'lines' => [
                ['account_id' => $this->acc($dr), 'debit' => $nominal, 'kredit' => '0'],
                ['account_id' => $this->acc($cr), 'debit' => '0', 'kredit' => $nominal],
            ],
        ], $this->owner);
    }

    private function period(int $bulan): AccountingPeriod
    {
        return AccountingPeriod::query()->where('bulan', $bulan)->firstOrFail();
    }

    private function fy(): FiscalYear
    {
        return FiscalYear::query()->where('tahun', 2026)->firstOrFail();
    }

    private function svc(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    private function fysvc(): FiscalYearCloseService
    {
        return app(FiscalYearCloseService::class);
    }

    private function closeMonths(int $sampai): void
    {
        for ($b = 1; $b <= $sampai; $b++) {
            $this->svc()->close((int) $this->period($b)->id, $this->owner);
        }
    }

    private function expectClose(string $code, callable $fn): PeriodCloseException
    {
        try {
            $fn();
            $this->fail("Seharusnya ditolak dengan kode {$code}");
        } catch (PeriodCloseException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }
    }

    private function item(array $hasil, string $kode): array
    {
        foreach ($hasil['items'] as $i) {
            if ($i['kode'] === $kode) {
                return $i;
            }
        }
        $this->fail("Item checklist '{$kode}' tidak ada.");
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "Diharapkan {$expected}, didapat {$actual}");
    }

    private function draftTransfer(string $tanggal): void
    {
        $svc = app(BankAccountService::class);
        $a = $svc->create(['account_id' => $this->acc('1-1110'), 'nama_bank' => 'Kas', 'is_kas' => true], $this->owner);
        $b = $svc->create(['account_id' => $this->acc('1-1120'), 'nama_bank' => 'BCA', 'no_rekening' => '1'], $this->owner);
        app(BankTransferService::class)->create(['tanggal' => $tanggal, 'rekening_asal_id' => $a->id, 'rekening_tujuan_id' => $b->id, 'nominal' => '1000', 'biaya_admin' => '0'], $this->owner);
    }

    // ----------------------------------------------------------------------------- rekonsiliasi bank (7C-3)

    public function test_rekonsiliasi_bank_belum_selesai_menjadi_peringatan_dan_hilang_setelah_selesai(): void
    {
        $bank = app(\App\Modules\CashBank\BankAccounts\BankAccountService::class)->create(['account_id' => $this->acc('1-1120'), 'nama_bank' => 'BCA', 'no_rekening' => '9'], $this->owner);
        $docId = (int) app(\App\Modules\CashBank\Documents\CashReceiptService::class)->create([
            'tanggal' => '2026-01-10', 'rekening_id' => $bank->id, 'lines' => [['account_id' => $this->acc('4-1000'), 'nominal' => '100']],
        ], $this->owner)->id;
        app(\App\Modules\Accounting\Posting\PostingOrchestrator::class)->post('cash_receipt', $docId, $this->owner);
        $st = app(\App\Modules\CashBank\Reconciliation\BankStatementService::class)->createManual([
            'bank_account_id' => $bank->id, 'periode_mulai' => '2026-01-01', 'periode_selesai' => '2026-01-31', 'saldo_awal' => '0', 'saldo_akhir' => '100',
            'lines' => [['tanggal' => '2026-01-10', 'deskripsi' => 'SETOR', 'kredit' => '100']],
        ], $this->owner);

        $hasil = $this->svc()->check($this->period(1));
        $this->assertSame('warning', $this->item($hasil, 'rekonsiliasi_bank')['status']);
        $this->assertTrue($hasil['butuh_konfirmasi']);
        $this->assertSame(1, $this->item($hasil, 'rekonsiliasi_bank')['detail']['jumlah']);

        $rs = app(\App\Modules\CashBank\Reconciliation\BankReconciliationService::class);
        $rec = $rs->create((int) $st->id, $this->owner);
        $rs->autoMatch($rec->id, null, $this->owner);
        $this->assertSame('warning', $this->item($this->svc()->check($this->period(1)), 'rekonsiliasi_bank')['status']); // masih berjalan

        $rs->complete($rec->id, $this->owner);
        $this->assertSame('ok', $this->item($this->svc()->check($this->period(1)), 'rekonsiliasi_bank')['status']);
    }

    // ----------------------------------------------------------------------------- tutup bulan

    public function test_checklist_bersih_dan_tutup_bulan_wajib_berurutan(): void
    {
        $this->book('2026-01-10', '1-1110', '4-1000', '1000000');

        $hasil = $this->svc()->check($this->period(1));
        $this->assertSame('passed', $hasil['status']);
        $this->assertTrue($hasil['bisa_ditutup']);
        foreach (['transaksi_berulang', 'penyusutan_aset'] as $na) {
            $this->assertSame('na', $this->item($hasil, $na)['status']);
        }
        $this->assertSame('ok', $this->item($hasil, 'rekonsiliasi_bank')['status']); // 7C-3: tanpa rekening koran = tidak ada yang diperiksa

        // bulan 2 ditutup sebelum bulan 1: ditolak
        $e = $this->expectClose('close_blocked', fn () => $this->svc()->close((int) $this->period(2)->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'urutan')['status']);
        $this->assertSame('open', $this->period(2)->status);
        $this->assertSame(1, DB::table('period_close_logs')->where(['period_id' => $this->period(2)->id, 'status' => 'failed'])->count());

        $this->svc()->close((int) $this->period(1)->id, $this->owner);
        $this->svc()->close((int) $this->period(2)->id, $this->owner);
        $this->assertSame('closed', $this->period(1)->status);
        $this->assertSame('closed', $this->period(2)->status);
        $this->assertSame(1, DB::table('period_close_logs')->where(['period_id' => $this->period(1)->id, 'status' => 'closed'])->count());
        $this->assertCount(2, $this->svc()->logs($this->period(2))); // satu failed + satu closed

        $this->expectClose('already_closed', fn () => $this->svc()->close((int) $this->period(1)->id, $this->owner));
    }

    public function test_periode_terakhir_hanya_ditutup_lewat_tutup_tahun(): void
    {
        $this->expectClose('use_fiscal_year_close', fn () => $this->svc()->close((int) $this->period(12)->id, $this->owner));
    }

    public function test_draft_memberi_peringatan_dan_butuh_konfirmasi(): void
    {
        $this->draftTransfer('2026-01-15');

        $hasil = $this->svc()->check($this->period(1));
        $this->assertSame('warning', $hasil['status']);
        $this->assertSame(1, $this->item($hasil, 'draft')['detail']['total']);

        $this->expectClose('confirm_required', fn () => $this->svc()->close((int) $this->period(1)->id, $this->owner));
        $this->assertSame('open', $this->period(1)->status);
        $this->assertSame(1, DB::table('period_close_logs')->where(['period_id' => $this->period(1)->id, 'status' => 'warning'])->count());

        $this->svc()->close((int) $this->period(1)->id, $this->owner, konfirmasi: true);
        $this->assertSame('closed', $this->period(1)->status);
    }

    public function test_kebijakan_block_menolak_draft_walau_dikonfirmasi(): void
    {
        DB::table('company_settings')->where('company_id', $this->co->id)->update(['kebijakan_draft_akhir_bulan' => 'block']);
        $this->draftTransfer('2026-01-15');

        $e = $this->expectClose('close_blocked', fn () => $this->svc()->close((int) $this->period(1)->id, $this->owner, konfirmasi: true));
        $this->assertSame('failed', $this->item($e->hasil, 'draft')['status']);
        $this->assertSame('open', $this->period(1)->status);
    }

    public function test_draft_di_bulan_lain_tidak_menghalangi(): void
    {
        $this->draftTransfer('2026-02-15');
        $this->assertSame('passed', $this->svc()->check($this->period(1))['status']);
    }

    public function test_hpp_tertunda_menggagalkan_penutupan(): void
    {
        $fake = new class extends StockRecalcService {
            public function __construct()
            {
            }

            public function pendingCountUntil(string $tanggal): int
            {
                return 2;
            }
        };
        $this->app->instance(StockRecalcService::class, $fake);
        $this->app->forgetInstance(PeriodCloseService::class);

        $e = $this->expectClose('close_blocked', fn () => $this->svc()->close((int) $this->period(1)->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'hpp_tertunda')['status']);
        $this->assertSame('open', $this->period(1)->status);
    }

    public function test_saldo_ringkas_rusak_menggagalkan_neraca_saldo_dan_kecocokan(): void
    {
        $this->book('2026-01-10', '1-1110', '4-1000', '1000000');
        DB::table('account_balances')->where('company_id', $this->co->id)->where('account_id', $this->acc('1-1110'))->update(['debit' => DB::raw('debit + 500')]);

        $e = $this->expectClose('close_blocked', fn () => $this->svc()->close((int) $this->period(1)->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'neraca_saldo')['status']);
        $this->assertSame('failed', $this->item($e->hasil, 'saldo_cocok')['status']);
        $this->assertSame('open', $this->period(1)->status);

        app(AccountBalanceService::class)->rebuild(); // perbaikan resmi
        $this->assertSame('passed', $this->svc()->check($this->period(1))['status']);
    }

    // ----------------------------------------------------------------------------- tutup tahun

    private function siapkanTahunLabaRugi(): void
    {
        $this->book('2026-02-10', '1-1110', '4-1000', '10000000'); // pendapatan
        $this->book('2026-05-20', '6-2000', '1-1110', '3000000');  // beban sewa
        $this->closeMonths(11);
    }

    public function test_tutup_tahun_menerbitkan_jurnal_penutup_ke_laba_ditahan(): void
    {
        $this->siapkanTahunLabaRugi();

        $pre = $this->fysvc()->preview((int) $this->fy()->id);
        $this->assertSame('passed', $pre['status']);
        $this->assertMoney('7000000', $pre['penutup']['laba_bersih']);
        $this->assertSame(3, $pre['penutup']['jumlah_baris_jurnal']);

        $res = $this->fysvc()->close((int) $this->fy()->id, $this->owner);

        $this->assertNotNull($res['jurnal_penutup']);
        $this->assertTrue($res['tahun_berikutnya_dibuat']);
        $this->assertSame('closed', $this->fy()->status);
        $this->assertSame('closed', $this->period(12)->status);
        $this->assertSame(1, FiscalYear::query()->where('tahun', 2027)->count());

        $j = Journal::query()->findOrFail($res['jurnal_penutup']['id']);
        $this->assertSame('closing', $j->tipe);
        $this->assertTrue((bool) $j->is_closing);
        $this->assertSame('2026-12-31', $j->tanggal->toDateString());
        $this->assertSame('fiscal_year', $j->source_type);

        $line = fn (string $kode) => DB::table('journal_lines')->where(['journal_id' => $j->id, 'account_id' => $this->acc($kode)])->first();
        $this->assertMoney('10000000', $line('4-1000')->debit);   // pendapatan (saldo kredit) didebit
        $this->assertMoney('3000000', $line('6-2000')->kredit);   // beban (saldo debit) dikredit
        $this->assertMoney('7000000', $line('3-2000')->kredit);   // laba ke Laba Ditahan
        $this->assertMoney('10000000', $j->total_debit);
        $this->assertMoney('10000000', $j->total_kredit);

        // saldo ringkas: jurnal penutup masuk kolom *_penutup dan tetap cocok dengan jurnal
        $this->assertMoney('7000000', DB::table('account_balances')->where(['company_id' => $this->co->id, 'account_id' => $this->acc('3-2000')])->sum('kredit_penutup'));
        $this->assertSame([], app(AccountBalanceService::class)->discrepancies());

        // Laba Rugi mengabaikan jurnal penutup: mutasi pendapatan periode 2 tetap 10 juta, periode 12 nol
        $tb12 = app(AccountBalanceService::class)->trialBalance($this->period(12));
        $this->assertTrue($tb12['seimbang']);
        $rev = collect($tb12['rows'])->firstWhere('kode', '4-1000');
        $this->assertNotNull($rev);
        $this->assertMoney('10000000', $rev['awal_kredit']);
        $this->assertMoney('0', $rev['mutasi_debit']);  // jurnal penutup tidak tampil sebagai mutasi pendapatan
        $this->assertMoney('0', $rev['mutasi_kredit']);

        // posting ke tahun 2026 sesudah penutupan ditolak (periode 12 Closed)
        $this->expectException(\App\Modules\Accounting\Periods\PeriodException::class);
        $this->book('2026-12-15', '1-1110', '4-1000', '1000');
    }

    public function test_rugi_didebit_ke_laba_ditahan(): void
    {
        $this->book('2026-03-05', '6-2000', '1-1110', '2500000');
        $this->closeMonths(11);

        $res = $this->fysvc()->close((int) $this->fy()->id, $this->owner);

        $this->assertSame('closed', $this->fy()->status);
        $j = Journal::query()->findOrFail($res['jurnal_penutup']['id']);
        $ld = DB::table('journal_lines')->where(['journal_id' => $j->id, 'account_id' => $this->acc('3-2000')])->first();
        $this->assertMoney('2500000', $ld->debit);
        $this->assertMoney('0', $ld->kredit);
    }

    public function test_tahun_tanpa_transaksi_ditutup_tanpa_jurnal(): void
    {
        $this->closeMonths(11);

        $res = $this->fysvc()->close((int) $this->fy()->id, $this->owner);

        $this->assertNull($res['jurnal_penutup']);
        $this->assertSame('closed', $this->fy()->status);
        $this->assertSame(0, Journal::query()->where('tipe', 'closing')->count());
    }

    public function test_tutup_tahun_ditolak_bila_ada_periode_sebelumnya_terbuka_atau_sudah_ditutup(): void
    {
        $this->closeMonths(5); // bulan 6-11 masih terbuka

        $e = $this->expectClose('close_blocked', fn () => $this->fysvc()->close((int) $this->fy()->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'urutan')['status']);
        $this->assertSame('open', $this->fy()->status);
        $this->assertSame('open', $this->period(12)->status);

        for ($b = 6; $b <= 11; $b++) {
            $this->svc()->close((int) $this->period($b)->id, $this->owner);
        }
        $this->fysvc()->close((int) $this->fy()->id, $this->owner);
        $this->expectClose('year_not_open', fn () => $this->fysvc()->close((int) $this->fy()->id, $this->owner));
    }

    public function test_akun_laba_rugi_nonaktif_atau_laba_ditahan_tidak_dipetakan_menggagalkan(): void
    {
        $this->book('2026-02-10', '1-1110', '4-1000', '1000000');
        $this->closeMonths(11);

        DB::table('accounts')->where('id', $this->acc('4-1000'))->update(['is_aktif' => 0]);
        $e = $this->expectClose('close_blocked', fn () => $this->fysvc()->close((int) $this->fy()->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'akun_laba_rugi_aktif')['status']);
        DB::table('accounts')->where('id', $this->acc('4-1000'))->update(['is_aktif' => 1]);

        DB::table('system_accounts')->where(['company_id' => $this->co->id, 'kunci_akun' => 'laba_ditahan'])->delete();
        $e = $this->expectClose('close_blocked', fn () => $this->fysvc()->close((int) $this->fy()->id, $this->owner));
        $this->assertSame('failed', $this->item($e->hasil, 'akun_laba_ditahan')['status']);

        $this->assertSame('open', $this->fy()->status);
        $this->assertSame(0, Journal::query()->where('tipe', 'closing')->count()); // semua dibatalkan, tak ada jurnal setengah jadi
    }

    // ----------------------------------------------------------------------------- buka kembali

    public function test_buka_kembali_tahun_membalik_jurnal_penutup_dan_bisa_ditutup_lagi(): void
    {
        $this->siapkanTahunLabaRugi();
        $closing = $this->fysvc()->close((int) $this->fy()->id, $this->owner)['jurnal_penutup'];

        $this->expectClose('reason_required', fn () => $this->fysvc()->reopen((int) $this->fy()->id, $this->owner, '  '));

        $res = $this->fysvc()->reopen((int) $this->fy()->id, $this->owner, 'Koreksi beban yang terlewat');

        $this->assertSame('open', $this->fy()->status);
        $this->assertSame('open', $this->period(12)->status);
        $this->assertSame('closed', $this->period(11)->status); // periode 1-11 tetap Closed
        $this->assertCount(1, $res['jurnal_dibalik']);

        $rev = Journal::query()->where('reversed_of_id', $closing['id'])->firstOrFail();
        $this->assertSame('reversal', $rev->tipe);
        $this->assertTrue((bool) $rev->is_closing);
        // saldo penutup Laba Ditahan kembali nol dan tetap cocok dengan jurnal
        $this->assertMoney('0', DB::table('account_balances')->where(['company_id' => $this->co->id, 'account_id' => $this->acc('3-2000')])->selectRaw('SUM(kredit_penutup - debit_penutup) AS s')->value('s'));
        $this->assertSame([], app(AccountBalanceService::class)->discrepancies());

        // tahun yang dibuka bisa ditutup lagi; jurnal penutup baru terbit
        $again = $this->fysvc()->close((int) $this->fy()->id, $this->owner);
        $this->assertNotNull($again['jurnal_penutup']);
        $this->assertNotSame($closing['id'], $again['jurnal_penutup']['id']);
        $this->assertFalse($again['tahun_berikutnya_dibuat']); // 2027 sudah ada
    }

    public function test_buka_kembali_ditolak_bila_tahun_lebih_baru_sudah_ditutup_atau_tahun_belum_ditutup(): void
    {
        $this->expectClose('year_not_closed', fn () => $this->fysvc()->reopen((int) $this->fy()->id, $this->owner, 'uji'));

        $this->closeMonths(11);
        $this->fysvc()->close((int) $this->fy()->id, $this->owner);

        DB::table('fiscal_years')->where(['company_id' => $this->co->id, 'tahun' => 2027])->update(['status' => 'closed']);
        $this->expectClose('later_year_closed', fn () => $this->fysvc()->reopen((int) $this->fy()->id, $this->owner, 'uji'));
        $this->assertSame('closed', $this->fy()->status);
    }

    public function test_jurnal_penutup_tidak_bisa_dibalik_lewat_reverse_biasa(): void
    {
        $this->siapkanTahunLabaRugi();
        $closing = $this->fysvc()->close((int) $this->fy()->id, $this->owner)['jurnal_penutup'];

        try {
            app(JournalService::class)->reverse((int) $closing['id'], 'coba', (int) $this->owner->id);
            $this->fail('Seharusnya ditolak.');
        } catch (JournalException $e) {
            $this->assertStringContainsString('Jurnal penutup', $e->getMessage());
        }
    }

    // ----------------------------------------------------------------------------- lintas perusahaan dan HTTP

    public function test_periode_dan_tahun_perusahaan_lain_tidak_bisa_diakses(): void
    {
        $owner2 = User::factory()->create();
        $co2 = $this->newCompany($owner2);
        $this->as($owner2, $co2);
        $periodeB = (int) AccountingPeriod::query()->where('bulan', 1)->value('id');
        $tahunB = (int) FiscalYear::query()->value('id');

        $this->as($this->owner, $this->co);
        try {
            $this->svc()->close($periodeB, $this->owner);
            $this->fail('Periode perusahaan lain tidak boleh bisa ditutup.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->expectException(ModelNotFoundException::class);
        $this->fysvc()->preview($tahunB);
    }

    public function test_hak_akses_per_role_lewat_http(): void
    {
        $akuntan = $this->member('Akuntan');
        $kasir = $this->member('Kasir');
        $p1 = (int) $this->period(1)->id;
        $fy = (int) $this->fy()->id;
        $h = ['X-Company-Id' => $this->co->public_id];

        Sanctum::actingAs($kasir);
        $this->withHeaders($h)->getJson("/api/v1/periods/{$p1}/close-check")->assertOk(); // lihat checklist (read-only)
        $this->withHeaders($h)->postJson("/api/v1/periods/{$p1}/close")->assertForbidden();
        $this->withHeaders($h)->postJson("/api/v1/fiscal-years/{$fy}/close")->assertForbidden();

        Sanctum::actingAs($akuntan);
        $this->withHeaders($h)->getJson("/api/v1/periods/{$p1}/close-check")->assertOk()->assertJsonPath('data.status', 'passed');
        $this->withHeaders($h)->postJson("/api/v1/periods/{$p1}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withHeaders($h)->getJson("/api/v1/periods/{$p1}/close-logs")->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->postJson("/api/v1/fiscal-years/{$fy}/reopen", ['alasan' => 'x'])->assertForbidden(); // hanya Owner

        Sanctum::actingAs($this->owner);
        $this->withHeaders($h)->postJson("/api/v1/periods/{$p1}/close")->assertStatus(422)->assertJsonPath('code', 'already_closed');
        $p12 = (int) $this->period(12)->id;
        $this->withHeaders($h)->postJson("/api/v1/periods/{$p12}/close")->assertStatus(422)->assertJsonPath('code', 'use_fiscal_year_close');
        $this->withHeaders($h)->getJson("/api/v1/fiscal-years/{$fy}/close-preview")->assertOk()->assertJsonPath('data.status', 'failed'); // bulan 2-11 masih terbuka
        $this->withHeaders($h)->postJson("/api/v1/fiscal-years/{$fy}/close")->assertStatus(422)->assertJsonPath('code', 'close_blocked');
    }
}
