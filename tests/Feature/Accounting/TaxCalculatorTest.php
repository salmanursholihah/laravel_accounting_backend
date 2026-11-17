<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Accounting\Tax\TaxException;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ALG-03. Tarif/DPP selalu dari master pajak (data), tidak ada angka tarif di kode produksi. */
class TaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function company(bool $pkp = true): Company
    {
        return app(CreateCompany::class)->handle(User::factory()->create(), [
            'nama' => 'PT Hitung ' . uniqid(), 'is_pkp' => $pkp, 'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
    }

    /** Siapkan master pajak di perusahaan aktif (sekali per perusahaan), lalu jalankan $fn. */
    private function run(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, function () use ($fn) {
            if (! \App\Modules\Accounting\Tax\TaxCode::query()->where('kode', 'PPN')->exists()) {
                $t = app(TaxCodeService::class);
                // PPN: 10% DPP penuh sampai 2024, lalu 12% DPP 11/12 (data uji; versi lama sengaja beda)
                $lama = $t->create(['kode' => 'PPN', 'nama' => 'PPN', 'jenis' => 'ppn', 'tarif' => '0.10', 'dpp_pembilang' => 1, 'dpp_penyebut' => 1, 'berlaku_dari' => '2022-04-01']);
                $baru = $t->create(['kode' => 'PPN', 'nama' => 'PPN', 'jenis' => 'ppn', 'tarif' => '0.12', 'dpp_pembilang' => 11, 'dpp_penyebut' => 12, 'berlaku_dari' => '2025-01-01']);
                $p11 = $t->create(['kode' => 'PPN11', 'nama' => 'PPN 11', 'jenis' => 'ppn', 'tarif' => '0.11', 'berlaku_dari' => '2020-01-01']);
                $bm = $t->create(['kode' => 'BM10', 'nama' => 'PPnBM 10', 'jenis' => 'ppnbm', 'tarif' => '0.10', 'berlaku_dari' => '2020-01-01']);
                $this->id = ['lama' => $lama->id, 'baru' => $baru->id, 'p11' => $p11->id, 'bm' => $bm->id];
            }

            return $fn(app(TaxCalculator::class));
        });
    }

    public function test_kasus_wajib_10_juta_tarif_12_dpp_11_per_12(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17',
            'lines' => [['qty' => '1', 'harga' => '10000000', 'tax_code_id' => $this->id['baru']]],
        ]));

        $this->assertSame('9166667', $r['pajak']['PPN']['dpp']);
        $this->assertSame('9166667.0000', $r['dpp']);
        $this->assertSame('1100000.0000', $r['total_pajak']);
        $this->assertSame('11100000.0000', $r['total']);
        $this->assertSame('0.0000', $r['pembulatan']);
    }

    public function test_harga_termasuk_pajak_menghitung_mundur_dan_total_konsisten(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'harga_termasuk_pajak' => true,
            'lines' => [['qty' => '1', 'harga' => '11100000', 'tax_code_id' => $this->id['baru']]],
        ]));

        $this->assertSame('10000000.0000', $r['dasar']);
        $this->assertSame('1100000.0000', $r['total_pajak']);
        $this->assertSame('11100000.0000', $r['total']); // sama dengan kasus belum termasuk pajak
    }

    public function test_tarif_lama_dipakai_untuk_tanggal_lama(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2024-06-01', // pakai id versi BARU, tanggal memilih versi lama
            'lines' => [['qty' => '1', 'harga' => '10000000', 'tax_code_id' => $this->id['baru']]],
        ]));

        $this->assertSame('10000000.0000', $r['dpp']);       // DPP penuh, bukan 11/12
        $this->assertSame('1000000.0000', $r['total_pajak']); // 10%
        $this->assertSame('11000000.0000', $r['total']);
    }

    public function test_non_pkp_pajak_nol(): void
    {
        $c = $this->company(false);
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17',
            'lines' => [['qty' => '1', 'harga' => '10000000', 'tax_code_id' => $this->id['baru']]],
        ]));

        $this->assertSame('0.0000', $r['total_pajak']);
        $this->assertSame([], $r['pajak']);
        $this->assertSame('10000000.0000', $r['total']);

        // harga termasuk pajak pada non-PKP: tidak dihitung mundur
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'harga_termasuk_pajak' => true,
            'lines' => [['qty' => '1', 'harga' => '11100000', 'tax_code_id' => $this->id['baru']]],
        ]));
        $this->assertSame('11100000.0000', $r['dasar']);
        $this->assertSame('11100000.0000', $r['total']);
    }

    public function test_diskon_baris_dan_diskon_header_proporsional(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'diskon_header' => '300',
            'lines' => [
                ['qty' => 1, 'harga' => 1000, 'tax_code_id' => $this->id['baru']],
                ['qty' => 1, 'harga' => 2000, 'tax_code_id' => $this->id['baru']],
            ],
        ]));

        $this->assertSame('900.0000', $r['lines'][0]['netto_setelah_diskon']);   // 1000 - 100
        $this->assertSame('1800.0000', $r['lines'][1]['netto_setelah_diskon']);  // 2000 - 200
        $this->assertSame('2700.0000', $r['dasar']);
        $this->assertSame('2475.0000', $r['pajak']['PPN']['dpp_presisi']);       // 2700 x 11/12
        $this->assertSame('297.0000', $r['total_pajak']);
        $this->assertSame('2997.0000', $r['total']);

        // diskon baris persen: 2 x 1000 x (1 - 10%) = 1800
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'lines' => [['qty' => 2, 'harga' => 1000, 'diskon_persen' => '10']],
        ]));
        $this->assertSame('1800.0000', $r['total']);
    }

    public function test_pembulatan_pajak_per_dokumen_bukan_per_baris(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17',
            'lines' => array_fill(0, 3, ['qty' => 1, 'harga' => 15, 'tax_code_id' => $this->id['p11']]), // 3 x 1,65
        ]));

        $this->assertSame('5.0000', $r['total_pajak']); // bukan 3 x round(1,65) = 6
        $this->assertSame('50.0000', $r['total']);
        // rincian per baris menjumlah tepat ke pajak dokumen
        $sum = Money::sum(array_map(fn ($l) => $l['pajak'][0]['pajak'], $r['lines']));
        $this->assertSame('5.0000', Money::round($sum));
    }

    public function test_selisih_pembulatan_total_ke_akun_pembulatan(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'lines' => [['qty' => '1.5', 'harga' => '333']], // 499,5
        ]));

        $this->assertSame('499.5000', $r['dasar']);
        $this->assertSame('500.0000', $r['total']);
        $this->assertSame('0.5000', $r['pembulatan']);
        // identitas: total = dasar + pajak + ongkir + pembulatan
        $this->assertSame('500.0000', Money::round(Money::sum([$r['dasar'], $r['total_pajak'], $r['ongkir'], $r['pembulatan']])));
    }

    public function test_ppn_dan_ppnbm_bersamaan_dan_ongkir(): void
    {
        $c = $this->company();
        $r = $this->run($c, fn (TaxCalculator $calc) => $calc->calculate([
            'tanggal' => '2026-11-17', 'ongkir' => '50000',
            'lines' => [['qty' => 1, 'harga' => 1000000, 'tax_code_ids' => [$this->id['p11'], $this->id['bm']]]],
        ]));

        $this->assertSame('110000', $r['pajak']['PPN11']['pajak']);
        $this->assertSame('100000', $r['pajak']['BM10']['pajak']);
        $this->assertSame('210000.0000', $r['total_pajak']);
        $this->assertSame('1260000.0000', $r['total']); // 1.000.000 + 210.000 + ongkir 50.000
        $this->assertSame('1000000.0000', $r['dpp']);   // hanya DPP kelompok PPN
    }

    public function test_masukan_tidak_valid_dan_kode_nonaktif_ditolak(): void
    {
        $c = $this->company();

        $this->run($c, function (TaxCalculator $calc) {
            foreach ([
                ['diskon_header' => '999999999'],
                ['lines' => [['qty' => -1, 'harga' => 10]]],
                ['lines' => [['qty' => 1, 'harga' => 10, 'diskon_persen' => '101']]],
            ] as $bad) {
                try {
                    $calc->calculate($bad + ['tanggal' => '2026-11-17', 'lines' => [['qty' => 1, 'harga' => 10]]]);
                    $this->fail('Harus ditolak: ' . json_encode($bad));
                } catch (TaxException) {
                    $this->assertTrue(true);
                }
            }

            app(TaxCodeService::class)->update(\App\Modules\Accounting\Tax\TaxCode::findOrFail($this->id['p11']), ['is_aktif' => false]);
            $this->expectException(TaxException::class);
            $calc->calculate(['tanggal' => '2026-11-17', 'lines' => [['qty' => 1, 'harga' => 10, 'tax_code_id' => $this->id['p11']]]]);
        });
    }

    public function test_money_pembulatan_half_up_menjauhi_nol(): void
    {
        $this->assertSame('3', Money::round('2.5', 0));
        $this->assertSame('-3', Money::round('-2.5', 0));
        $this->assertSame('0.0000', Money::round('-0.00004'));
        $this->assertSame('1.0001', Money::round('1.00005'));
    }
}
