<?php

namespace Tests\Feature\Stock;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Ledger\JournalException;
use App\Modules\Accounting\Ledger\OpeningBalanceService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Ledger\StockLedgerService;
use App\Modules\Stock\Masters\StockMasterService;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\OpeningStock\OpeningStockService;
use App\Modules\Stock\StockException;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Saldo awal stok: ikut jurnal saldo awal (satu jurnal), stock_ledger = jurnal, void membalik keduanya. */
class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $unit;
    private int $gudang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
        $this->co = app(CreateCompany::class)->handle($this->owner, ['nama' => 'PT Awal ' . uniqid(), 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
        app(CurrentCompany::class)->set($this->co, CompanyUser::where(['company_id' => $this->co->id, 'user_id' => $this->owner->id])->firstOrFail());

        $m = app(StockMasterService::class);
        $this->unit = (int) $m->create('units', ['kode' => 'PCS', 'nama' => 'Pcs'], $this->owner)->id;
        $this->gudang = (int) $m->create('warehouses', ['kode' => 'GU', 'nama' => 'Gudang Utama', 'is_default' => true], $this->owner)->id;
    }

    // ----------------------------------------------------------------------------- pembantu

    private function item(string $kode, string $tipe = 'barang'): Item
    {
        return app(StockMasterService::class)->create('items', ['kode' => $kode, 'nama' => "Barang {$kode}", 'tipe' => $tipe, 'unit_id' => $this->unit], $this->owner);
    }

    private function acc(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private function svc(): OpeningStockService
    {
        return app(OpeningStockService::class);
    }

    private function row(Item $i, string $qty, string $harga): array
    {
        return ['item_id' => (int) $i->id, 'warehouse_id' => $this->gudang, 'qty' => $qty, 'harga_satuan' => $harga];
    }

    /** modal manual agar jurnal seimbang */
    private function modal(string $jumlah): void
    {
        app(OpeningBalanceService::class)->save([['account_id' => $this->acc('3-1000'), 'jumlah' => $jumlah]], $this->owner);
    }

    private function persediaanDebit(): string
    {
        $id = (int) app(SystemAccountService::class)->resolve('persediaan')->getKey();

        return (string) DB::table('journal_lines')->where('account_id', $id)->sum('debit');
    }

    private function bal(Item $i): object
    {
        return DB::table('stock_balances')->where('item_id', $i->id)->where('warehouse_id', $this->gudang)->first();
    }

    // ----------------------------------------------------------------------------- posting

    public function test_saldo_awal_stok_masuk_satu_jurnal_dan_stock_ledger_sama_dengan_jurnal(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $this->svc()->save([$this->row($a, '10', '100'), $this->row($b, '5', '200')], $this->owner);
        $this->modal('2000');

        $j = app(OpeningBalanceService::class)->post($this->owner);

        $this->assertSame('opening', $j->tipe);
        $this->assertSame('2000.0000', $j->total_debit);
        $this->assertEquals(2000, $this->persediaanDebit());
        $this->assertEquals(2000, DB::table('stock_ledger')->where('source_type', 'opening_balance')->where('source_id', $j->id)->sum('nilai'));

        $this->assertEquals(10, $this->bal($a)->qty_saldo);
        $this->assertEquals(100, $this->bal($a)->hpp_rata_rata);
        $this->assertEquals(200, $this->bal($b)->hpp_rata_rata);
        $this->assertSame(0, DB::table('opening_stock_lines')->whereNull('journal_id')->count());
        $this->assertSame(1, Journal::query()->where('is_opening', true)->count()); // tetap SATU jurnal saldo awal
    }

    public function test_hpp_rata_rata_berlanjut_dari_saldo_awal(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '10', '100')], $this->owner);
        $this->modal('1000');
        app(OpeningBalanceService::class)->post($this->owner);

        // beli 10 @ 120 -> HPP baru (1000 + 1200) / 20 = 110 (ALG-05)
        app(StockLedgerService::class)->apply('uji', 1, '2026-02-01', [['item_id' => (int) $a->id, 'warehouse_id' => $this->gudang, 'arah' => 'in', 'qty' => '10', 'harga_satuan' => '120']]);
        $this->assertEquals(110, $this->bal($a)->hpp_rata_rata);
    }

    public function test_akun_persediaan_override_barang_dipakai(): void
    {
        $a = $this->item('A');
        $override = $this->acc('1-1120'); // akun aset lain, sebagai override uji
        Item::query()->whereKey($a->id)->update(['akun_persediaan_id' => $override]);
        $this->svc()->save([$this->row($a, '4', '250')], $this->owner);

        $this->assertSame([$override => '1000.0000'], $this->svc()->draftAccountAmounts());
    }

    public function test_baris_manual_pada_akun_persediaan_ditolak_bila_ada_draf_stok(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '1', '100')], $this->owner);
        $persediaan = (int) app(SystemAccountService::class)->resolve('persediaan')->getKey();
        app(OpeningBalanceService::class)->save([
            ['account_id' => $persediaan, 'jumlah' => '100'], ['account_id' => $this->acc('3-1000'), 'jumlah' => '100'],
        ], $this->owner);

        try {
            app(OpeningBalanceService::class)->post($this->owner);
            $this->fail('Seharusnya ditolak');
        } catch (JournalException $e) {
            $this->assertSame('stock_account_conflict', $e->errorCode);
        }
        $this->assertSame(0, DB::table('journals')->count());
    }

    public function test_jurnal_tidak_seimbang_membatalkan_stok_dan_draf_tetap(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '10', '100')], $this->owner);
        $this->modal('500'); // modal tidak sama dengan nilai stok (1000): tidak seimbang

        try {
            app(OpeningBalanceService::class)->post($this->owner);
            $this->fail('Seharusnya ditolak');
        } catch (JournalException $e) {
            $this->assertSame('unbalanced', $e->errorCode);
        }

        $this->assertSame(0, DB::table('stock_ledger')->count());
        $this->assertSame(0, DB::table('stock_balances')->count());
        $this->assertSame(1, DB::table('opening_stock_lines')->whereNull('journal_id')->count());
    }

    public function test_saldo_awal_kosong_total_ditolak(): void
    {
        try {
            app(OpeningBalanceService::class)->post($this->owner);
            $this->fail('Seharusnya ditolak');
        } catch (JournalException $e) {
            $this->assertSame('opening_empty', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- simpan draf

    public function test_validasi_draf_jasa_qty_nol_duplikat_dan_gudang_nonaktif(): void
    {
        $a = $this->item('A');
        $jasa = $this->item('JS', 'jasa');

        $cases = [
            'not_stock_item' => [$this->row($jasa, '1', '10')],
            'bad_qty' => [$this->row($a, '0', '10')],
            'duplicate_row' => [$this->row($a, '1', '10'), $this->row($a, '2', '10')],
            'invalid_amount' => [$this->row($a, '1', '-5')],
        ];
        foreach ($cases as $code => $rows) {
            try {
                $this->svc()->save($rows, $this->owner);
                $this->fail("Seharusnya ditolak: {$code}");
            } catch (StockException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
        $this->assertSame(0, DB::table('opening_stock_lines')->count());
    }

    public function test_draf_diganti_utuh_dan_tidak_bisa_diubah_setelah_posting(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '1', '10')], $this->owner);
        $this->svc()->save([$this->row($a, '3', '10')], $this->owner); // mengganti, bukan menambah
        $this->assertSame(1, DB::table('opening_stock_lines')->count());
        $this->assertEquals(3, DB::table('opening_stock_lines')->value('qty'));

        $this->modal('30');
        app(OpeningBalanceService::class)->post($this->owner);

        try {
            $this->svc()->save([], $this->owner);
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('opening_stock_posted', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- void

    public function test_void_saldo_awal_membalik_stok_dan_jurnal_lalu_bisa_diposting_ulang(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '10', '100')], $this->owner);
        $this->modal('1000');
        $j = app(OpeningBalanceService::class)->post($this->owner);

        app(OpeningBalanceService::class)->void('Salah harga', $this->owner);

        $this->assertEquals(0, $this->bal($a)->qty_saldo);
        $this->assertEquals(0, $this->bal($a)->nilai_saldo);
        $this->assertEquals(0, DB::table('stock_ledger')->sum(DB::raw('qty_masuk - qty_keluar')));
        $this->assertSame(1, DB::table('opening_stock_lines')->whereNull('journal_id')->count()); // kembali draf

        $this->svc()->save([$this->row($a, '10', '110')], $this->owner);
        $j2 = app(OpeningBalanceService::class)->post($this->owner);
        $this->assertNotSame($j->id, $j2->id);
        $this->assertEquals(110, $this->bal($a)->hpp_rata_rata);
    }

    public function test_void_ditolak_dan_seluruhnya_batal_bila_stok_awal_sudah_terpakai(): void
    {
        $a = $this->item('A');
        $this->svc()->save([$this->row($a, '10', '100')], $this->owner);
        $this->modal('1000');
        $j = app(OpeningBalanceService::class)->post($this->owner);

        // semua stok terjual: membalik saldo awal tidak mungkin
        app(StockLedgerService::class)->apply('uji', 2, '2026-02-01', [['item_id' => (int) $a->id, 'warehouse_id' => $this->gudang, 'arah' => 'out', 'qty' => '10']]);

        try {
            app(OpeningBalanceService::class)->void('Salah', $this->owner);
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame(0, Journal::query()->where('tipe', 'reversal')->count()); // jurnal tidak ikut terbalik
        $this->assertSame(1, DB::table('opening_stock_lines')->where('journal_id', $j->id)->count());
    }

    // ----------------------------------------------------------------------------- HTTP dan hak akses

    public function test_http_simpan_lihat_total_dan_hak_akses(): void
    {
        $a = $this->item('A');
        $h = ['X-Company-Id' => $this->co->public_id];

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/opening-stock', ['rows' => [$this->row($a, '10', '100.50')]], $h)->assertOk()->assertJsonPath('data.baris', 1);
        $r = $this->getJson('/api/v1/opening-stock', $h)->assertOk();
        $r->assertJsonPath('data.status', 'draft');
        $this->assertSame('1005.0000', $r->json('data.total_nilai'));

        $kasir = User::factory()->create();
        CompanyUser::create(['company_id' => $this->co->id, 'user_id' => $kasir->id, 'status' => 'active', 'role_id' => Role::whereNull('company_id')->where('nama', 'Kasir')->value('id')]);
        Sanctum::actingAs($kasir);
        $this->getJson('/api/v1/opening-stock', $h)->assertForbidden();
        $this->putJson('/api/v1/opening-stock', ['rows' => []], $h)->assertForbidden();
    }
}
