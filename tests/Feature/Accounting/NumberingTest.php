<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Numbering\NumberingService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanySetting;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompany::class)->handle($this->owner, [
            'nama' => 'PT Uji', 'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_nomor_berurutan_dan_reset_per_bulan(): void
    {
        $n = app(NumberingService::class);

        $this->assertSame('INV/2026/11/00001', $n->next('sales_invoice', '2026-11-16'));
        $this->assertSame('INV/2026/11/00002', $n->next('sales_invoice', '2026-11-20'));
        $this->assertSame('INV/2026/12/00001', $n->next('sales_invoice', '2026-12-01'));
        $this->assertSame('PY/2026/11/00001', $n->next('purchase_payment', '2026-11-16')); // jenis lain punya counter sendiri
    }

    public function test_rollback_transaksi_tidak_meninggalkan_celah(): void
    {
        $n = app(NumberingService::class);

        try {
            DB::transaction(function () use ($n) {
                $n->next('sales_invoice', '2026-11-16');
                throw new RuntimeException('posting gagal');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('INV/2026/11/00001', $n->next('sales_invoice', '2026-11-16'));
    }

    public function test_preview_tidak_mengonsumsi_counter(): void
    {
        $n = app(NumberingService::class);

        $this->assertSame('INV/2026/11/00001', $n->preview('sales_invoice', '2026-11-16'));
        $this->assertSame('INV/2026/11/00001', $n->preview('sales_invoice', '2026-11-16'));
        $this->assertSame('INV/2026/11/00001', $n->next('sales_invoice', '2026-11-16'));
        $this->assertSame('INV/2026/11/00002', $n->preview('sales_invoice', '2026-11-16'));
    }

    public function test_format_dan_reset_kustom_serta_perpindahan_ke_none_meneruskan_nomor(): void
    {
        $n = app(NumberingService::class);
        $n->next('sales_order', '2026-01-10');
        $n->next('sales_order', '2026-02-10');
        $n->next('sales_order', '2026-02-11'); // counter Feb sudah di 3

        $n->configure('sales_order', 'SO', '{PREFIX}-{YY}-{SEQ:4}', 'none');

        $this->assertSame('SO-26-0004', $n->next('sales_order', '2026-03-01')); // melanjutkan, bukan mulai dari 1
        $this->assertSame('SO-26-0005', $n->next('sales_order', '2026-03-02'));
    }

    public function test_nomor_terpisah_per_perusahaan(): void
    {
        $n = app(NumberingService::class);
        $n->next('sales_invoice', '2026-11-16');

        $b = app(CreateCompany::class)->handle(User::factory()->create(), [
            'nama' => 'PT B', 'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
        app(CurrentCompany::class)->set($b);

        $this->assertSame('INV/2026/11/00001', $n->next('sales_invoice', '2026-11-16'));
    }

    public function test_validasi_format(): void
    {
        $this->assertNotNull(NumberingService::formatError('{PREFIX}/{SEQ:5}', 'monthly'));      // tanpa tahun/bulan
        $this->assertNotNull(NumberingService::formatError('{PREFIX}/{YYYY}/{MM}', 'monthly'));  // tanpa SEQ
        $this->assertNotNull(NumberingService::formatError('{PREFIX}/{FOO}/{SEQ}', 'none'));     // token tidak dikenal
        $this->assertNotNull(NumberingService::formatError('{PREFIX}/{MM}/{SEQ}', 'yearly'));    // yearly tanpa tahun
        $this->assertNull(NumberingService::formatError('{PREFIX}/{YYYY}/{MM}/{SEQ:5}', 'monthly'));
        $this->assertNull(NumberingService::formatError('{PREFIX}{SEQ:6}', 'none'));
    }

    public function test_jenis_dokumen_tidak_dikenal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NumberingService::class)->next('tidak_ada', '2026-11-16');
    }

    public function test_api_penomoran_dan_hak_akses(): void
    {
        $h = ['X-Company-Id' => $this->company->public_id];
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/numbering', $h)->assertOk()->assertJsonCount(count(config('numbering.types')), 'data');
        $this->putJson('/api/v1/numbering/sales_invoice', ['prefix' => 'FJ', 'format' => '{PREFIX}/{SEQ:5}', 'reset' => 'monthly'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'invalid_format');
        $this->putJson('/api/v1/numbering/sales_invoice', ['prefix' => 'FJ', 'format' => '{PREFIX}/{YYYY}/{SEQ:5}', 'reset' => 'yearly'], $h)
            ->assertOk()->assertJsonPath('data.contoh_berikut', 'FJ/' . now()->format('Y') . '/00001');
        $this->putJson('/api/v1/numbering/foo', ['prefix' => 'X', 'format' => '{SEQ}', 'reset' => 'none'], $h)->assertNotFound();

        $kasir = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $kasir->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', 'Kasir')->value('id'),
        ]);
        Sanctum::actingAs($kasir);
        $this->getJson('/api/v1/numbering', $h)->assertForbidden();
    }

    public function test_preferensi_mode_pajak_sinkron_dengan_perusahaan_dan_kasir_ditolak(): void
    {
        $h = ['X-Company-Id' => $this->company->public_id];
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/settings', $h)->assertOk()->assertJsonPath('data.mode_pajak', 'non_pkp');
        $this->putJson('/api/v1/settings', ['mode_pajak' => 'pkp', 'izinkan_stok_minus' => true], $h)
            ->assertOk()->assertJsonPath('data.mode_pajak', 'pkp');

        $this->assertTrue($this->company->fresh()->is_pkp);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'company_settings', 'aksi' => 'update']);

        $kasir = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id, 'user_id' => $kasir->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', 'Kasir')->value('id'),
        ]);
        Sanctum::actingAs($kasir);
        $this->putJson('/api/v1/settings', ['mode_pajak' => 'non_pkp'], $h)->assertForbidden();
    }
}
