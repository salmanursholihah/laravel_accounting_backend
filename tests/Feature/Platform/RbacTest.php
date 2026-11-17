<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->company = app(CreateCompany::class)->handle(User::factory()->create(), [
            'nama' => 'PT Uji',
            'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
    }

    private function actingAsRole(string $roleName): array
    {
        $user = User::factory()->create();
        $membership = CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'role_id' => Role::whereNull('company_id')->where('nama', $roleName)->value('id'),
            'status' => 'active',
        ]);

        app(CurrentCompany::class)->set($this->company, $membership->load('role'));
        app(PermissionChecker::class)->flush();

        return [$user, app(PermissionChecker::class)];
    }

    public function test_seeder_idempotent(): void
    {
        $count = DB::table('permissions')->count();
        $this->seed(RbacSeeder::class);

        $this->assertSame($count, DB::table('permissions')->count());
        $this->assertSame(6, Role::whereNull('company_id')->where('is_sistem', true)->count());
    }

    public function test_kasir_bisa_buat_faktur_tapi_tidak_bisa_void(): void
    {
        [$user, $can] = $this->actingAsRole('Kasir');

        $this->assertTrue($can->can($user, 'faktur_penjualan', 'create'));
        $this->assertFalse($can->can($user, 'faktur_penjualan', 'void'));
        $this->assertFalse($can->can($user, 'jurnal_umum', 'create'));
        $this->assertFalse($can->can($user, 'khusus', 'tutup_periode'));
    }

    public function test_kasir_posting_hanya_dokumen_miliknya(): void
    {
        [$user, $can] = $this->actingAsRole('Kasir');

        $milikSendiri = (new Branch())->forceFill(['created_by' => $user->id]);
        $milikOrang = (new Branch())->forceFill(['created_by' => $user->id + 999]);

        $this->assertTrue($can->can($user, 'faktur_penjualan', 'post', $milikSendiri));
        $this->assertFalse($can->can($user, 'faktur_penjualan', 'post', $milikOrang));
    }

    public function test_batas_diskon_kasir(): void
    {
        [$user, $can] = $this->actingAsRole('Kasir');

        $this->assertSame(5.0, $can->limit($user, 'khusus', 'ubah_harga_diskon'));
    }

    public function test_akuntan_boleh_jurnal_koreksi_tapi_tidak_buka_kunci_periode(): void
    {
        [$user, $can] = $this->actingAsRole('Akuntan');

        $this->assertTrue($can->can($user, 'khusus', 'jurnal_koreksi'));
        $this->assertTrue($can->can($user, 'jurnal_umum', 'void'));
        $this->assertFalse($can->can($user, 'khusus', 'buka_kunci_periode'));
    }

    public function test_owner_punya_semua_hak_khusus_dan_gudang_tidak_melihat_keuangan(): void
    {
        [$owner, $can] = $this->actingAsRole('Owner');
        $this->assertTrue($can->can($owner, 'khusus', 'buka_kunci_periode'));
        $this->assertTrue($can->can($owner, 'khusus', 'hapus_perusahaan'));

        [$gudang, $canGudang] = $this->actingAsRole('Gudang');
        $this->assertTrue($canGudang->can($gudang, 'penyesuaian_persediaan', 'create'));
        $this->assertFalse($canGudang->can($gudang, 'jurnal_umum', 'view'));
        $this->assertTrue($canGudang->can($gudang, 'khusus', 'lihat_hpp'));
    }

    public function test_user_non_anggota_tidak_punya_hak(): void
    {
        [, $can] = $this->actingAsRole('Owner');
        $orangLain = User::factory()->create();

        $this->assertFalse($can->can($orangLain, 'faktur_penjualan', 'view'));
    }
}
