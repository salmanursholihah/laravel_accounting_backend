<?php

namespace Tests\Feature\FixedAssets;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\FixedAssets\Assets\FixedAssetService;
use App\Modules\FixedAssets\Categories\AssetCategoryService;
use App\Modules\FixedAssets\Depreciation\DepreciationService;
use App\Modules\FixedAssets\Disposal\AssetDisposalService;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\AssetDisposal;
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

/** Disposisi Aset Tetap (7D-2, UC-57). BELUM pernah dijalankan oleh penulisnya: jalankan di MySQL 8 asli. */
class AssetDisposalTest extends TestCase
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
        $this->co = app(CreateCompany::class)->handle($this->owner, ['nama' => 'PT Lepas ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
        $this->as($this->owner, $this->co);

        $this->cat = app(AssetCategoryService::class)->create([
            'kode' => 'KAT-A', 'nama' => 'Kategori A', 'akun_aset_id' => $this->acc('1-2100'), 'akun_akumulasi_id' => $this->acc('1-2900'), 'akun_beban_id' => $this->acc('6-3000'),
        ], $this->owner);
    }

    // ------------------------------------------------------------------------------------------ pembantu

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

    /** 12.000.000 / 12 bulan = 1.000.000 per bulan, mulai Januari 2026. */
    private function asset(string $kode = 'A-1', string $harga = '12000000', int $umur = 12): FixedAsset
    {
        return app(FixedAssetService::class)->create([
            'kode' => $kode, 'nama' => "Aset {$kode}", 'category_id' => $this->cat->id, 'tanggal_perolehan' => '2026-01-10',
            'harga_perolehan' => $harga, 'nilai_residu' => '0', 'umur_bulan' => $umur,
        ], $this->owner);
    }

    private function depreciate(int ...$bulan): void
    {
        foreach ($bulan as $b) {
            app(DepreciationService::class)->run((int) AccountingPeriod::query()->where('bulan', $b)->value('id'), $this->owner);
        }
    }

    private function draft(FixedAsset $a, string $tgl, string $nilai = '0', ?int $akun = null, ?User $by = null): AssetDisposal
    {
        return app(AssetDisposalService::class)->create([
            'tanggal' => $tgl, 'asset_id' => $a->id, 'nilai_pelepasan' => $nilai, 'akun_penerimaan_id' => $akun, 'alasan' => 'Dijual',
        ], $by ?? $this->owner);
    }

    private function post(AssetDisposal $d, ?User $u = null)
    {
        return app(PostingOrchestrator::class)->post('asset_disposal', (int) $d->id, $u ?? $this->owner);
    }

    /** debit - kredit satu akun dari semua jurnal disposisi. */
    private function net(string $kode, int $docId): string
    {
        return (string) DB::table('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.company_id', $this->co->id)->where('jl.account_id', $this->acc($kode))->where('j.source_type', 'asset_disposal')->where('j.source_id', $docId)
            ->selectRaw('COALESCE(SUM(jl.debit - jl.kredit), 0) AS s')->value('s');
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

    // ------------------------------------------------------------------------------------------ jurnal

    public function test_jual_dengan_laba_jurnal_dan_status_aset(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2, 3); // akumulasi 3.000.000, nilai buku 9.000.000
        $d = $this->draft($a, '2026-04-15', '10000000', $this->acc('1-1110'));

        $r = $this->post($d);

        $d->refresh();
        $this->assertSame('posted', $d->status);
        $this->assertSame($r->nomor, $d->nomor);
        $this->assertStringStartsWith('DSP', $d->nomor);
        $this->assertMoney('3000000', $d->akumulasi);
        $this->assertMoney('9000000', $d->nilai_buku);
        $this->assertMoney('1000000', $d->laba_rugi);

        $this->assertMoney('10000000', $this->net('1-1110', $d->id));   // Dr kas
        $this->assertMoney('3000000', $this->net('1-2900', $d->id));    // Dr akumulasi
        $this->assertMoney('-12000000', $this->net('1-2100', $d->id));  // Cr aset
        $this->assertMoney('-1000000', $this->net('7-1000', $d->id));   // Cr laba
        $this->assertSame('dilepas', $a->fresh()->status);
        $this->assertSame('2026-04-15', $a->fresh()->tanggal_dilepas->toDateString());
    }

    public function test_jual_dengan_rugi_dan_hapus_tanpa_hasil(): void
    {
        $a = $this->asset('A-1');
        $b = $this->asset('A-2');
        $this->depreciate(1, 2, 3);

        $rugi = $this->draft($a, '2026-04-15', '8000000', $this->acc('1-1110'));
        $this->post($rugi);
        $this->assertMoney('1000000', $this->net('7-1000', $rugi->id));  // Dr rugi 1.000.000
        $this->assertMoney('-1000000', $rugi->fresh()->laba_rugi);

        $hapus = $this->draft($b, '2026-04-20', '0'); // write-off: tanpa akun penerimaan
        $this->post($hapus);
        $this->assertMoney('9000000', $this->net('7-1000', $hapus->id)); // Dr rugi = nilai buku
        $this->assertMoney('3000000', $this->net('1-2900', $hapus->id));
        $this->assertMoney('-12000000', $this->net('1-2100', $hapus->id));
    }

    public function test_pratinjau_tidak_menyimpan_apa_pun(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2, 3);

        $p = app(AssetDisposalService::class)->preview((int) $a->id, '2026-04-15', '10000000', $this->acc('1-1110'));

        $this->assertMoney('1000000', $p['laba_rugi']);
        $this->assertSame(['D', 'D', 'K', 'K'], array_column($p['jurnal'], 'sisi'));
        $this->assertSame(0, AssetDisposal::query()->count());
        $this->assertSame('aktif', $a->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------ aturan penyusutan

    public function test_penyusutan_sampai_bulan_sebelum_pelepasan_wajib_lengkap_dan_gagal_tidak_mengubah_apa_pun(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2); // Maret belum
        $d = $this->draft($a, '2026-04-15', '10000000', $this->acc('1-1110'));

        $this->assertErr('depreciation_not_run', fn () => $this->post($d));

        $this->assertSame('draft', $d->fresh()->status);
        $this->assertSame('aktif', $a->fresh()->status);
        $this->assertSame(0, DB::table('journals')->where('source_type', 'asset_disposal')->count());
        $this->assertNull($d->fresh()->nomor);
    }

    public function test_penyusutan_sesudah_bulan_pelepasan_menolak(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2, 3, 4);
        $d = $this->draft($a, '2026-03-10', '1000', $this->acc('1-1110'));

        $this->assertErr('depreciation_after_disposal', fn () => $this->post($d));
    }

    public function test_aset_dilepas_tidak_disusutkan_lagi(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2, 3);
        $this->post($this->draft($a, '2026-04-15', '1', $this->acc('1-1110')));

        $this->depreciate(4);

        $this->assertSame(3, DB::table('depreciation_schedules')->where('asset_id', $a->id)->count());
    }

    // ------------------------------------------------------------------------------------------ draf

    public function test_satu_disposisi_aktif_per_aset_dan_aturan_draf(): void
    {
        $a = $this->asset();
        $this->draft($a, '2026-02-10');

        $this->assertErr('disposal_exists', fn () => $this->draft($a, '2026-02-11'));
        $this->assertErr('account_invalid', fn () => $this->draft($this->asset('A-2'), '2026-02-10', '1000', $this->acc('4-1000'))); // akun pendapatan bukan akun aset
        $this->assertErr('disposal_before_acquisition', fn () => $this->draft($this->asset('A-3'), '2025-12-31'));
        $this->assertSame(1, AssetDisposal::query()->count());
    }

    public function test_aset_dilepas_tidak_bisa_diubah_atau_dihapus_dan_draf_bisa_diubah_dihapus(): void
    {
        $a = $this->asset();
        $d = $this->draft($a, '2026-01-20', '500', $this->acc('1-1110')); // Januari: bulan pelepasan = bulan ke-1, tidak perlu penyusutan
        $svc = app(AssetDisposalService::class);

        $svc->update((int) $d->id, ['nilai_pelepasan' => '700'], $this->owner);
        $this->assertMoney('700', $d->fresh()->nilai_pelepasan);
        $this->assertSame(2, (int) $d->fresh()->version);
        $this->assertErr('edit_conflict', fn () => $svc->update((int) $d->id, ['nilai_pelepasan' => '1', 'version' => 1], $this->owner));

        $this->post($d);
        $this->assertErr('not_draft', fn () => $svc->update((int) $d->id, ['nilai_pelepasan' => '1'], $this->owner));
        $this->assertErr('not_draft', fn () => $svc->delete((int) $d->id, $this->owner));
        $this->assertErr('asset_disposed', fn () => app(FixedAssetService::class)->update((int) $a->id, ['nama' => 'Baru'], $this->owner));
        $this->assertErr('asset_locked', fn () => app(FixedAssetService::class)->delete((int) $a->id, $this->owner));
    }

    // ------------------------------------------------------------------------------------------ void

    public function test_void_memulihkan_aset_dan_membalik_jurnal(): void
    {
        $a = $this->asset();
        $this->depreciate(1, 2, 3);
        $d = $this->draft($a, '2026-04-15', '10000000', $this->acc('1-1110'));
        $this->post($d);

        app(PostingOrchestrator::class)->void('asset_disposal', (int) $d->id, 'Salah input', $this->owner);

        $this->assertSame('void', $d->fresh()->status);
        $this->assertSame('aktif', $a->fresh()->status);
        $this->assertNull($a->fresh()->tanggal_dilepas);
        $this->assertMoney('0', $this->net('1-2100', $d->id)); // jurnal asli + pembalik = 0
        // aset boleh didisposisi lagi
        $this->draft($a, '2026-04-20', '9000000', $this->acc('1-1110'));
        $this->assertSame(2, AssetDisposal::query()->count());
    }

    public function test_void_ditolak_bila_penyusutan_bulan_sesudah_pelepasan_sudah_jalan(): void
    {
        $a = $this->asset('A-1');
        $this->asset('A-2'); // aset lain tetap aktif agar penyusutan Mei menghasilkan run
        $this->depreciate(1, 2, 3);
        $d = $this->draft($a, '2026-04-15', '1000', $this->acc('1-1110'));
        $this->post($d);
        $this->depreciate(4, 5);

        try {
            app(PostingOrchestrator::class)->void('asset_disposal', (int) $d->id, 'x', $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('has_active_children', $e->errorCode);
        }
        $this->assertSame('posted', $d->fresh()->status);
        $this->assertSame('dilepas', $a->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------ hak akses, isolasi

    public function test_hak_akses_per_role_dan_posting_milik_sendiri(): void
    {
        $h = ['X-Company-Id' => $this->co->public_id];
        $a = $this->asset();
        $body = ['tanggal' => '2026-01-20', 'asset_id' => $a->id, 'nilai_pelepasan' => '0', 'alasan' => 'Rusak'];

        Sanctum::actingAs($this->member('Kasir'));
        $this->withHeaders($h)->postJson('/api/v1/asset-disposals', $body)->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/asset-disposals')->assertForbidden();

        Sanctum::actingAs($this->member('Gudang'));
        $this->withHeaders($h)->postJson('/api/v1/asset-disposals', $body)->assertForbidden();

        Sanctum::actingAs($this->member('Akuntan'));
        $id = $this->withHeaders($h)->postJson('/api/v1/asset-disposals', $body)->assertCreated()->json('data.id');
        $this->withHeaders($h)->postJson("/api/v1/documents/asset_disposal/{$id}/post")->assertOk();
        $this->withHeaders($h)->getJson("/api/v1/asset-disposals/{$id}")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->withHeaders($h)->getJson('/api/v1/asset-disposals/preview?asset_id=' . $a->id . '&tanggal=2026-01-20')->assertStatus(409); // aset sudah dilepas
    }

    public function test_isolasi_lintas_perusahaan(): void
    {
        $a = $this->asset();
        $d = $this->draft($a, '2026-01-20');

        $owner2 = User::factory()->create();
        $co2 = app(CreateCompany::class)->handle($owner2, ['nama' => 'PT Lain ' . uniqid(), 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
        $this->as($owner2, $co2);

        $this->assertSame(0, AssetDisposal::query()->count());
        $this->expectException(ModelNotFoundException::class);
        app(AssetDisposalService::class)->find((int) $d->id);
    }

    public function test_periode_terkunci_menolak_posting(): void
    {
        $a = $this->asset();
        $d = $this->draft($a, '2026-01-20');
        $period = AccountingPeriod::query()->where('bulan', 1)->firstOrFail();
        app(\App\Modules\Accounting\Periods\PeriodService::class)->close($period, $this->owner);

        $this->expectException(\DomainException::class);
        $this->post($d);
    }
}
