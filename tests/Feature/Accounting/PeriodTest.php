<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\FiscalYear;
use App\Modules\Accounting\Periods\PeriodException;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use DomainException;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function company(User $owner, array $extra = []): Company
    {
        return app(CreateCompany::class)->handle($owner, $extra + [
            'nama' => 'PT Uji', 'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
    }

    private function in(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, $fn);
    }

    public function test_perusahaan_baru_punya_tahun_buku_dan_12_periode(): void
    {
        $c = $this->company(User::factory()->create());

        $this->in($c, function () {
            $this->assertSame(1, FiscalYear::count());
            $this->assertSame(12, AccountingPeriod::count());
            $first = AccountingPeriod::orderBy('bulan')->first();
            $this->assertSame('2026-01-01', $first->tanggal_mulai->toDateString());
            $this->assertSame('2026-01-31', $first->tanggal_selesai->toDateString());
        });
    }

    public function test_tahun_buku_tidak_harus_januari(): void
    {
        $c = $this->company(User::factory()->create(), ['bulan_mulai_tahun_buku' => 7, 'tanggal_mulai_pembukuan' => '2026-07-01']);

        $this->in($c, function () {
            $fy = FiscalYear::firstOrFail();
            $this->assertSame(2026, $fy->tahun);
            $this->assertSame('2026-07-01', $fy->tanggal_mulai->toDateString());
            $this->assertSame('2027-06-30', $fy->tanggal_selesai->toDateString());
            $this->assertSame('2027-06-30', AccountingPeriod::orderByDesc('bulan')->first()->tanggal_selesai->toDateString());
        });
    }

    public function test_tahun_buku_bertumpuk_atau_ganda_ditolak(): void
    {
        $c = $this->company(User::factory()->create());

        $this->in($c, function () {
            $this->expectException(DomainException::class);
            app(PeriodService::class)->createFiscalYear(2026);
        });
    }

    public function test_assert_postable_aturan_tanggal_dan_periode(): void
    {
        $c = $this->company(User::factory()->create(), ['tanggal_mulai_pembukuan' => '2026-03-01']);

        $this->in($c, function () {
            $svc = app(PeriodService::class);

            $this->assertSame(3, $svc->assertPostable('2026-03-15')->bulan);
            $this->assertFalse($svc->isPostable('2026-02-28')); // sebelum tanggal mulai pembukuan
            $this->assertFalse($svc->isPostable('2027-01-01')); // tahun buku belum dibuat
        });
    }

    public function test_periode_terkunci_menolak_dan_buka_kunci_butuh_alasan(): void
    {
        $owner = User::factory()->create();
        $c = $this->company($owner);

        $this->in($c, function () use ($owner) {
            $svc = app(PeriodService::class);
            $period = $svc->periodFor('2026-05-10');

            $svc->close($period, $owner);
            try {
                $svc->assertPostable('2026-05-10');
                $this->fail('Seharusnya ditolak');
            } catch (PeriodException $e) {
                $this->assertSame('period_closed', $e->errorCode);
            }

            $svc->reopen($period->fresh(), $owner, 'Koreksi akrual');
            $this->assertTrue($svc->isPostable('2026-05-10'));
            $this->assertDatabaseHas('audit_logs', ['entity_type' => 'accounting_period', 'aksi' => 'reopen', 'alasan' => 'Koreksi akrual']);
        });
    }

    public function test_hanya_owner_yang_boleh_buka_kunci_lewat_api(): void
    {
        $owner = User::factory()->create();
        $c = $this->company($owner);

        $periodId = $this->in($c, function () use ($owner) {
            $svc = app(PeriodService::class);
            $p = $svc->periodFor('2026-02-10');
            $svc->close($p, $owner);

            return $p->id;
        });

        $akuntan = User::factory()->create();
        CompanyUser::create([
            'company_id' => $c->id, 'user_id' => $akuntan->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', 'Akuntan')->value('id'),
        ]);

        $h = ['X-Company-Id' => $c->public_id];

        Sanctum::actingAs($akuntan);
        $this->postJson("/api/v1/periods/{$periodId}/reopen", ['alasan' => 'x'], $h)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/periods/{$periodId}/reopen", ['alasan' => 'Salah akun'], $h)->assertOk();
        $this->postJson("/api/v1/periods/{$periodId}/reopen", [], $h)->assertStatus(422); // alasan wajib
    }

    public function test_periode_perusahaan_lain_tidak_bisa_dibuka(): void
    {
        $ownerA = User::factory()->create();
        $a = $this->company($ownerA);
        $b = $this->company(User::factory()->create(), ['nama' => 'PT B']);

        $periodB = $this->in($b, fn () => AccountingPeriod::firstOrFail()->id);

        Sanctum::actingAs($ownerA);
        $this->postJson("/api/v1/periods/{$periodB}/reopen", ['alasan' => 'x'], ['X-Company-Id' => $a->public_id])
            ->assertNotFound();
    }
}
