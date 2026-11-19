<?php

namespace Tests\Feature\Stock;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Sales\Customers\CustomerService;
use App\Modules\Sales\Invoices\SalesInvoiceService;
use App\Modules\Stock\Adjustments\StockAdjustmentService;
use App\Modules\Stock\Masters\StockMasterService;
use App\Modules\Stock\Recalc\StockRecalcService;
use App\Modules\Stock\StockException;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Step 7A: hitung ulang HPP transaksi mundur. Invarian yang dijaga di tiap tes yang melakukan hitung ulang:
 *   saldo akun Persediaan di buku besar = jumlah stock_balances.nilai_saldo; saldo akun HPP = jumlah nilai gerakan keluar penjualan.
 * BELUM pernah dijalankan oleh penulisnya (tanpa MySQL/vendor); jalankan di MySQL 8 asli.
 */
class StockRecalcTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $unit;
    private int $gudang;
    private int $item;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tax_defaults.seed_on_company_created' => true]);
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = $this->newCompany($this->owner);
        $this->as($this->owner, $this->co);

        $m = app(StockMasterService::class);
        $this->unit = (int) $m->create('units', ['kode' => 'PCS', 'nama' => 'Pcs'], $this->owner)->id;
        $this->gudang = (int) $m->create('warehouses', ['kode' => 'GU', 'nama' => 'Gudang Utama', 'is_default' => true], $this->owner)->id;
        $this->item = (int) $m->create('items', ['kode' => 'BRS-5', 'nama' => 'Beras 5kg', 'unit_id' => $this->unit, 'harga_jual' => '1000000'], $this->owner)->id;
        $this->customer = (int) app(CustomerService::class)->create(['kode' => 'C-0001', 'nama' => 'CV Sinar'], $this->owner)['contact_id'];
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => 'PT Recalc ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function as(User $u, ?Company $c = null): void
    {
        $c ??= $this->co;
        app(CurrentCompany::class)->set($c, CompanyUser::where(['company_id' => $c->id, 'user_id' => $u->id])->firstOrFail());
        app(\App\Modules\Platform\Access\PermissionChecker::class)->flush();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create(['company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active', 'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id')]);

        return $u;
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    private function svc(): StockRecalcService
    {
        return app(StockRecalcService::class);
    }

    private function acc(string $key): int
    {
        return (int) app(SystemAccountService::class)->resolve($key)->getKey();
    }

    /** Penyesuaian Persediaan terposting; qty bertanda, harga hanya untuk selisih plus. */
    private function adj(string $tanggal, string $qty, ?string $harga = null): int
    {
        $line = ['item_id' => $this->item, 'qty_selisih' => $qty] + ($harga !== null ? ['harga_satuan' => $harga] : []);
        $id = (int) app(StockAdjustmentService::class)->create(['tanggal' => $tanggal, 'warehouse_id' => $this->gudang, 'alasan' => 'Uji', 'lines' => [$line]], $this->owner)->id;
        $this->orch()->post('stock_adjustment', $id, $this->owner);

        return $id;
    }

    private function jual(string $tanggal, string $qty): int
    {
        $id = (int) app(SalesInvoiceService::class)->create(['tanggal' => $tanggal, 'contact_id' => $this->customer, 'lines' => [['item_id' => $this->item, 'qty' => $qty, 'tax_code_id' => null]]], $this->owner)->id;
        $this->orch()->post('sales_invoice', $id, $this->owner);

        return $id;
    }

    private function net(string $key): string
    {
        return (string) DB::table('journal_lines')->where('company_id', $this->co->id)->where('account_id', $this->acc($key))->selectRaw('COALESCE(SUM(debit - kredit), 0) as s')->value('s');
    }

    private function bal(): object
    {
        return DB::table('stock_balances')->where('item_id', $this->item)->where('warehouse_id', $this->gudang)->first();
    }

    private function assertMoney(string $expected, mixed $actual, string $m = ''): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$m} diharapkan {$expected}, didapat {$actual}");
    }

    private function recalcJournals(): int
    {
        return DB::table('journals')->where('company_id', $this->co->id)->where('source_type', 'stock_recalc')->count();
    }

    /** Buku besar harus sama dengan sub-ledger stok. */
    private function assertLedgerMatchesStock(): void
    {
        $this->assertMoney((string) DB::table('stock_balances')->where('company_id', $this->co->id)->sum('nilai_saldo'), $this->net('persediaan'), 'Persediaan GL = nilai stok');
    }

    /** Beli 10 @100 (03-01), jual 5 (03-10, HPP 500), lalu beli mundur 10 @200 (03-05) sehingga HPP penjualan seharusnya 750. */
    private function stageBackdated(): int
    {
        $this->adj('2026-03-01', '10', '100');
        $inv = $this->jual('2026-03-10', '5');
        $this->adj('2026-03-05', '10', '200');

        return $inv;
    }

    // ----------------------------------------------------------------------------- inti

    public function test_pembelian_mundur_menandai_lalu_hitung_ulang_memperbaiki_hpp_penjualan_dan_menjurnal_selisih(): void
    {
        $inv = $this->stageBackdated();

        $b = $this->bal();
        $this->assertTrue((bool) $b->perlu_hitung_ulang);
        $this->assertSame('2026-03-05', (string) $b->hitung_ulang_dari);
        $this->assertMoney('500', $this->net('hpp'), 'sebelum hitung ulang');
        $this->assertCount(1, $this->svc()->pending());

        $r = $this->svc()->run($this->item, $this->gudang);

        $this->assertSame('selesai', $r['status']);
        $this->assertSame(2, $r['baris_berubah'], 'baris penjualan (nilai) dan baris beli mundur (saldo berjalan)');
        $this->assertCount(1, $r['jurnal'], 'hanya penjualan yang punya selisih nilai');

        $out = DB::table('stock_ledger')->where(['source_type' => 'sales_invoice', 'source_id' => $inv])->first();
        $this->assertMoney('750', $out->nilai);
        $this->assertMoney('150', $out->harga_satuan);

        $b = $this->bal();
        $this->assertFalse((bool) $b->perlu_hitung_ulang);
        $this->assertNull($b->hitung_ulang_dari);
        $this->assertMoney('15', $b->qty_saldo);
        $this->assertMoney('2250', $b->nilai_saldo);
        $this->assertMoney('150', $b->hpp_rata_rata);

        $this->assertMoney('750', $this->net('hpp'), 'HPP di buku besar mengikuti hitungan ulang');
        $this->assertLedgerMatchesStock();

        $j = DB::table('journals')->where(['source_type' => 'stock_recalc', 'source_id' => $r['run_id']])->first();
        $this->assertSame('2026-03-10', (string) $j->tanggal, 'tanggal gerakan asal');
        $this->assertSame('auto', $j->tipe);
        $this->assertSame(0, DB::table('stock_recalc_runs')->where('status', 'gagal')->count());
    }

    public function test_hitung_ulang_idempoten_dan_tanpa_penanda_tidak_membuat_jurnal(): void
    {
        $this->stageBackdated();
        $this->svc()->run($this->item, $this->gudang);
        $jurnal = DB::table('journals')->where('company_id', $this->co->id)->count();

        $this->assertSame('tanpa_perubahan', $this->svc()->run($this->item, $this->gudang)['status']); // tanpa penanda

        DB::table('stock_balances')->where('id', $this->bal()->id)->update(['perlu_hitung_ulang' => 1, 'hitung_ulang_dari' => '2026-03-01']); // dipaksa
        $r = $this->svc()->run($this->item, $this->gudang);
        $this->assertSame('tanpa_perubahan', $r['status']);
        $this->assertSame(0, $r['baris_berubah']);
        $this->assertSame($jurnal, DB::table('journals')->where('company_id', $this->co->id)->count(), 'tidak menggandakan jurnal');
        $this->assertLedgerMatchesStock();
    }

    public function test_faktur_yang_sudah_di_void_tidak_menimbulkan_selisih_bersih(): void
    {
        $this->adj('2026-03-01', '10', '100');
        $inv = $this->jual('2026-03-10', '5');
        $this->orch()->void('sales_invoice', $inv, 'Salah input', $this->owner);
        $this->adj('2026-03-05', '10', '200');

        $r = $this->svc()->run($this->item, $this->gudang);

        $this->assertSame([], $r['jurnal'], 'baris asal dan pembaliknya berubah sama besar sehingga saling meniadakan');
        $this->assertMoney('0', $this->net('hpp'));
        $this->assertMoney('20', $this->bal()->qty_saldo);
        $this->assertMoney('3000', $this->bal()->nilai_saldo);
        $this->assertLedgerMatchesStock();
    }

    // ----------------------------------------------------------------------------- penolakan

    public function test_hitung_ulang_ditolak_bila_rentang_menyentuh_periode_closed_tanpa_mengubah_apa_pun(): void
    {
        $inv = $this->stageBackdated();
        $p = app(PeriodService::class)->periodFor('2026-03-10');
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($p->id), $this->owner);

        try {
            $this->svc()->run($this->item, $this->gudang);
            $this->fail('Seharusnya ditolak.');
        } catch (StockException $e) {
            $this->assertSame('closed_range', $e->errorCode ?? 'closed_range');
        }

        $this->assertTrue((bool) $this->bal()->perlu_hitung_ulang, 'penanda tetap menyala');
        $this->assertMoney('500', DB::table('stock_ledger')->where(['source_type' => 'sales_invoice', 'source_id' => $inv])->value('nilai'));
        $this->assertSame(0, $this->recalcJournals());
        $this->assertSame(1, DB::table('stock_recalc_runs')->where('status', 'gagal')->count());
    }

    public function test_stok_minus_pada_urutan_kronologis_membatalkan_seluruh_hitung_ulang(): void
    {
        $this->adj('2026-03-01', '10', '100');
        $this->adj('2026-03-02', '-9');
        $this->adj('2026-03-20', '10', '100');   // saldo kini 11
        $this->adj('2026-03-03', '-5');          // lolos saat posting (11 >= 5) tetapi pada 03-03 saldo hanya 1
        $saldoLedger = DB::table('stock_ledger')->where('item_id', $this->item)->orderBy('id')->pluck('nilai_saldo')->all();

        try {
            $this->svc()->run($this->item, $this->gudang);
            $this->fail('Seharusnya ditolak.');
        } catch (StockException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode ?? 'insufficient_stock');
        }

        $this->assertTrue((bool) $this->bal()->perlu_hitung_ulang);
        $this->assertSame($saldoLedger, DB::table('stock_ledger')->where('item_id', $this->item)->orderBy('id')->pluck('nilai_saldo')->all(), 'ledger tidak berubah');
        $this->assertSame(1, DB::table('stock_recalc_runs')->where('status', 'gagal')->count());
    }

    // ----------------------------------------------------------------------------- hak akses, isolasi, jadwal

    public function test_http_hanya_owner_dan_akuntan_menjalankan_dan_perusahaan_lain_tidak_terlihat(): void
    {
        $this->stageBackdated();
        $h = ['X-Company-Id' => $this->co->public_id];
        $payload = ['item_id' => $this->item, 'warehouse_id' => $this->gudang];

        Sanctum::actingAs($this->member('Kasir'));
        $this->withHeaders($h)->postJson('/api/v1/stock/recalc', $payload)->assertForbidden();

        $other = User::factory()->create();
        $coB = $this->newCompany($other);
        Sanctum::actingAs($other);
        $hb = ['X-Company-Id' => $coB->public_id];
        $this->withHeaders($hb)->getJson('/api/v1/stock/recalc/pending')->assertOk()->assertJsonCount(0, 'data');
        $res = $this->withHeaders($hb)->postJson('/api/v1/stock/recalc', $payload);
        $this->assertGreaterThanOrEqual(400, $res->status(), 'barang/gudang perusahaan lain tidak ditemukan');
        $this->assertTrue((bool) $this->bal()->perlu_hitung_ulang, 'data perusahaan A tidak tersentuh');

        Sanctum::actingAs($this->owner);
        $this->withHeaders($h)->getJson('/api/v1/stock/recalc/pending')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->postJson('/api/v1/stock/recalc', $payload)->assertOk()->assertJsonPath('data.status', 'selesai');
        $this->withHeaders($h)->getJson('/api/v1/stock/recalc/pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_perintah_artisan_menghitung_ulang_semua_perusahaan_dan_mengembalikan_gagal_bila_ada(): void
    {
        $this->stageBackdated();

        $this->artisan('stock:recalc')->assertSuccessful();

        $this->assertFalse((bool) $this->bal()->perlu_hitung_ulang);
        $this->assertMoney('750', $this->net('hpp'));
        $this->assertLedgerMatchesStock();
    }
}
