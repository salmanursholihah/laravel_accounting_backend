<?php

namespace Tests\Feature\FixedAssets;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\Close\PeriodCloseService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\FixedAssets\Assets\FixedAssetService;
use App\Modules\FixedAssets\Categories\AssetCategoryService;
use App\Modules\FixedAssets\Depreciation\DepreciationService;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\FixedAsset;
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

/** Aset Tetap + penyusutan garis lurus (7D, ALG-08). BELUM pernah dijalankan oleh penulisnya: jalankan di MySQL 8 asli. */
class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private AssetCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = $this->newCompany($this->owner);
        $this->as($this->owner, $this->co);
        $this->cat = $this->makeCategory('KAT-A');
    }

    // ------------------------------------------------------------------------------------------ pembantu

    private function newCompany(User $o): Company
    {
        return app(CreateCompany::class)->handle($o, ['nama' => 'PT Aset ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
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

    private function makeCategory(string $kode, array $over = []): AssetCategory
    {
        return app(AssetCategoryService::class)->create($over + [
            'kode' => $kode, 'nama' => "Kategori {$kode}", 'akun_aset_id' => $this->acc('1-2100'), 'akun_akumulasi_id' => $this->acc('1-2900'), 'akun_beban_id' => $this->acc('6-3000'),
        ], $this->owner);
    }

    private function makeAsset(string $kode, string $harga, int $umur, string $tgl = '2026-01-10', string $residu = '0', ?AssetCategory $cat = null): FixedAsset
    {
        return app(FixedAssetService::class)->create([
            'kode' => $kode, 'nama' => "Aset {$kode}", 'category_id' => ($cat ?? $this->cat)->id, 'tanggal_perolehan' => $tgl,
            'harga_perolehan' => $harga, 'nilai_residu' => $residu, 'umur_bulan' => $umur,
        ], $this->owner);
    }

    private function period(int $bulan): AccountingPeriod
    {
        return AccountingPeriod::query()->where('bulan', $bulan)->firstOrFail();
    }

    private function run(int $bulan): array
    {
        return app(DepreciationService::class)->run($this->period($bulan)->id, $this->owner);
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
        } catch (FixedAssetException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function item(array $hasil, string $kode): array
    {
        foreach ($hasil['items'] as $i) {
            if ($i['kode'] === $kode) {
                return $i;
            }
        }
        $this->fail("Item {$kode} tidak ada");
    }

    // ------------------------------------------------------------------------------------------ kategori dan aset

    public function test_kategori_memvalidasi_tipe_akun_header_dan_kode_ganda(): void
    {
        $this->assertErr('account_type_mismatch', fn () => $this->makeCategory('X1', ['akun_beban_id' => $this->acc('1-2100')]));   // beban harus expense
        $this->assertErr('account_not_usable', fn () => $this->makeCategory('X2', ['akun_aset_id' => $this->acc('1-2000')]));        // akun induk
        $this->assertErr('account_type_mismatch', fn () => $this->makeCategory('X3', ['akun_akumulasi_id' => $this->acc('1-2100')])); // aset = akumulasi
        $this->assertErr('duplicate_code', fn () => $this->makeCategory('KAT-A'));
    }

    public function test_awal_penyusutan_mengikuti_preferensi(): void
    {
        $a = $this->makeAsset('A1', '1200000', 12, '2026-03-15');
        $this->assertSame('2026-03-01', $a->tanggal_mulai_susut->toDateString()); // default: bulan perolehan

        DB::table('company_settings')->updateOrInsert(['company_id' => $this->co->id], ['aturan_awal_penyusutan' => 'next_month']);
        $b = $this->makeAsset('A2', '1200000', 12, '2026-03-15');
        $this->assertSame('2026-04-01', $b->tanggal_mulai_susut->toDateString());
    }

    public function test_validasi_aset(): void
    {
        $this->assertErr('validation', fn () => $this->makeAsset('B1', '1000000', 12, '2026-01-10', '1000000')); // residu >= harga
        $this->assertErr('validation', fn () => $this->makeAsset('B2', '0', 12));
        $this->assertErr('validation', fn () => $this->makeAsset('B3', '1000000', 0));
        $this->assertErr('before_books_start', fn () => $this->makeAsset('B4', '1000000', 12, '2025-12-31'));
        $this->makeAsset('B5', '1000000', 12);
        $this->assertErr('duplicate_code', fn () => $this->makeAsset('B5', '1000000', 12));

        $c = $this->makeCategory('KAT-U', ['umur_default_bulan' => 24]);
        $a = app(FixedAssetService::class)->create(['kode' => 'B6', 'nama' => 'Umur default', 'category_id' => $c->id, 'tanggal_perolehan' => '2026-01-10', 'harga_perolehan' => '2400000'], $this->owner);
        $this->assertSame(24, (int) $a->umur_bulan);
    }

    public function test_aset_terkunci_setelah_ada_jadwal(): void
    {
        $a = $this->makeAsset('L1', '1200000', 12);
        $this->assertSame('Nama baru', app(FixedAssetService::class)->update($a->id, ['harga_perolehan' => '1300000', 'nama' => 'Nama baru'], $this->owner)->nama); // belum ada jadwal: boleh

        $this->run(1);

        $this->assertErr('asset_locked', fn () => app(FixedAssetService::class)->update($a->id, ['harga_perolehan' => '9'], $this->owner));
        $this->assertSame('Nama lagi', app(FixedAssetService::class)->update($a->id, ['nama' => 'Nama lagi'], $this->owner)->nama);
        $this->assertErr('asset_locked', fn () => app(FixedAssetService::class)->delete($a->id, $this->owner));
        $this->assertErr('category_in_use', fn () => app(AssetCategoryService::class)->delete($this->cat->id, $this->owner));
        $this->assertErr('category_in_use', fn () => app(AssetCategoryService::class)->update($this->cat->id, ['akun_beban_id' => $this->acc('6-3000') + 0, 'akun_aset_id' => $this->acc('1-2900'), 'akun_akumulasi_id' => $this->acc('1-2100')], $this->owner));
    }

    // ------------------------------------------------------------------------------------------ penyusutan

    public function test_penyusutan_membuat_jurnal_jadwal_dan_idempoten(): void
    {
        $a = $this->makeAsset('P1', '12000000', 12);

        $r = $this->run(1);
        $this->assertSame(1, $r['jumlah_aset']);
        $this->assertMoney('1000000', $r['total']);

        $j = DB::table('journals')->where('source_type', 'depreciation_run')->first();
        $this->assertSame('2026-01-31', $j->tanggal);
        $this->assertSame('auto', $j->tipe);
        $lines = DB::table('journal_lines')->where('journal_id', $j->id)->orderBy('line_no')->get();
        $this->assertSame($this->acc('6-3000'), (int) $lines[0]->account_id);
        $this->assertMoney('1000000', $lines[0]->debit);
        $this->assertSame($this->acc('1-2900'), (int) $lines[1]->account_id);
        $this->assertMoney('1000000', $lines[1]->kredit);

        $s = DB::table('depreciation_schedules')->where('asset_id', $a->id)->first();
        $this->assertSame(1, (int) $s->bulan_ke);
        $this->assertMoney('1000000', $s->akumulasi);
        $this->assertMoney('11000000', $s->nilai_buku);
        $this->assertSame((int) $j->id, (int) $s->journal_id);

        // dijalankan lagi: tidak menggandakan jurnal maupun jadwal
        $again = $this->run(1);
        $this->assertSame(0, $again['jumlah_aset']);
        $this->assertSame(1, DB::table('journals')->where('source_type', 'depreciation_run')->count());
        $this->assertSame(1, DB::table('depreciation_schedules')->count());
        $this->assertSame(1, DB::table('depreciation_runs')->count());
    }

    public function test_bulan_terakhir_menyesuaikan_sisa_dan_menghormati_residu(): void
    {
        $a = $this->makeAsset('M1', '1000000', 3);                       // 333.333,3333 x2, bulan ke-3 = 333.333,3334
        $b = $this->makeAsset('M2', '1000000', 2, '2026-01-10', '100000'); // 450.000 x2, buku akhir = 100.000

        $jan = $this->run(1);
        $this->assertMoney('783333.3333', $jan['total']);
        $feb = $this->run(2);
        $this->assertMoney('783333.3333', $feb['total']);
        $mar = $this->run(3);
        $this->assertSame(1, $mar['jumlah_aset']); // M2 sudah habis
        $this->assertMoney('333333.3334', $mar['total']);

        $sa = DB::table('depreciation_schedules')->where('asset_id', $a->id)->orderBy('bulan_ke')->get();
        $this->assertCount(3, $sa);
        $this->assertMoney('1000000', $sa[2]->akumulasi);
        $this->assertMoney('0', $sa[2]->nilai_buku);

        $sb = DB::table('depreciation_schedules')->where('asset_id', $b->id)->orderBy('bulan_ke')->get();
        $this->assertCount(2, $sb);
        $this->assertMoney('900000', $sb[1]->akumulasi);
        $this->assertMoney('100000', $sb[1]->nilai_buku);

        $this->assertSame(0, $this->run(4)['jumlah_aset']); // semua habis
    }

    public function test_tidak_boleh_loncat_periode_dan_tidak_ada_yang_tertulis(): void
    {
        $this->makeAsset('S1', '1200000', 12);

        $this->assertErr('previous_period_not_run', fn () => $this->run(2));

        $this->assertSame(0, DB::table('depreciation_schedules')->count());
        $this->assertSame(0, DB::table('depreciation_runs')->count());
        $this->assertSame(0, DB::table('journals')->where('source_type', 'depreciation_run')->count());
    }

    public function test_satu_jurnal_per_kategori_dan_penggabungan_aset_sekategori(): void
    {
        $k2 = $this->makeCategory('KAT-B');
        $this->makeAsset('C1', '1200000', 12);
        $this->makeAsset('C2', '2400000', 12);
        $this->makeAsset('C3', '3600000', 12, '2026-01-20', '0', $k2);

        $r = $this->run(1);

        $this->assertSame(3, $r['jumlah_aset']);
        $this->assertMoney('600000', $r['total']);
        $this->assertCount(2, $r['jurnal']);
        $this->assertSame(2, DB::table('journals')->where('source_type', 'depreciation_run')->count());
        $totals = collect($r['jurnal'])->mapWithKeys(fn ($j) => [(int) $j['category_id'] => $j['total']]);
        $this->assertMoney('300000', $totals[$this->cat->id]);
        $this->assertMoney('300000', $totals[$k2->id]);
    }

    public function test_preview_tidak_menulis_dan_periode_closed_ditolak(): void
    {
        $this->makeAsset('V1', '1200000', 12);

        $p = app(DepreciationService::class)->preview($this->period(1)->id);
        $this->assertSame(1, $p['jumlah_aset']);
        $this->assertMoney('100000', $p['total']);
        $this->assertSame(0, DB::table('depreciation_schedules')->count());
        $this->assertSame(0, DB::table('journals')->where('source_type', 'depreciation_run')->count());

        app(PeriodService::class)->close($this->period(1), $this->owner);
        $this->assertErr('period_closed', fn () => $this->run(1));
    }

    public function test_aset_dengan_awal_di_bulan_depan_tidak_ikut_periode_ini(): void
    {
        $this->makeAsset('F1', '1200000', 12, '2026-02-05'); // mulai Februari

        $this->assertSame(0, $this->run(1)['jumlah_aset']);
        $this->assertSame(1, $this->run(2)['jumlah_aset']);
    }

    // ------------------------------------------------------------------------------------------ Akhir Bulan

    public function test_checklist_akhir_bulan_penyusutan(): void
    {
        $svc = app(PeriodCloseService::class);
        $this->assertSame('na', $this->item($svc->check($this->period(1)), 'penyusutan_aset')['status']); // belum ada aset

        $this->makeAsset('K1', '1200000', 12);
        $hasil = $svc->check($this->period(1));
        $this->assertSame('failed', $this->item($hasil, 'penyusutan_aset')['status']);
        $this->assertFalse($hasil['bisa_ditutup']);

        $this->run(1);
        $this->assertSame('ok', $this->item($svc->check($this->period(1)), 'penyusutan_aset')['status']);
        $this->assertSame('failed', $this->item($svc->check($this->period(2)), 'penyusutan_aset')['status']); // Feb belum
    }

    // ------------------------------------------------------------------------------------------ isolasi dan hak akses

    public function test_isolasi_antar_perusahaan(): void
    {
        $a = $this->makeAsset('I1', '1200000', 12);

        $other = User::factory()->create();
        $this->as($other, $this->newCompany($other));

        $this->expectException(ModelNotFoundException::class);
        app(FixedAssetService::class)->find($a->id);
    }

    public function test_http_hak_akses(): void
    {
        $h = ['X-Company-Id' => $this->co->public_id];
        $periodId = $this->period(1)->id;

        Sanctum::actingAs($this->member('Akuntan'));
        $id = $this->withHeaders($h)->postJson('/api/v1/fixed-assets', [
            'kode' => 'H1', 'nama' => 'Laptop', 'category_id' => $this->cat->id, 'tanggal_perolehan' => '2026-01-10', 'harga_perolehan' => '12000000', 'umur_bulan' => 12,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($h)->getJson("/api/v1/periods/{$periodId}/depreciation-preview")->assertOk()->assertJsonPath('data.jumlah_aset', 1);
        $this->withHeaders($h)->postJson("/api/v1/periods/{$periodId}/depreciation")->assertCreated()->assertJsonPath('data.jumlah_aset', 1);
        $this->withHeaders($h)->getJson("/api/v1/fixed-assets/{$id}/schedule")->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->postJson('/api/v1/fixed-assets', ['kode' => 'H2'])->assertStatus(422);

        Sanctum::actingAs($this->member('Admin')); // Admin: kelola aset (C), tidak boleh menjalankan penyusutan
        $this->withHeaders($h)->getJson('/api/v1/fixed-assets')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->postJson("/api/v1/periods/{$periodId}/depreciation")->assertForbidden();

        Sanctum::actingAs($this->member('Kasir'));
        $this->withHeaders($h)->getJson('/api/v1/fixed-assets')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/asset-categories')->assertForbidden();
    }
}
