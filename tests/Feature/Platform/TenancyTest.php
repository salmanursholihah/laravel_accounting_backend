<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CrossCompanyException;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Platform\Tenancy\NoCompanyContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function makeCompany(User $owner, string $nama): Company
    {
        return app(CreateCompany::class)->handle($owner, [
            'nama' => $nama,
            'is_pkp' => true,
            'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
    }

    public function test_query_tanpa_konteks_perusahaan_ditolak(): void
    {
        $this->expectException(NoCompanyContext::class);
        Branch::query()->count();
    }

    public function test_query_hanya_melihat_data_perusahaan_aktif(): void
    {
        $a = $this->makeCompany(User::factory()->create(), 'PT A');
        $b = $this->makeCompany(User::factory()->create(), 'PT B');
        $current = app(CurrentCompany::class);

        $current->runAs($a, function () use ($a) {
            $this->assertSame(1, Branch::count());
            $this->assertEquals($a->id, Branch::firstOrFail()->company_id);
        });
        $current->runAs($b, fn () => $this->assertSame(1, Branch::count()));
    }

    public function test_menyimpan_data_perusahaan_lain_dalam_konteks_ditolak(): void
    {
        $a = $this->makeCompany(User::factory()->create(), 'PT A');
        $b = $this->makeCompany(User::factory()->create(), 'PT B');

        $this->expectException(CrossCompanyException::class);
        app(CurrentCompany::class)->runAs($a, function () use ($b) {
            Branch::create(['company_id' => $b->id, 'kode' => 'X', 'nama' => 'Cabang X']);
        });
    }

    public function test_company_id_diisi_otomatis_dari_konteks(): void
    {
        $a = $this->makeCompany(User::factory()->create(), 'PT A');

        app(CurrentCompany::class)->runAs($a, function () use ($a) {
            $branch = Branch::create(['kode' => 'JKT', 'nama' => 'Jakarta']);
            $this->assertEquals($a->id, $branch->company_id);
        });
    }

    public function test_user_perusahaan_a_tidak_bisa_memakai_perusahaan_b(): void
    {
        $userA = User::factory()->create();
        $a = $this->makeCompany($userA, 'PT A');
        $b = $this->makeCompany(User::factory()->create(), 'PT B');

        Sanctum::actingAs($userA);

        $this->getJson('/api/v1/company', ['X-Company-Id' => $b->public_id])->assertForbidden();
        $this->getJson('/api/v1/company', ['X-Company-Id' => $a->public_id])
            ->assertOk()
            ->assertJsonPath('data.nama', 'PT A')
            ->assertJsonPath('data.role', 'Owner');
    }

    public function test_tanpa_header_perusahaan_diminta_dipilih(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/company')->assertStatus(400)->assertJsonPath('code', 'company_required');
    }

    public function test_membuat_perusahaan_lewat_api(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/companies', [
            'nama' => 'PT Maju Jaya',
            'tanggal_mulai_pembukuan' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.nama', 'PT Maju Jaya');

        $this->getJson('/api/v1/me/companies')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'company', 'aksi' => 'create']);
    }
}
