<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\ChartOfAccounts\AccountException;
use App\Modules\Accounting\ChartOfAccounts\AccountService;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountMissing;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
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

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
    }

    private function company(string $template = 'dagang', ?User $owner = null): Company
    {
        return app(CreateCompany::class)->handle($owner ?? $this->owner, [
            'nama' => 'PT ' . $template, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => $template,
        ]);
    }

    private function in(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, $fn);
    }

    public function test_template_dagang_memetakan_akun_sistem(): void
    {
        $c = $this->company('dagang');

        $this->in($c, function () {
            $sys = app(SystemAccountService::class);
            $this->assertSame('1-1200', $sys->resolve('piutang_usaha')->kode);
            $this->assertSame('5-1000', $sys->resolve('hpp')->kode);
            $this->assertSame('2-1300', $sys->resolve('ppn_keluaran')->kode);
            $this->assertSame('credit', Account::where('kode', '1-2900')->value('saldo_normal'));  // akun kontra
            $this->assertSame('debit', Account::where('kode', '4-1200')->value('saldo_normal'));   // retur penjualan
            $this->assertSame('1-0000', Account::where('kode', '1-1000')->first()->parent->kode);
        });
    }

    public function test_template_jasa_tanpa_persediaan_dan_manufaktur_dengan_wip(): void
    {
        $jasa = $this->company('jasa');
        $this->in($jasa, function () {
            $this->assertNull(Account::where('kode', '1-1300')->first());
            $this->assertSame('Pendapatan Jasa', app(SystemAccountService::class)->resolve('penjualan')->nama);
            $this->expectException(SystemAccountMissing::class);
            app(SystemAccountService::class)->resolve('persediaan');
        });
    }

    public function test_template_manufaktur_punya_barang_dalam_proses(): void
    {
        $m = $this->company('manufaktur');
        $this->in($m, fn () => $this->assertSame('1-1320', app(SystemAccountService::class)->resolve('barang_dalam_proses')->kode));
    }

    public function test_pohon_akun_valid(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            foreach (Account::with('parent')->get() as $a) {
                if ($a->parent) {
                    $this->assertTrue($a->parent->is_header, "{$a->kode} berinduk non-header");
                    $this->assertSame($a->parent->tipe, $a->tipe, "{$a->kode} beda tipe dengan induknya");
                }
                if (! in_array($a->kode, ['1-2900', '4-1100', '4-1200'], true)) { // selain akun kontra
                    $this->assertSame(Account::normalBalanceOf($a->tipe), $a->saldo_normal, "saldo normal {$a->kode}");
                }
            }
        });
    }

    public function test_aturan_induk_dan_kode(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            $svc = app(AccountService::class);
            $header = Account::where('kode', '1-1100')->first();
            $detail = Account::where('kode', '1-1110')->first();

            $baru = $svc->create(['kode' => '1-1130', 'nama' => 'Kas Kecil', 'tipe' => 'asset', 'parent_id' => $header->id]);
            $this->assertSame('debit', $baru->saldo_normal);

            try { $svc->create(['kode' => '1-1130', 'nama' => 'Dobel', 'tipe' => 'asset']); $this->fail(); } catch (AccountException $e) { $this->assertStringContainsString('sudah dipakai', $e->getMessage()); }
            try { $svc->create(['kode' => '1-1140', 'nama' => 'X', 'tipe' => 'asset', 'parent_id' => $detail->id]); $this->fail(); } catch (AccountException $e) { $this->assertStringContainsString('header', $e->getMessage()); }
            try { $svc->create(['kode' => '1-1150', 'nama' => 'X', 'tipe' => 'expense', 'parent_id' => $header->id]); $this->fail(); } catch (AccountException $e) { $this->assertStringContainsString('tipe sama', $e->getMessage()); }
        });
    }

    public function test_siklus_induk_ditolak(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            $svc = app(AccountService::class);
            $lancar = Account::where('kode', '1-1000')->first();
            $kasBank = Account::where('kode', '1-1100')->first(); // anak dari 1-1000, juga header

            $this->expectException(AccountException::class);
            $svc->update($lancar, ['parent_id' => $kasBank->id]);
        });
    }

    public function test_akun_sistem_tidak_bisa_dihapus_dinonaktifkan_atau_diubah_tipe(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            $svc = app(AccountService::class);
            $piutang = Account::where('kode', '1-1200')->first();

            foreach ([fn () => $svc->delete($piutang), fn () => $svc->update($piutang, ['is_aktif' => false]), fn () => $svc->update($piutang, ['tipe' => 'expense'])] as $aksi) {
                try { $aksi(); $this->fail('Seharusnya ditolak'); } catch (AccountException) { $this->assertTrue(true); }
            }
        });
    }

    public function test_akun_bertransaksi_tidak_bisa_dihapus_atau_jadi_induk(): void
    {
        $c = $this->company();

        $this->in($c, function () use ($c) {
            $svc = app(AccountService::class);
            $kas = Account::where('kode', '1-1110')->first();

            DB::table('opening_balances')->insert([
                'company_id' => $c->id, 'account_id' => $kas->id, 'tanggal' => '2026-01-01', 'jumlah' => 1000, 'created_at' => now(),
            ]);

            $this->assertTrue($svc->hasTransactions($kas));
            try { $svc->delete($kas); $this->fail(); } catch (AccountException) {}
            try { $svc->update($kas, ['is_header' => true]); $this->fail(); } catch (AccountException) {}

            $kosong = $svc->create(['kode' => '1-1190', 'nama' => 'Kosong', 'tipe' => 'asset']);
            $svc->delete($kosong);
            $this->assertNull(Account::where('kode', '1-1190')->first());
        });
    }

    public function test_pemetaan_ulang_akun_sistem_memvalidasi_tipe(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            $sys = app(SystemAccountService::class);
            $svc = app(AccountService::class);

            $bank2 = $svc->create(['kode' => '1-1200-A', 'nama' => 'Piutang Lain', 'tipe' => 'asset']);
            $sys->map('piutang_usaha', $bank2->id);
            $this->assertSame('1-1200-A', $sys->resolve('piutang_usaha')->kode);

            $beban = Account::where('kode', '6-2000')->first();
            try { $sys->map('piutang_usaha', $beban->id); $this->fail(); } catch (AccountException $e) { $this->assertStringContainsString('asset', $e->getMessage()); }

            $header = Account::where('kode', '1-1000')->first();
            try { $sys->map('piutang_usaha', $header->id); $this->fail(); } catch (AccountException $e) { $this->assertStringContainsString('header', $e->getMessage()); }
        });
    }

    public function test_akun_tidak_bocor_antar_perusahaan(): void
    {
        $a = $this->company('dagang');
        $b = $this->company('jasa', User::factory()->create());

        $idB = $this->in($b, fn () => Account::where('kode', '1-1200')->value('id'));

        $this->in($a, function () use ($idB) {
            $this->assertNull(Account::find($idB));
            $this->expectException(AccountException::class);
            app(SystemAccountService::class)->map('piutang_usaha', $idB); // akun milik perusahaan lain
        });
    }

    public function test_api_pohon_hak_akses_dan_validasi(): void
    {
        $c = $this->company();
        $h = ['X-Company-Id' => $c->public_id];

        Sanctum::actingAs($this->owner);
        $tree = $this->getJson('/api/v1/accounts?tree=1', $h)->assertOk()->json('data');
        $this->assertCount(8, $tree); // 1-0000 .. 7-0000 dan 7-5000
        $this->assertSame('ASET', $tree[0]['nama']);

        $this->postJson('/api/v1/accounts', ['kode' => '1-1200', 'nama' => 'Dobel', 'tipe' => 'asset'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'account_rule');
        $this->postJson('/api/v1/accounts', ['kode' => '1-1140', 'nama' => 'Kas Toko', 'tipe' => 'asset'], $h)->assertCreated();
        $this->getJson('/api/v1/system-accounts', $h)->assertOk()->assertJsonPath('data.0.kunci_akun', 'piutang_usaha');

        $akuntan = User::factory()->create();
        CompanyUser::create(['company_id' => $c->id, 'user_id' => $akuntan->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', 'Akuntan')->value('id')]);
        Sanctum::actingAs($akuntan);
        $this->postJson('/api/v1/accounts', ['kode' => '1-1141', 'nama' => 'Kas Toko 2', 'tipe' => 'asset'], $h)->assertCreated();
        $this->putJson('/api/v1/system-accounts/hpp', ['account_id' => 1], $h)->assertForbidden(); // bukan hak Akuntan

        $kasir = User::factory()->create();
        CompanyUser::create(['company_id' => $c->id, 'user_id' => $kasir->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', 'Kasir')->value('id')]);
        Sanctum::actingAs($kasir);
        $this->getJson('/api/v1/accounts', $h)->assertForbidden();
    }
}
