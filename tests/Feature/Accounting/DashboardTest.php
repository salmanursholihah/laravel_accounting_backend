<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Reports\DashboardCache;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 6D: widget per izin, nilai widget, cache dan invalidasi, isolasi tenant. Data diisi langsung ke tabel. */
class DashboardTest extends TestCase
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

    private function makeCompany(User $owner, string $nama = 'PT Dash'): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => $nama, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function h(?Company $c = null): array
    {
        return ['X-Company-Id' => ($c ?? $this->co)->public_id];
    }

    private function dash(?Company $c = null): array
    {
        return $this->getJson('/api/v1/dashboard', $this->h($c))->assertOk()->json('data');
    }

    private function mkInsert(string $table, array $data, ?Company $c = null): int
    {
        $data['company_id'] = ($c ?? $this->co)->id;
        foreach (['created_at', 'updated_at'] as $col) {
            if (! array_key_exists($col, $data) && Schema::hasColumn($table, $col)) {
                $data[$col] = now();
            }
        }

        return (int) DB::table($table)->insertGetId($data);
    }

    private function mkContact(string $kode, bool $pelanggan): int
    {
        return $this->mkInsert('contacts', ['kode' => $kode, 'nama' => $kode, 'is_pelanggan' => $pelanggan, 'is_pemasok' => ! $pelanggan]);
    }

    private function mkInvoice(bool $jual, int $contact, string $nomor, string $tanggal, string $jatuhTempo, string $total, string $sisa, ?Company $c = null): int
    {
        $row = [
            'public_id' => (string) Str::ulid(), 'nomor' => $nomor, 'tanggal' => $tanggal, 'jatuh_tempo' => $jatuhTempo,
            'status' => 'posted', 'contact_id' => $contact, 'total' => $total, 'sisa_tagihan' => $sisa,
        ];
        if (! $jual) {
            $row['no_faktur_pemasok'] = $nomor;
        }

        return $this->mkInsert($jual ? 'sales_invoices' : 'purchase_invoices', $row, $c);
    }

    private function mkLowStock(string $kode, string $qty, string $min): void
    {
        $unit = $this->mkInsert('units', ['kode' => 'U' . $kode, 'nama' => 'pcs']);
        $wh = $this->mkInsert('warehouses', ['kode' => 'W' . $kode, 'nama' => 'Gudang ' . $kode]);
        $item = $this->mkInsert('items', ['public_id' => (string) Str::ulid(), 'kode' => $kode, 'nama' => 'Barang ' . $kode, 'tipe' => 'barang', 'unit_id' => $unit]);
        DB::table('stock_balances')->insert([
            'company_id' => $this->co->id, 'item_id' => $item, 'warehouse_id' => $wh,
            'qty_saldo' => $qty, 'nilai_saldo' => '999999', 'hpp_rata_rata' => '1234', 'stok_minimum' => $min, 'updated_at' => now(),
        ]);
    }

    private function memberWithRole(string $roleName): User
    {
        $u = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', $roleName)->value('id'),
        ]);

        return $u;
    }

    private function keys(User $u): array
    {
        Sanctum::actingAs($u);

        return array_keys($this->dash()['widgets']);
    }

    // ------------------------------------------------------------------ widget per izin

    public function test_owner_dan_akuntan_melihat_semua_widget(): void
    {
        $semua = ['kas_bank', 'laba_bulan_ini', 'penjualan_vs_pembelian', 'piutang', 'utang', 'penjualan_hari_ini', 'faktur_belum_dibayar',
            'tagihan_utang', 'stok_menipis', 'periode_belum_ditutup', 'draft_tersisa', 'menunggu_persetujuan'];

        $this->assertEqualsCanonicalizing($semua, $this->keys($this->owner));
        $this->assertEqualsCanonicalizing($semua, $this->keys($this->memberWithRole('Akuntan')));
    }

    public function test_kasir_hanya_widget_penjualan_dan_piutang(): void
    {
        $k = $this->keys($this->memberWithRole('Kasir'));

        foreach (['penjualan_hari_ini', 'faktur_belum_dibayar', 'piutang', 'menunggu_persetujuan'] as $ada) {
            $this->assertContains($ada, $k);
        }
        foreach (['kas_bank', 'laba_bulan_ini', 'penjualan_vs_pembelian', 'utang', 'tagihan_utang', 'stok_menipis', 'periode_belum_ditutup', 'draft_tersisa'] as $tidak) {
            $this->assertNotContains($tidak, $k);
        }
    }

    public function test_gudang_hanya_stok_menipis(): void
    {
        $k = $this->keys($this->memberWithRole('Gudang'));

        $this->assertContains('stok_menipis', $k);
        foreach (['kas_bank', 'laba_bulan_ini', 'piutang', 'utang', 'penjualan_hari_ini', 'faktur_belum_dibayar', 'tagihan_utang', 'periode_belum_ditutup'] as $tidak) {
            $this->assertNotContains($tidak, $k);
        }
    }

    public function test_purchasing_melihat_utang_tagihan_dan_stok_tapi_bukan_penjualan(): void
    {
        $k = $this->keys($this->memberWithRole('Purchasing'));

        foreach (['tagihan_utang', 'utang', 'stok_menipis', 'menunggu_persetujuan'] as $ada) {
            $this->assertContains($ada, $k);
        }
        foreach (['kas_bank', 'laba_bulan_ini', 'piutang', 'penjualan_hari_ini', 'faktur_belum_dibayar', 'periode_belum_ditutup'] as $tidak) {
            $this->assertNotContains($tidak, $k);
        }
    }

    public function test_tanpa_login_atau_bukan_anggota_ditolak(): void
    {
        $this->getJson('/api/v1/dashboard', $this->h())->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->assertContains($this->getJson('/api/v1/dashboard', $this->h())->status(), [403, 404]);
    }

    // ------------------------------------------------------------------ nilai widget

    public function test_nilai_widget_penjualan_dan_faktur_belum_dibayar(): void
    {
        $c = $this->mkContact('C-1', true);
        $today = now()->toDateString();
        $this->mkInvoice(true, $c, 'INV-1', $today, now()->addDays(30)->toDateString(), '1000', '1000');
        $this->mkInvoice(true, $c, 'INV-2', '2026-01-10', '2026-01-20', '500', '200'); // lewat jatuh tempo

        Sanctum::actingAs($this->owner);
        $w = $this->dash()['widgets'];

        $this->assertSame(1, $w['penjualan_hari_ini']['jumlah_faktur']);
        $this->assertSame('1000.0000', $w['penjualan_hari_ini']['total']);
        $this->assertSame(2, $w['faktur_belum_dibayar']['jumlah_faktur']);
        $this->assertSame('1200.0000', $w['faktur_belum_dibayar']['sisa']);
        $this->assertSame(1, $w['faktur_belum_dibayar']['jumlah_lewat_jatuh_tempo']);
        $this->assertSame('1500.0000', $w['piutang']['total']);
    }

    public function test_tagihan_utang_lewat_dan_jatuh_tempo_7_hari(): void
    {
        $s = $this->mkContact('S-1', false);
        $this->mkInvoice(false, $s, 'PI-1', '2026-01-10', now()->subDays(3)->toDateString(), '300', '300');   // lewat
        $this->mkInvoice(false, $s, 'PI-2', '2026-01-10', now()->addDays(3)->toDateString(), '400', '400');   // 7 hari
        $this->mkInvoice(false, $s, 'PI-3', '2026-01-10', now()->addDays(20)->toDateString(), '900', '900');  // di luar

        Sanctum::actingAs($this->owner);
        $t = $this->dash()['widgets']['tagihan_utang'];

        $this->assertSame(['jumlah_faktur' => 1, 'sisa' => '300.0000'], $t['lewat_jatuh_tempo']);
        $this->assertSame(['jumlah_faktur' => 1, 'sisa' => '400.0000'], $t['jatuh_tempo_7_hari']);
    }

    public function test_stok_menipis_tanpa_nilai_atau_hpp(): void
    {
        $this->mkLowStock('A', '3', '10');   // menipis
        $this->mkLowStock('B', '50', '10');  // aman
        $this->mkLowStock('C', '0', '0');    // tanpa minimum: diabaikan

        Sanctum::actingAs($this->memberWithRole('Gudang'));
        $s = $this->dash()['widgets']['stok_menipis'];

        $this->assertSame(1, $s['jumlah']);
        $this->assertSame('A', $s['teratas'][0]['kode']);
        $this->assertSame('3.0000', $s['teratas'][0]['qty_saldo']);
        $this->assertStringNotContainsString('hpp', json_encode($s));
        $this->assertStringNotContainsString('nilai', json_encode($s));
    }

    public function test_grafik_12_bulan_dan_periode_belum_ditutup(): void
    {
        Sanctum::actingAs($this->owner);
        $w = $this->dash()['widgets'];

        $this->assertCount(12, $w['penjualan_vs_pembelian']);
        $this->assertSame(now()->format('Y-m'), $w['penjualan_vs_pembelian'][11]['bulan']);
        $this->assertArrayHasKey('jumlah', $w['periode_belum_ditutup']);
        $this->assertArrayHasKey('saldo', $w['kas_bank']);
        $this->assertSame('0.0000', $w['kas_bank']['saldo']);
    }

    public function test_menunggu_persetujuan_berisi_angka(): void
    {
        Sanctum::actingAs($this->owner);

        $this->assertSame(0, $this->dash()['widgets']['menunggu_persetujuan']['jumlah']);
    }

    // ------------------------------------------------------------------ cache

    public function test_data_di_cache_sampai_versi_diganti(): void
    {
        $c = $this->mkContact('C-1', true);
        $today = now()->toDateString();
        $this->mkInvoice(true, $c, 'INV-1', $today, $today, '1000', '1000');

        Sanctum::actingAs($this->owner);
        $this->assertSame('1000.0000', $this->dash()['widgets']['penjualan_hari_ini']['total']);

        $this->mkInvoice(true, $c, 'INV-2', $today, $today, '500', '500');
        $this->assertSame('1000.0000', $this->dash()['widgets']['penjualan_hari_ini']['total'], 'masih cache');

        DashboardCache::bump($this->co->id);
        $this->assertSame('1500.0000', $this->dash()['widgets']['penjualan_hari_ini']['total']);
    }

    public function test_posting_jurnal_membatalkan_cache_kas_bank(): void
    {
        Sanctum::actingAs($this->owner);
        $this->assertSame('0.0000', $this->dash()['widgets']['kas_bank']['saldo']);

        $bank = $this->mkInsert('bank_accounts', ['account_id' => DB::table('accounts')->where('company_id', $this->co->id)->where('kode', '1-1110')->value('id')]);
        $this->assertGreaterThan(0, $bank);
        $this->assertSame('0.0000', $this->dash()['widgets']['kas_bank']['saldo']); // masih cache (rekening baru belum terlihat)

        $ak = fn (string $kode) => DB::table('accounts')->where('company_id', $this->co->id)->where('kode', $kode)->value('id');
        $this->postJson('/api/v1/journals', ['tanggal' => now()->toDateString(), 'memo' => 'Modal', 'lines' => [
            ['account_id' => $ak('1-1110'), 'debit' => '5000000'], ['account_id' => $ak('3-1000'), 'kredit' => '5000000'],
        ]], $this->h())->assertCreated();

        $k = $this->dash()['widgets']['kas_bank'];
        $this->assertSame('5000000.0000', $k['saldo']);
        $this->assertSame(1, $k['jumlah_rekening']);
    }

    // ------------------------------------------------------------------ tenant

    public function test_cache_dan_data_tidak_bocor_antar_perusahaan(): void
    {
        $c = $this->mkContact('C-1', true);
        $today = now()->toDateString();
        $this->mkInvoice(true, $c, 'INV-1', $today, $today, '1000', '1000');

        Sanctum::actingAs($this->owner);
        $this->assertSame('1000.0000', $this->dash()['widgets']['penjualan_hari_ini']['total']);

        $ownerB = User::factory()->create();
        $b = $this->makeCompany($ownerB, 'PT B');
        Sanctum::actingAs($ownerB);

        $this->assertSame('0.0000', $this->dash($b)['widgets']['penjualan_hari_ini']['total']);
    }
}
