<?php

namespace Tests\Feature\CashBank;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodException;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Documents\BankTransferService;
use App\Modules\CashBank\Documents\CashPaymentService;
use App\Modules\CashBank\Documents\CashReceiptService;
use App\Modules\CashBank\Documents\ExpenseService;
use App\Modules\CashBank\History\BankHistoryService;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Kas & Bank: rekening, Penerimaan, Pembayaran, Pencatatan Beban, Transfer Bank, Void, Histori Bank. */
class CashBankTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private BankAccount $kas;
    private BankAccount $bca;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tax_defaults.seed_on_company_created' => true]); // PPN bawaan dipakai untuk menghitung pajak
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = $this->newCompany($this->owner, true);
        $this->as($this->owner, $this->co);

        $this->kas = app(BankAccountService::class)->create(['account_id' => $this->acc('1-1110'), 'nama_bank' => 'Kas Kecil', 'is_kas' => true], $this->owner);
        $this->bca = app(BankAccountService::class)->create(['account_id' => $this->acc('1-1120'), 'nama_bank' => 'BCA', 'no_rekening' => '123'], $this->owner);
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner, bool $pkp): Company
    {
        return app(CreateCompany::class)->handle($owner, [
            'nama' => 'PT Kas ' . uniqid(), 'is_pkp' => $pkp, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang',
        ]);
    }

    private function as(User $u, ?Company $c = null): void
    {
        $c ??= $this->co;
        $m = CompanyUser::where(['company_id' => $c->id, 'user_id' => $u->id])->firstOrFail();
        app(CurrentCompany::class)->set($c, $m);
        app(\App\Modules\Platform\Access\PermissionChecker::class)->flush();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id'),
        ]);

        return $u;
    }

    private function acc(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private function ppn(): int
    {
        return app(TaxCodeService::class)->resolveByKode('PPN', '2026-11-17')->id;
    }

    private function supplier(): int
    {
        return DB::table('contacts')->insertGetId([
            'company_id' => $this->co->id, 'kode' => 'S-' . uniqid(), 'nama' => 'PT Pemasok', 'is_pemasok' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    private function receipt(array $over = [], ?User $by = null): int
    {
        return (int) app(CashReceiptService::class)->create($over + [
            'tanggal' => '2026-11-17', 'rekening_id' => $this->bca->id,
            'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '5000000']],
        ], $by ?? $this->owner)->id;
    }

    private function payment(array $over = [], ?User $by = null): int
    {
        return (int) app(CashPaymentService::class)->create($over + [
            'tanggal' => '2026-11-17', 'rekening_id' => $this->bca->id,
            'lines' => [['account_id' => $this->acc('6-2000'), 'nominal' => '2000000']],
        ], $by ?? $this->owner)->id;
    }

    private function journalOf(string $docType, int $id): Journal
    {
        return Journal::query()->where(['source_type' => $docType, 'source_id' => $id])->whereNull('reversed_of_id')->orderBy('id')->firstOrFail();
    }

    private function line(Journal $j, string $kode): ?object
    {
        return DB::table('journal_lines')->where(['journal_id' => $j->id, 'account_id' => $this->acc($kode)])->first();
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "Diharapkan {$expected}, didapat {$actual}");
    }

    private function assertBalanced(Journal $j): void
    {
        $r = DB::table('journal_lines')->where('journal_id', $j->id)->selectRaw('SUM(debit) d, SUM(kredit) k')->first();
        $this->assertSame(0, bccomp((string) $r->d, (string) $r->k, 4), 'Jurnal tidak seimbang.');
    }

    // ----------------------------------------------------------------------------- rekening

    public function test_rekening_harus_akun_kas_bank_yang_bisa_dipakai_dan_unik(): void
    {
        $svc = app(BankAccountService::class);

        foreach ([
            ['6-2000', 'account_not_cash_bank'],   // bukan kas/bank
            ['1-1100', 'account_not_postable'],    // akun induk
            ['1-1120', 'bank_account_exists'],     // sudah terdaftar
        ] as [$kode, $code]) {
            try {
                $svc->create(['account_id' => $this->acc($kode)], $this->owner);
                $this->fail("Seharusnya ditolak: {$kode}");
            } catch (CashBankException $e) {
                $this->assertSame($code, $e->errorCode, $kode);
            }
        }
    }

    public function test_rekening_bisa_membuat_akun_baru_sekaligus(): void
    {
        $bank = app(BankAccountService::class)->create(['akun_baru' => ['kode' => '1-1130', 'nama' => 'Bank Mandiri', 'parent_id' => $this->acc('1-1100')], 'nama_bank' => 'Mandiri'], $this->owner);

        $a = Account::query()->findOrFail($bank->account_id);
        $this->assertSame('cash_bank', $a->sub_tipe);
        $this->assertSame('asset', $a->tipe);
    }

    public function test_rekening_yang_sudah_punya_jurnal_tidak_bisa_dihapus_tapi_yang_belum_bisa(): void
    {
        $this->orch()->post('cash_receipt', $this->receipt(), $this->owner);

        try {
            app(BankAccountService::class)->delete($this->bca);
            $this->fail('Seharusnya ditolak.');
        } catch (CashBankException $e) {
            $this->assertSame('bank_account_used', $e->errorCode);
        }

        app(BankAccountService::class)->delete($this->kas);
        $this->assertDatabaseMissing('bank_accounts', ['id' => $this->kas->id]);
    }

    public function test_rekening_nonaktif_atau_milik_perusahaan_lain_ditolak_dokumen(): void
    {
        app(BankAccountService::class)->update($this->kas, ['is_aktif' => false], $this->owner);
        try {
            $this->receipt(['rekening_id' => $this->kas->id]);
            $this->fail('Seharusnya ditolak.');
        } catch (CashBankException $e) {
            $this->assertSame('bank_account_inactive', $e->errorCode);
        }

        // rekening perusahaan B tidak terlihat dari perusahaan A (global scope), jadi dianggap tidak ada
        $other = User::factory()->create();
        $coB = $this->newCompany($other, true);
        $this->as($other, $coB);
        $bankB = app(BankAccountService::class)->create(['account_id' => $this->acc('1-1120')], $other);
        $this->as($this->owner, $this->co);

        try {
            $this->receipt(['rekening_id' => $bankB->id]);
            $this->fail('Seharusnya ditolak.');
        } catch (CashBankException $e) {
            $this->assertSame('bank_account_not_found', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- penerimaan

    public function test_penerimaan_tanpa_pajak_menjurnal_bank_debit_dan_akun_kredit(): void
    {
        $id = $this->receipt();
        $r = $this->orch()->post('cash_receipt', $id, $this->owner);

        $this->assertSame('KM/2026/11/00001', $r->nomor);
        $doc = app(CashReceiptService::class)->find($id);
        $this->assertSame('posted', $doc->status);
        $this->assertSame('KM/2026/11/00001', $doc->nomor);
        $this->assertSame($r->journalIds[0], (int) $doc->journal_id);

        $j = $this->journalOf('cash_receipt', $id);
        $this->assertMoney('5000000', $this->line($j, '1-1120')->debit);
        $this->assertMoney('5000000', $this->line($j, '7-2000')->kredit);
        $this->assertBalanced($j);
    }

    public function test_penerimaan_dengan_ppn_membuat_ppn_keluaran_dan_rincian_pajak(): void
    {
        $id = $this->receipt(['lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '10000000', 'tax_code_id' => $this->ppn()]]]);
        $this->assertMoney('11100000', app(CashReceiptService::class)->find($id)->total); // total sudah dihitung di Draft

        $this->orch()->post('cash_receipt', $id, $this->owner);

        $j = $this->journalOf('cash_receipt', $id);
        $this->assertMoney('11100000', $this->line($j, '1-1120')->debit);
        $this->assertMoney('10000000', $this->line($j, '7-2000')->kredit);
        $this->assertMoney('1100000', $this->line($j, '2-1300')->kredit);
        $this->assertBalanced($j);

        $dt = DB::table('document_taxes')->where(['doc_type' => 'cash_receipt', 'doc_id' => $id])->first();
        $this->assertNotNull($dt);
        $this->assertMoney('1100000', $dt->nominal);
    }

    public function test_pembulatan_pajak_masuk_akun_pembulatan_dan_jurnal_tetap_seimbang(): void
    {
        $id = $this->receipt(['lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '1000.50', 'tax_code_id' => $this->ppn()]]]);
        $this->orch()->post('cash_receipt', $id, $this->owner);

        $j = $this->journalOf('cash_receipt', $id);
        $this->assertMoney('1111', $this->line($j, '1-1120')->debit);
        $this->assertMoney('0.5', $this->line($j, '7-5100')->kredit); // pembulatan
        $this->assertBalanced($j);
    }

    public function test_non_pkp_tidak_memungut_ppn_walau_baris_bertanda_pajak(): void
    {
        $u = User::factory()->create();
        $coN = $this->newCompany($u, false);
        $this->as($u, $coN);
        $bank = app(BankAccountService::class)->create(['account_id' => $this->acc('1-1120')], $u);
        $ppn = app(TaxCodeService::class)->resolveByKode('PPN', '2026-11-17')->id;

        $id = (int) app(CashReceiptService::class)->create([
            'tanggal' => '2026-11-17', 'rekening_id' => $bank->id,
            'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '10000000', 'tax_code_id' => $ppn]],
        ], $u)->id;
        $this->orch()->post('cash_receipt', $id, $u);

        $j = $this->journalOf('cash_receipt', $id);
        $this->assertMoney('10000000', $this->line($j, '1-1120')->debit);
        $this->assertNull($this->line($j, '2-1300'));
    }

    // ----------------------------------------------------------------------------- pembayaran

    public function test_pembayaran_dengan_ppn_masukan_dikreditkan_butuh_faktur_pajak(): void
    {
        $lines = [['account_id' => $this->acc('6-2000'), 'nominal' => '10000000', 'tax_code_id' => $this->ppn()]];

        try {
            $this->payment(['lines' => $lines]);
            $this->fail('Seharusnya ditolak.');
        } catch (CashBankException $e) {
            $this->assertSame('faktur_pajak_required', $e->errorCode);
        }

        $id = $this->payment(['lines' => $lines, 'no_faktur_pajak' => '010.000-26.00000001']);
        $this->orch()->post('cash_payment', $id, $this->owner);

        $j = $this->journalOf('cash_payment', $id);
        $this->assertSame('KK/2026/11/00001', app(CashPaymentService::class)->find($id)->nomor);
        $this->assertMoney('10000000', $this->line($j, '6-2000')->debit);
        $this->assertMoney('1100000', $this->line($j, '1-1500')->debit);   // PPN Masukan
        $this->assertMoney('11100000', $this->line($j, '1-1120')->kredit);
        $this->assertBalanced($j);
    }

    public function test_ppn_masukan_tidak_dikreditkan_masuk_biaya_baris(): void
    {
        $id = $this->payment([
            'lines' => [['account_id' => $this->acc('6-2000'), 'nominal' => '10000000', 'tax_code_id' => $this->ppn()]],
            'dapat_dikreditkan' => false,
        ]);
        $this->orch()->post('cash_payment', $id, $this->owner);

        $j = $this->journalOf('cash_payment', $id);
        $this->assertMoney('11100000', $this->line($j, '6-2000')->debit);
        $this->assertNull($this->line($j, '1-1500'));
        $this->assertMoney('11100000', $this->line($j, '1-1120')->kredit);
        $this->assertBalanced($j);
    }

    public function test_akun_baris_tidak_boleh_sama_dengan_akun_rekening_dan_akun_induk_ditolak(): void
    {
        foreach ([['1-1120', 'account_same_as_bank'], ['6-0000', 'account_not_postable']] as [$kode, $code]) {
            try {
                $this->payment(['lines' => [['account_id' => $this->acc($kode), 'nominal' => '1000']]]);
                $this->fail("Seharusnya ditolak: {$kode}");
            } catch (CashBankException $e) {
                $this->assertSame($code, $e->errorCode, $kode);
            }
        }
    }

    // ----------------------------------------------------------------------------- beban

    public function test_beban_dibayar_kas_bank_dan_beban_utang(): void
    {
        $svc = app(ExpenseService::class);

        $a = (int) $svc->create([
            'tanggal' => '2026-11-17', 'rekening_id' => $this->kas->id,
            'lines' => [['account_id' => $this->acc('6-2100'), 'nominal' => '350000', 'deskripsi' => 'Listrik']],
        ], $this->owner)->id;
        $this->orch()->post('expense', $a, $this->owner);
        $ja = $this->journalOf('expense', $a);
        $this->assertSame('EXP/2026/11/00001', $svc->find($a)->nomor);
        $this->assertMoney('350000', $this->line($ja, '6-2100')->debit);
        $this->assertMoney('350000', $this->line($ja, '1-1110')->kredit);

        $sid = $this->supplier();
        $b = (int) $svc->create([
            'tanggal' => '2026-11-17', 'jenis_pembayaran' => 'utang', 'contact_id' => $sid,
            'lines' => [['account_id' => $this->acc('6-2000'), 'nominal' => '900000']],
        ], $this->owner)->id;
        $this->assertNull($svc->find($b)->rekening_pembayaran_id);
        $this->orch()->post('expense', $b, $this->owner);

        $jb = $this->journalOf('expense', $b);
        $utang = $this->line($jb, '2-1100');
        $this->assertMoney('900000', $utang->kredit);
        $this->assertSame($sid, (int) $utang->contact_id); // subledger utang
        $this->assertBalanced($jb);
    }

    public function test_beban_utang_wajib_kontak_dan_beban_kas_wajib_rekening(): void
    {
        $line = [['account_id' => $this->acc('6-2000'), 'nominal' => '1000']];
        foreach ([
            [['jenis_pembayaran' => 'utang'], 'contact_required'],
            [['jenis_pembayaran' => 'kas_bank'], 'bank_required'],
        ] as [$over, $code]) {
            try {
                app(ExpenseService::class)->create($over + ['tanggal' => '2026-11-17', 'lines' => $line], $this->owner);
                $this->fail('Seharusnya ditolak.');
            } catch (CashBankException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    // ----------------------------------------------------------------------------- transfer

    public function test_transfer_dengan_biaya_admin(): void
    {
        $svc = app(BankTransferService::class);
        $id = (int) $svc->create([
            'tanggal' => '2026-11-17', 'rekening_asal_id' => $this->bca->id, 'rekening_tujuan_id' => $this->kas->id,
            'nominal' => '1000000', 'biaya_admin' => '6500',
        ], $this->owner)->id;

        $r = $this->orch()->post('bank_transfer', $id, $this->owner);
        $this->assertSame('TB/2026/11/00001', $r->nomor);

        $j = $this->journalOf('bank_transfer', $id);
        $this->assertMoney('1000000', $this->line($j, '1-1110')->debit);   // tujuan
        $this->assertMoney('6500', $this->line($j, '6-4000')->debit);      // beban admin bank
        $this->assertMoney('1006500', $this->line($j, '1-1120')->kredit);  // asal
        $this->assertBalanced($j);
    }

    public function test_transfer_tanpa_biaya_tidak_membuat_baris_beban_dan_rekening_sama_ditolak(): void
    {
        $svc = app(BankTransferService::class);
        $id = (int) $svc->create([
            'tanggal' => '2026-11-17', 'rekening_asal_id' => $this->bca->id, 'rekening_tujuan_id' => $this->kas->id, 'nominal' => '250000',
        ], $this->owner)->id;
        $this->orch()->post('bank_transfer', $id, $this->owner);
        $this->assertNull($this->line($this->journalOf('bank_transfer', $id), '6-4000'));

        try {
            $svc->create(['tanggal' => '2026-11-17', 'rekening_asal_id' => $this->bca->id, 'rekening_tujuan_id' => $this->bca->id, 'nominal' => '1'], $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (CashBankException $e) {
            $this->assertSame('same_bank_account', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- siklus draft

    public function test_hanya_draft_yang_bisa_diubah_dihapus_dan_locking_optimistik(): void
    {
        $svc = app(CashReceiptService::class);
        $id = $this->receipt();

        $upd = $svc->update($id, [
            'tanggal' => '2026-11-18', 'rekening_id' => $this->bca->id, 'version' => 1,
            'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '7000000'], ['account_id' => $this->acc('4-1000'), 'nominal' => '1000000']],
        ], $this->owner);
        $this->assertSame(2, (int) $upd->version);
        $this->assertCount(2, $upd->lines);
        $this->assertMoney('8000000', $upd->total);

        try { // versi basi: orang lain sudah mengubah
            $svc->update($id, ['tanggal' => '2026-11-18', 'rekening_id' => $this->bca->id, 'version' => 1, 'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '1']]], $this->owner);
            $this->fail('Seharusnya konflik.');
        } catch (CashBankException $e) {
            $this->assertSame('edit_conflict', $e->errorCode);
        }

        $this->orch()->post('cash_receipt', $id, $this->owner);
        foreach ([
            fn () => $svc->update($id, ['tanggal' => '2026-11-18', 'rekening_id' => $this->bca->id, 'lines' => [['account_id' => $this->acc('7-2000'), 'nominal' => '1']]], $this->owner),
            fn () => $svc->delete($id, $this->owner),
        ] as $try) {
            try {
                $try();
                $this->fail('Dokumen Posted harus read-only.');
            } catch (CashBankException $e) {
                $this->assertSame('not_draft', $e->errorCode);
            }
        }

        $draft = $this->receipt();
        $svc->delete($draft, $this->owner);
        $this->assertDatabaseMissing('cash_receipts', ['id' => $draft]);
        $this->assertDatabaseMissing('cash_receipts_lines', ['cash_receipt_id' => $draft]);
    }

    public function test_draft_yang_periodenya_dikunci_kemudian_tidak_bisa_diposting(): void
    {
        $id = $this->receipt();
        $p = app(PeriodService::class)->periodFor('2026-11-17');
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($p->id), $this->owner);

        $this->expectException(PeriodException::class);
        try {
            $this->orch()->post('cash_receipt', $id, $this->owner);
        } finally {
            $this->assertSame(0, DB::table('journals')->count());
        }
    }

    public function test_draft_baru_di_periode_terkunci_ditolak(): void
    {
        $p = app(PeriodService::class)->periodFor('2026-11-17');
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($p->id), $this->owner);

        $this->expectException(PeriodException::class);
        $this->receipt();
    }

    // ----------------------------------------------------------------------------- void

    public function test_void_membalik_jurnal_dan_nomor_tidak_dipakai_ulang(): void
    {
        $id = $this->receipt();
        $this->orch()->post('cash_receipt', $id, $this->owner);

        $this->orch()->void('cash_receipt', $id, 'Salah input', $this->owner);

        $doc = app(CashReceiptService::class)->find($id);
        $this->assertSame('void', $doc->status);
        $this->assertSame('Salah input', $doc->alasan_void);
        $this->assertTrue(Journal::query()->where('reversed_of_id', $doc->journal_id)->exists());
        $this->assertMoney('0', app(BankHistoryService::class)->balances()['total']);

        $next = $this->receipt();
        $this->assertSame('KM/2026/11/00002', $this->orch()->post('cash_receipt', $next, $this->owner)->nomor);
    }

    public function test_void_ditolak_bila_baris_bank_sudah_direkonsiliasi(): void
    {
        $id = $this->receipt();
        $this->orch()->post('cash_receipt', $id, $this->owner);

        $j = $this->journalOf('cash_receipt', $id);
        $jl = $this->line($j, '1-1120');
        $recId = DB::table('bank_reconciliations')->insertGetId([
            'company_id' => $this->co->id, 'bank_account_id' => $this->bca->id, 'periode_mulai' => '2026-11-01', 'periode_selesai' => '2026-11-30',
            'saldo_koran' => 5000000, 'saldo_buku' => 5000000, 'selisih' => 0, 'status' => 'berjalan',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reconciliation_items')->insert([
            'company_id' => $this->co->id, 'bank_reconciliation_id' => $recId, 'journal_line_id' => $jl->id,
            'tipe_cocok' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(PostingException::class);
        try {
            $this->orch()->void('cash_receipt', $id, 'alasan', $this->owner);
        } finally {
            $this->assertSame('posted', app(CashReceiptService::class)->find($id)->status);
            $this->assertSame(1, DB::table('journals')->count()); // tidak ada jurnal pembalik yang bocor
        }
    }

    // ----------------------------------------------------------------------------- histori bank

    public function test_histori_bank_menggabungkan_semua_dokumen_dengan_saldo_berjalan(): void
    {
        $this->orch()->post('cash_receipt', $this->receipt(), $this->owner);                        // +5.000.000
        $this->orch()->post('cash_payment', $this->payment(), $this->owner);                        // -2.000.000
        $t = (int) app(BankTransferService::class)->create([
            'tanggal' => '2026-11-17', 'rekening_asal_id' => $this->bca->id, 'rekening_tujuan_id' => $this->kas->id,
            'nominal' => '1000000', 'biaya_admin' => '6500',
        ], $this->owner)->id;
        $this->orch()->post('bank_transfer', $t, $this->owner);                                      // -1.006.500

        $h = app(BankHistoryService::class)->history($this->bca->id, '2026-11-01', '2026-11-30');

        $this->assertCount(3, $h['rows']);
        $this->assertMoney('0', $h['saldo_awal']);
        $this->assertMoney('1993500', $h['saldo_akhir']);
        $this->assertMoney('1993500', $h['rows']->last()['saldo']);
        $this->assertSame(['Penerimaan Kas/Bank', 'Pembayaran Kas/Bank', 'Transfer Bank'], $h['rows']->pluck('jenis_dokumen')->all());
    }

    public function test_saldo_semua_rekening_menjumlahkan_bank_dan_kas(): void
    {
        $this->orch()->post('cash_receipt', $this->receipt(), $this->owner);                        // BCA +5.000.000
        $t = (int) app(BankTransferService::class)->create([
            'tanggal' => '2026-11-17', 'rekening_asal_id' => $this->bca->id, 'rekening_tujuan_id' => $this->kas->id,
            'nominal' => '1000000', 'biaya_admin' => '6500',
        ], $this->owner)->id;
        $this->orch()->post('bank_transfer', $t, $this->owner);                                      // BCA -1.006.500, Kas +1.000.000

        $b = app(BankHistoryService::class)->balances('2026-11-30');
        $saldo = collect($b['rekening'])->pluck('saldo', 'nama_bank');

        $this->assertMoney('3993500', $saldo['BCA']);
        $this->assertMoney('1000000', $saldo['Kas Kecil']);
        $this->assertMoney('4993500', $b['total']); // turun hanya sebesar biaya admin
    }

    // ----------------------------------------------------------------------------- hak akses dan HTTP

    public function test_kasir_hanya_memposting_dokumen_miliknya_dan_tidak_boleh_void(): void
    {
        $kasir = $this->member('Kasir');
        $this->as($kasir);

        $milikOwner = $this->receipt([], $this->owner);
        try {
            $this->orch()->post('cash_receipt', $milikOwner, $kasir);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }

        $milikSendiri = $this->receipt([], $kasir);
        $this->orch()->post('cash_receipt', $milikSendiri, $kasir);

        try {
            $this->orch()->void('cash_receipt', $milikSendiri, 'x', $kasir);
            $this->fail('Kasir tidak punya hak void.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
    }

    public function test_alur_http_penerimaan_dari_draft_sampai_posting_dan_histori(): void
    {
        $h = ['X-Company-Id' => $this->co->public_id];
        $akunBunga = $this->acc('7-2000'); // dihitung sebelum request: konteks perusahaan dibersihkan setelah tiap request HTTP
        Sanctum::actingAs($this->owner);

        $res = $this->withHeaders($h)->postJson('/api/v1/cash-receipts', [
            'tanggal' => '2026-11-17', 'rekening_id' => $this->bca->id, 'memo' => 'Bunga bank',
            'lines' => [['account_id' => $akunBunga, 'nominal' => '125000']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.nomor', null);
        $id = $res->json('data.id');

        $this->withHeaders($h)->postJson("/api/v1/documents/cash_receipt/{$id}/post")
            ->assertOk()->assertJsonPath('data.nomor', 'KM/2026/11/00001');

        $this->withHeaders($h)->getJson("/api/v1/cash-receipts/{$id}")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->withHeaders($h)->getJson('/api/v1/cash-receipts?status=posted')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->getJson("/api/v1/reports/bank-history/{$this->bca->id}?dari=2026-11-01&sampai=2026-11-30")
            ->assertOk()->assertJsonPath('data.saldo_akhir', '125000.0000');

        $this->withHeaders($h)->postJson("/api/v1/documents/cash_receipt/{$id}/void", ['alasan' => 'uji'])->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_kasir_melihat_daftar_rekening_tanpa_saldo_tapi_tidak_histori_bank(): void
    {
        $kasir = $this->member('Kasir');
        $h = ['X-Company-Id' => $this->co->public_id];
        $akunKas = $this->acc('1-1110'); // dihitung sebelum request: konteks perusahaan dibersihkan setelah tiap request HTTP
        Sanctum::actingAs($kasir);

        $res = $this->withHeaders($h)->getJson('/api/v1/bank-accounts')->assertOk();
        $this->assertCount(2, $res->json('data.rekening'));
        $this->assertArrayNotHasKey('saldo', $res->json('data.rekening.0'));
        $this->assertArrayNotHasKey('total', $res->json('data'));

        $this->withHeaders($h)->getJson("/api/v1/reports/bank-history/{$this->bca->id}?dari=2026-11-01&sampai=2026-11-30")->assertForbidden();
        $this->withHeaders($h)->postJson('/api/v1/bank-accounts', ['account_id' => $akunKas])->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/expenses')->assertForbidden(); // Kasir tanpa akses Pencatatan Beban
    }
}
