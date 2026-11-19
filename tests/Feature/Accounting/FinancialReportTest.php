<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 6B: Neraca, Laba Rugi, Buku Besar, perbandingan periode, mode ringkas/rinci, izin, isolasi tenant. */
class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
        $this->co = $this->makeCompany($this->owner);
    }

    private function makeCompany(User $owner, string $nama = 'PT Laporan'): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => $nama, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($this->co, $fn);
    }

    private function acc(string $kode): int
    {
        return $this->in(fn () => Account::query()->where('kode', $kode)->value('id'));
    }

    private function h(?Company $c = null): array
    {
        return ['X-Company-Id' => ($c ?? $this->co)->public_id];
    }

    private function periodId(string $date): int
    {
        return $this->in(fn () => app(PeriodService::class)->periodFor($date)->id);
    }

    private function line(string $kode, string $sisi, string $nominal): array
    {
        return ['account_id' => $this->acc($kode), $sisi => $nominal];
    }

    private function jurnal(string $tanggal, array $lines): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/journals', ['tanggal' => $tanggal, 'memo' => 'Uji', 'lines' => $lines], $this->h())->assertCreated();
    }

    /** Maret 2026: modal 5.000.000, penjualan 1.000.000, beban sewa 300.000 => laba 700.000. */
    private function transaksiMaret(): void
    {
        $this->jurnal('2026-03-01', [$this->line('1-1110', 'debit', '5000000'), $this->line('3-1000', 'kredit', '5000000')]);
        $this->jurnal('2026-03-10', [$this->line('1-1110', 'debit', '1000000'), $this->line('4-1000', 'kredit', '1000000')]);
        $this->jurnal('2026-03-12', [$this->line('6-2000', 'debit', '300000'), $this->line('1-1110', 'kredit', '300000')]);
    }

    private function row(array $section, ?string $kode = null, ?string $nama = null): ?array
    {
        return collect($section['rows'])->first(fn ($r) => ($kode !== null && $r['kode'] === $kode) || ($nama !== null && $r['nama'] === $nama));
    }

    private function api(string $path, ?array $headers = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/' . $path, $headers ?? $this->h());
    }

    // ---------------------------------------------------------------- Neraca

    public function test_neraca_seimbang_dengan_laba_berjalan_sebelum_tutup(): void
    {
        $this->transaksiMaret();
        $p = $this->periodId('2026-03-15');

        $bs = $this->api("reports/balance-sheet?period_id={$p}")->assertOk()->json('data');

        $this->assertSame('5700000.0000', $bs['aset']['total']['jumlah']);
        $this->assertSame('0.0000', $bs['kewajiban']['total']['jumlah']);
        $this->assertSame('5700000.0000', $bs['ekuitas']['total']['jumlah']);
        $this->assertTrue($bs['seimbang']);
        $this->assertSame('0.0000', $bs['selisih']);
        $this->assertSame('5000000.0000', $this->row($bs['ekuitas'], '3-1000')['jumlah']);
        $this->assertSame('700000.0000', $this->row($bs['ekuitas'], null, 'Laba Berjalan (belum ditutup)')['jumlah']);
    }

    public function test_neraca_tetap_seimbang_setelah_jurnal_penutup_dan_laba_rugi_mengabaikannya(): void
    {
        $this->transaksiMaret();
        $this->in(fn () => app(JournalService::class)->post(new JournalDraft(
            tanggal: '2026-03-31', tipe: 'closing', lines: [
                ['account_id' => $this->acc('4-1000'), 'debit' => '1000000'],
                ['account_id' => $this->acc('6-2000'), 'kredit' => '300000'],
                ['account_id' => $this->acc('3-2000'), 'kredit' => '700000'],
            ], sourceType: 'fiscal_year', userId: $this->owner->id,
        )));
        $p = $this->periodId('2026-03-15');
        Sanctum::actingAs($this->owner);

        $bs = $this->api("reports/balance-sheet?period_id={$p}")->assertOk()->json('data');
        $this->assertTrue($bs['seimbang']);
        $this->assertSame('5700000.0000', $bs['ekuitas']['total']['jumlah']);
        $this->assertSame('700000.0000', $this->row($bs['ekuitas'], '3-2000')['jumlah']); // pindah ke Laba Ditahan
        $this->assertNull($this->row($bs['ekuitas'], null, 'Laba Berjalan (belum ditutup)')); // nol, tidak ditampilkan

        $lr = $this->api("reports/income-statement?dari_period_id={$p}")->assertOk()->json('data');
        $this->assertSame('1000000.0000', $lr['pendapatan']['total']['jumlah']);
        $this->assertSame('700000.0000', $lr['laba_bersih']['jumlah']);
    }

    // ---------------------------------------------------------------- Laba Rugi

    public function test_laba_rugi_semua_bagian_dan_laba_bersih(): void
    {
        $this->transaksiMaret();
        $this->jurnal('2026-03-20', [$this->line('1-1110', 'debit', '20000'), $this->line('7-2000', 'kredit', '20000')]);  // pendapatan lain
        $this->jurnal('2026-03-21', [$this->line('7-5200', 'debit', '50000'), $this->line('1-1110', 'kredit', '50000')]);  // beban lain
        $p = $this->periodId('2026-03-15');

        $lr = $this->api("reports/income-statement?dari_period_id={$p}")->assertOk()->json('data');

        $this->assertSame('1000000.0000', $lr['pendapatan']['total']['jumlah']);
        $this->assertSame('0.0000', $lr['hpp']['total']['jumlah']);
        $this->assertSame('1000000.0000', $lr['laba_kotor']['jumlah']);
        $this->assertSame('300000.0000', $lr['beban_operasional']['total']['jumlah']);
        $this->assertSame('700000.0000', $lr['laba_usaha']['jumlah']);
        $this->assertSame('20000.0000', $lr['pendapatan_lain']['total']['jumlah']);
        $this->assertSame('50000.0000', $lr['beban_lain']['total']['jumlah']);
        $this->assertSame('670000.0000', $lr['laba_bersih']['jumlah']);
        $this->assertSame('300000.0000', $this->row($lr['beban_operasional'], '6-2000')['jumlah']);
        $this->assertArrayNotHasKey('pembanding', $lr['laba_bersih']);
    }

    public function test_perbandingan_dengan_periode_sebelumnya_selisih_dan_persen(): void
    {
        $this->jurnal('2026-02-10', [$this->line('1-1110', 'debit', '400000'), $this->line('4-1000', 'kredit', '400000')]);
        $this->transaksiMaret();
        $p = $this->periodId('2026-03-15');

        $lr = $this->api("reports/income-statement?dari_period_id={$p}&bandingkan=sebelumnya")->assertOk()->json('data');

        $pend = $lr['pendapatan']['total'];
        $this->assertSame('1000000.0000', $pend['jumlah']);
        $this->assertSame('400000.0000', $pend['pembanding']);
        $this->assertSame('600000.0000', $pend['selisih']);
        $this->assertSame('150.00', $pend['persen']);
        $this->assertSame('75.00', $lr['laba_bersih']['persen']); // 400.000 -> 700.000
        $this->assertNotNull($lr['pembanding']);
    }

    public function test_pembanding_nol_menghasilkan_persen_null_dan_tahun_lalu_tanpa_data_diberi_catatan(): void
    {
        $this->transaksiMaret();
        $p = $this->periodId('2026-03-15');

        $lr = $this->api("reports/income-statement?dari_period_id={$p}&bandingkan=tahun_lalu")->assertOk()->json('data');

        $this->assertSame('0.0000', $lr['pendapatan']['total']['pembanding']);
        $this->assertNull($lr['pendapatan']['total']['persen']);
        $this->assertSame('1000000.0000', $lr['pendapatan']['total']['selisih']);

        $bs = $this->api("reports/balance-sheet?period_id={$p}&bandingkan=tahun_lalu")->assertOk()->json('data');
        $this->assertNull($bs['pembanding']);
        $this->assertNotNull($bs['catatan']);
    }

    public function test_mode_ringkas_menggabungkan_akun_ke_induknya(): void
    {
        $this->transaksiMaret();
        $p = $this->periodId('2026-03-15');

        $lr = $this->api("reports/income-statement?dari_period_id={$p}&mode=ringkas")->assertOk()->json('data');

        $this->assertSame('1000000.0000', $this->row($lr['pendapatan'], '4-0000')['jumlah']);
        $this->assertNull($this->row($lr['pendapatan'], '4-1000'));
        $this->assertSame('300000.0000', $this->row($lr['beban_operasional'], '6-0000')['jumlah']);
        $this->assertSame('700000.0000', $lr['laba_bersih']['jumlah']); // total sama dengan mode rinci
    }

    public function test_rentang_periode_terbalik_ditolak(): void
    {
        $maret = $this->periodId('2026-03-15');
        $feb = $this->periodId('2026-02-15');
        Sanctum::actingAs($this->owner);

        $this->api("reports/income-statement?dari_period_id={$maret}&sampai_period_id={$feb}")->assertStatus(422);
    }

    // ---------------------------------------------------------------- Buku Besar

    public function test_buku_besar_ringkasan_per_akun_dengan_saldo_awal_dan_paginasi(): void
    {
        $this->transaksiMaret();
        Sanctum::actingAs($this->owner);

        $semua = [];
        $cursor = null;
        for ($i = 0; $i < 10; $i++) {
            $q = 'reports/general-ledger?dari=2026-03-01&sampai=2026-03-31&limit=2' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
            $page = $this->api($q)->assertOk()->json('data');
            $this->assertLessThanOrEqual(2, count($page['rows']));
            $semua = array_merge($semua, $page['rows']);
            $cursor = $page['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }

        $kodes = array_column($semua, 'kode');
        $this->assertSame($kodes, array_values(array_unique($kodes))); // tanpa duplikat antarhalaman
        $kas = collect($semua)->firstWhere('kode', '1-1110');
        $this->assertSame('0.0000', $kas['saldo_awal']);
        $this->assertSame('6000000.0000', $kas['debit']);
        $this->assertSame('300000.0000', $kas['kredit']);
        $this->assertSame('5700000.0000', $kas['saldo_akhir']);

        $april = $this->api('reports/general-ledger?dari=2026-04-01&sampai=2026-04-30')->assertOk()->json('data');
        $kasApr = collect($april['rows'])->firstWhere('kode', '1-1110');
        $this->assertSame('5700000.0000', $kasApr['saldo_awal']);
        $penjualanApr = collect($april['rows'])->firstWhere('kode', '4-1000');
        $this->assertSame('1000000.0000', $penjualanApr['saldo_awal']); // tahun buku sama: laba rugi belum reset
    }

    // ---------------------------------------------------------------- izin dan tenant

    private function memberWithRole(string $roleName): User
    {
        $u = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', $roleName)->value('id'),
        ]);

        return $u;
    }

    public function test_hanya_owner_admin_akuntan_boleh_laporan_keuangan(): void
    {
        $this->transaksiMaret();
        $p = $this->periodId('2026-03-15');
        $urls = [
            "reports/balance-sheet?period_id={$p}",
            "reports/income-statement?dari_period_id={$p}",
            'reports/general-ledger?dari=2026-03-01&sampai=2026-03-31',
            "reports/trial-balance?period_id={$p}",
        ];

        foreach (['Kasir', 'Gudang', 'Purchasing'] as $role) {
            Sanctum::actingAs($this->memberWithRole($role));
            foreach ($urls as $u) {
                $this->api($u)->assertForbidden();
            }
        }

        foreach (['Admin', 'Akuntan'] as $role) {
            Sanctum::actingAs($this->memberWithRole($role));
            foreach ($urls as $u) {
                $this->api($u)->assertOk();
            }
        }
    }

    public function test_perusahaan_lain_tidak_bisa_memakai_periode_perusahaan_ini(): void
    {
        $p = $this->periodId('2026-03-15');
        $ownerB = User::factory()->create();
        $b = $this->makeCompany($ownerB, 'PT B');
        Sanctum::actingAs($ownerB);

        $this->api("reports/balance-sheet?period_id={$p}", $this->h($b))->assertNotFound();
        $this->api("reports/income-statement?dari_period_id={$p}", $this->h($b))->assertNotFound();
    }
}
