<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Setup\SeedTaxAndTermDefaults;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data bawaan master Pajak dan Syarat Pembayaran saat perusahaan dibuat (config/tax_defaults.php). */
class SeedTaxAndTermDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['tax_defaults.seed_on_company_created' => true]); // TestCase dasar mematikannya
    }

    private function company(bool $pkp): Company
    {
        return app(CreateCompany::class)->handle(User::factory()->create(), [
            'nama' => 'PT Bawaan ' . uniqid(), 'is_pkp' => $pkp, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang',
        ]);
    }

    private function in(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, $fn);
    }

    public function test_perusahaan_pkp_mendapat_ppn_berversi_dengan_akun_dari_bagan_akun(): void
    {
        $c = $this->company(true);

        $this->in($c, function () {
            $this->assertSame(5, TaxCode::query()->count()); // PPN x2, PPN-MEWAH x2, PPN-0 x1

            $svc = app(TaxCodeService::class);
            $baru = $svc->resolveByKode('PPN', '2026-11-17');
            $this->assertSame('0.120000', (string) $baru->tarif);
            $this->assertSame([11, 12], [$baru->dpp_pembilang, $baru->dpp_penyebut]);
            $this->assertTrue($baru->is_aktif);
            $this->assertSame(Account::query()->where('kode', '2-1300')->value('id'), $baru->akun_penjualan_id); // PPN Keluaran
            $this->assertSame(Account::query()->where('kode', '1-1500')->value('id'), $baru->akun_pembelian_id); // PPN Masukan

            $lama = $svc->resolveByKode('PPN', '2024-06-01');
            $this->assertSame('0.110000', (string) $lama->tarif);
            $this->assertSame([1, 1], [$lama->dpp_pembilang, $lama->dpp_penyebut]);
            $this->assertSame('2024-12-31', $lama->berlaku_sampai->toDateString());

            $mewah = $svc->resolveByKode('PPN-MEWAH', '2026-11-17');
            $this->assertSame([1, 1], [$mewah->dpp_pembilang, $mewah->dpp_penyebut]); // DPP penuh
        });
    }

    public function test_perusahaan_non_pkp_semua_ppn_nonaktif(): void
    {
        $c = $this->company(false);

        $this->in($c, function () {
            $this->assertSame(5, TaxCode::query()->count());
            $this->assertSame(0, TaxCode::query()->where('is_aktif', true)->count());
        });
    }

    public function test_syarat_pembayaran_umum_tersedia(): void
    {
        $c = $this->company(true);

        $this->in($c, function () {
            $this->assertSame(6, PaymentTerm::query()->count());
            $tunai = PaymentTerm::query()->where('nama', 'Tunai')->first();
            $this->assertSame('2026-11-17', $tunai->jatuhTempo('2026-11-17')->toDateString());

            $cepat = PaymentTerm::query()->where('nama', '2/10 Net 30')->first();
            $this->assertSame('2026-12-17', $cepat->jatuhTempo('2026-11-17')->toDateString());
            $this->assertSame('2026-11-27', $cepat->batasDiskon('2026-11-17')->toDateString());
        });
    }

    public function test_penyalinan_idempotent_dan_tidak_menimpa_perubahan_perusahaan(): void
    {
        $c = $this->company(true);

        $this->in($c, function () use ($c) {
            PaymentTerm::query()->where('nama', 'Net 30')->update(['jumlah_hari' => 45]); // perusahaan mengubah sendiri

            $hasil = app(SeedTaxAndTermDefaults::class)->handle($c);

            $this->assertSame(['tax_versions' => 0, 'payment_terms' => 0], $hasil);
            $this->assertSame(5, TaxCode::query()->count());
            $this->assertSame(45, PaymentTerm::query()->where('nama', 'Net 30')->value('jumlah_hari'));
        });
    }

    public function test_ppn_bawaan_langsung_bisa_dipakai_menghitung_faktur(): void
    {
        $c = $this->company(true);

        $r = $this->in($c, function () {
            $id = app(TaxCodeService::class)->resolveByKode('PPN', '2026-11-17')->id;

            return app(TaxCalculator::class)->calculate([
                'tanggal' => '2026-11-17',
                'lines' => [['qty' => '10', 'harga' => '1000000', 'tax_code_id' => $id]],
            ]);
        });

        $this->assertSame('9166667.0000', $r['dpp']);
        $this->assertSame('1100000.0000', $r['total_pajak']);
        $this->assertSame('11100000.0000', $r['total']);
    }

    public function test_saklar_config_mematikan_penyalinan(): void
    {
        config(['tax_defaults.seed_on_company_created' => false]);
        $c = $this->company(true);

        $this->in($c, fn () => $this->assertSame(0, TaxCode::query()->count()));
    }
}
