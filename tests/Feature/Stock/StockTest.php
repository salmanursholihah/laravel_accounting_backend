<?php

namespace Tests\Feature\Stock;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Periods\PeriodException;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Posting\StockMovementApplier;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Adjustments\StockAdjustmentService;
use App\Modules\Stock\Ledger\StockLedgerService;
use App\Modules\Stock\Masters\StockMasterService;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\StockAdjustment;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockException;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inti stok: HPP rata-rata (ALG-05), Penyesuaian Persediaan, laporan, master, hak akses, isolasi, periode terkunci. */
class StockTest extends TestCase
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
        $this->co = $this->newCompany($this->owner);
        $this->as($this->owner, $this->co);

        $m = app(StockMasterService::class);
        $this->unit = (int) $m->create('units', ['kode' => 'PCS', 'nama' => 'Pcs'], $this->owner)->id;
        $this->gudang = (int) $m->create('warehouses', ['kode' => 'GU', 'nama' => 'Gudang Utama', 'is_default' => true], $this->owner)->id;
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => 'PT Stok ' . uniqid(), 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
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

    private function item(string $kode = 'BRG-1', string $tipe = 'barang'): Item
    {
        return app(StockMasterService::class)->create('items', ['kode' => $kode, 'nama' => "Barang {$kode}", 'tipe' => $tipe, 'unit_id' => $this->unit], $this->owner);
    }

    private function stock(): StockLedgerService
    {
        return app(StockLedgerService::class);
    }

    private function mv(Item $i, string $arah, string $qty, ?string $harga = null, ?int $wh = null): array
    {
        return ['item_id' => (int) $i->id, 'warehouse_id' => $wh ?? $this->gudang, 'arah' => $arah, 'qty' => $qty, 'harga_satuan' => $harga];
    }

    private function bal(Item $i, ?int $wh = null): object
    {
        return DB::table('stock_balances')->where('item_id', $i->id)->where('warehouse_id', $wh ?? $this->gudang)->first();
    }

    private function acc(string $key): int
    {
        return (int) app(SystemAccountService::class)->resolve($key)->getKey();
    }

    private function adjust(array $lines, string $tanggal = '2026-03-10', ?User $by = null): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(['tanggal' => $tanggal, 'warehouse_id' => $this->gudang, 'alasan' => 'Uji', 'lines' => $lines], $by ?? $this->owner);
    }

    private function post(int $id, ?User $u = null)
    {
        return app(PostingOrchestrator::class)->post('stock_adjustment', $id, $u ?? $this->owner);
    }

    /** debit - kredit satu akun dari semua jurnal perusahaan aktif */
    private function net(int $accountId): string
    {
        return (string) DB::table('journal_lines')->where('company_id', $this->co->id)->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit - kredit), 0) as s')->value('s');
    }

    private function assertNoStockWritten(): void
    {
        $this->assertSame(0, DB::table('stock_ledger')->count());
        $this->assertSame(0.0, (float) DB::table('stock_balances')->sum('qty_saldo'));
    }

    // ----------------------------------------------------------------------------- ALG-05

    public function test_port_stok_terikat_ke_modul_stok(): void
    {
        $this->assertInstanceOf(StockLedgerService::class, app(StockMovementApplier::class));
    }

    public function test_contoh_dokumen_beli_10_100_beli_10_120_jual_5_hpp_550(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '10', '100')]);
        $this->stock()->apply('t_in', 2, '2026-03-02', [$this->mv($i, 'in', '10', '120')]);
        $r = $this->stock()->apply('t_out', 3, '2026-03-03', [$this->mv($i, 'out', '5')]);

        $this->assertSame('550.0000', $r->nilaiKeluar);
        $this->assertSame('110.0000', $r->lines[0]['harga_satuan']);
        $b = $this->bal($i);
        $this->assertSame('15.0000', $b->qty_saldo);
        $this->assertSame('1650.0000', $b->nilai_saldo);
        $this->assertSame('110.0000', $b->hpp_rata_rata); // HPP rata-rata tidak berubah oleh stok keluar
    }

    public function test_menghabiskan_stok_tanpa_sisa_pembulatan(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '3', '10.3333')]); // nilai 30.9999
        $r = $this->stock()->apply('t_out', 2, '2026-03-02', [$this->mv($i, 'out', '3')]);

        $this->assertSame('30.9999', $r->nilaiKeluar);
        $this->assertSame('0.0000', $this->bal($i)->qty_saldo);
        $this->assertSame('0.0000', $this->bal($i)->nilai_saldo);
    }

    public function test_stok_minus_diblokir_kecuali_diizinkan_preferensi(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '2', '100')]);

        try {
            $this->stock()->apply('t_out', 2, '2026-03-02', [$this->mv($i, 'out', '3')]);
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }
        $this->assertSame('2.0000', $this->bal($i)->qty_saldo);

        DB::table('company_settings')->where('company_id', $this->co->id)->update(['izinkan_stok_minus' => true]);
        $this->stock()->apply('t_out', 3, '2026-03-03', [$this->mv($i, 'out', '3')]);
        $this->assertSame('-1.0000', $this->bal($i)->qty_saldo);
    }

    public function test_retur_penjualan_masuk_dengan_hpp_asli_dokumen_sumber(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '10', '100')]);
        $this->stock()->apply('sales_invoice', 77, '2026-03-02', [$this->mv($i, 'out', '4')]);     // HPP 100
        $this->stock()->apply('t_in', 2, '2026-03-03', [$this->mv($i, 'in', '10', '200')]);       // rata-rata naik: (600+2000)/16

        $asli = $this->stock()->costOfOut('sales_invoice', 77, (int) $i->id);
        $this->assertSame('100.0000', $asli);

        $r = $this->stock()->apply('sales_return', 5, '2026-03-04', [$this->mv($i, 'in', '1', $asli)]);
        $this->assertSame('100.0000', $r->lines[0]['harga_satuan']);
        $this->assertSame('100.0000', $r->nilaiMasuk); // bukan 162,50 (HPP rata-rata saat retur)
    }

    public function test_void_membalik_gerakan_dengan_nilai_asli(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '10', '100')]);
        $this->stock()->apply('t_in', 2, '2026-03-02', [$this->mv($i, 'in', '10', '120')]);
        $this->stock()->apply('t_out', 3, '2026-03-03', [$this->mv($i, 'out', '5')]);

        $this->stock()->reverse('t_out', 3, '2026-03-04');
        $b = $this->bal($i);
        $this->assertSame('20.0000', $b->qty_saldo);
        $this->assertSame('2200.0000', $b->nilai_saldo);
        $this->assertSame('110.0000', $b->hpp_rata_rata);

        $this->stock()->reverse('t_out', 3, '2026-03-04'); // idempoten: tidak membalik dua kali
        $this->assertSame('20.0000', $this->bal($i)->qty_saldo);
        $this->assertSame(1, DB::table('stock_ledger')->whereNotNull('reversed_of_id')->count());
    }

    public function test_pembalik_masuk_ditolak_bila_stok_sudah_terpakai(): void
    {
        $i = $this->item();
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($i, 'in', '10', '100')]);
        $this->stock()->apply('t_out', 2, '2026-03-02', [$this->mv($i, 'out', '8')]);

        $this->expectException(StockException::class);
        $this->stock()->reverse('t_in', 1, '2026-03-03'); // sisa 2 < 10
    }

    public function test_gagal_di_gerakan_kedua_tidak_meninggalkan_mutasi(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $this->stock()->apply('t_in', 1, '2026-03-01', [$this->mv($a, 'in', '5', '10')]);
        $before = DB::table('stock_ledger')->count();

        try {
            $this->stock()->apply('t_out', 2, '2026-03-02', [$this->mv($a, 'out', '2'), $this->mv($b, 'out', '1')]); // B tidak punya stok
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame($before, DB::table('stock_ledger')->count());
        $this->assertSame('5.0000', $this->bal($a)->qty_saldo); // gerakan A ikut batal
    }

    public function test_jasa_dan_barang_nonaktif_tidak_bisa_bergerak(): void
    {
        $jasa = $this->item('JASA', 'jasa');
        try {
            $this->stock()->apply('t', 1, '2026-03-01', [$this->mv($jasa, 'in', '1', '10')]);
            $this->fail();
        } catch (StockException $e) {
            $this->assertSame('not_stock_item', $e->errorCode);
        }

        $off = $this->item('OFF');
        $off->forceFill(['is_aktif' => false])->save();
        $this->expectException(StockException::class);
        $this->stock()->apply('t', 2, '2026-03-01', [$this->mv($off, 'in', '1', '10')]);
    }

    public function test_masuk_tanpa_harga_memakai_hpp_terakhir_dan_barang_baru_wajib_harga(): void
    {
        $i = $this->item();
        try {
            $this->stock()->apply('t', 1, '2026-03-01', [$this->mv($i, 'in', '1')]);
            $this->fail();
        } catch (StockException $e) {
            $this->assertSame('price_required', $e->errorCode);
        }

        $this->stock()->apply('t', 2, '2026-03-01', [$this->mv($i, 'in', '4', '50')]);
        $r = $this->stock()->apply('t', 3, '2026-03-02', [$this->mv($i, 'in', '2')]);
        $this->assertSame('100.0000', $r->nilaiMasuk);
    }

    public function test_transaksi_mundur_menandai_hitung_ulang_dan_ditolak_bila_periode_closed(): void
    {
        $i = $this->item();
        $this->stock()->apply('t', 1, '2026-04-10', [$this->mv($i, 'in', '5', '10')]);
        $this->stock()->apply('t', 2, '2026-04-02', [$this->mv($i, 'in', '1', '10')]); // mundur, periode terbuka

        $b = $this->bal($i);
        $this->assertSame(1, (int) $b->perlu_hitung_ulang);
        $this->assertSame('2026-04-02', $b->hitung_ulang_dari);

        $periods = app(PeriodService::class);
        $periods->close($periods->periodFor('2026-04-05'), $this->owner);
        $n = DB::table('stock_ledger')->count();
        try {
            $this->stock()->apply('t', 3, '2026-04-03', [$this->mv($i, 'in', '1', '10')]); // rentang 03-10 Apr memuat periode Closed
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('backdated_closed_period', $e->errorCode);
        }
        $this->assertSame($n, DB::table('stock_ledger')->count());
    }

    // ----------------------------------------------------------------------------- Penyesuaian Persediaan

    public function test_penyesuaian_plus_dr_persediaan_cr_penyesuaian_dan_minus_sebaliknya(): void
    {
        $i = $this->item();
        $adj = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '10', 'harga_satuan' => '100']]);
        $r = $this->post($adj->id);

        $this->assertMatchesRegularExpression('#^ADJ/2026/03/#', $r->nomor);
        $this->assertSame('1000.0000', $this->net($this->acc('persediaan')));
        $this->assertSame('-1000.0000', $this->net($this->acc('penyesuaian_persediaan')));
        $this->assertSame('10.0000', $this->bal($i)->qty_saldo);
        $this->assertSame('1000.0000', (string) $adj->fresh()->lines->first()->nilai);

        $adj2 = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '-4']], '2026-03-11');
        $this->post($adj2->id);
        $this->assertSame('600.0000', $this->net($this->acc('persediaan')));            // 1000 - 400
        $this->assertSame('-600.0000', $this->net($this->acc('penyesuaian_persediaan')));
        $this->assertSame('6.0000', $this->bal($i)->qty_saldo);
    }

    public function test_akun_persediaan_override_barang_lalu_kategori(): void
    {
        $akun = (int) \App\Modules\Accounting\ChartOfAccounts\Account::query()->where('kode', '1-1310')->value('id');
        $skip = $akun === 0;
        if ($skip) {
            $this->markTestSkipped('Template tidak punya akun 1-1310.');
        }
        $m = app(StockMasterService::class);
        $cat = $m->create('item-categories', ['kode' => 'K1', 'nama' => 'Kat', 'akun_persediaan_id' => $akun], $this->owner);
        $i = $m->create('items', ['kode' => 'X', 'nama' => 'X', 'tipe' => 'barang', 'unit_id' => $this->unit, 'category_id' => $cat->id], $this->owner);

        $this->post($this->adjust([['item_id' => $i->id, 'qty_selisih' => '2', 'harga_satuan' => '50']])->id);
        $this->assertSame('100.0000', $this->net($akun));
        $this->assertSame('0.0000', $this->net($this->acc('persediaan')));
    }

    public function test_penyesuaian_minus_melebihi_stok_gagal_total_dokumen_tetap_draft(): void
    {
        $i = $this->item();
        $adj = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '-1']]);

        try {
            $this->post($adj->id);
            $this->fail('Seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame('draft', $adj->fresh()->status);
        $this->assertNull($adj->fresh()->nomor);
        $this->assertSame(0, Journal::count());
        $this->assertNoStockWritten();
        $this->assertSame(0, DB::table('account_balances')->count());
    }

    public function test_void_penyesuaian_membalik_stok_dan_jurnal(): void
    {
        $i = $this->item();
        $adj = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '10', 'harga_satuan' => '100']]);
        $this->post($adj->id);

        app(PostingOrchestrator::class)->void('stock_adjustment', $adj->id, 'Salah hitung', $this->owner);

        $this->assertSame('void', $adj->fresh()->status);
        $this->assertSame('0.0000', $this->bal($i)->qty_saldo);
        $this->assertSame('0.0000', $this->net($this->acc('persediaan')));
        $this->assertSame('0.0000', $this->net($this->acc('penyesuaian_persediaan')));
    }

    public function test_periode_terkunci_menolak_draft_dan_posting(): void
    {
        $i = $this->item();
        $adj = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '1', 'harga_satuan' => '10']], '2026-03-10');

        $periods = app(PeriodService::class);
        $periods->close($periods->periodFor('2026-03-10'), $this->owner);

        try {
            $this->post($adj->id);
            $this->fail();
        } catch (PeriodException $e) {
            $this->assertSame('period_closed', $e->errorCode);
        }
        $this->assertSame('draft', $adj->fresh()->status);

        $this->expectException(PeriodException::class);
        $this->adjust([['item_id' => $i->id, 'qty_selisih' => '1', 'harga_satuan' => '10']], '2026-03-12');
    }

    public function test_draft_validasi_jasa_harga_minus_dan_dokumen_posted_tidak_bisa_diubah(): void
    {
        $jasa = $this->item('J', 'jasa');
        $i = $this->item();

        foreach ([
            [['item_id' => $jasa->id, 'qty_selisih' => '1']],
            [['item_id' => $i->id, 'qty_selisih' => '-1', 'harga_satuan' => '5']],
            [['item_id' => $i->id, 'qty_selisih' => '0']],
        ] as $lines) {
            try {
                $this->adjust($lines);
                $this->fail('Seharusnya ditolak');
            } catch (StockException) {
                $this->addToAssertionCount(1);
            }
        }

        $adj = $this->adjust([['item_id' => $i->id, 'qty_selisih' => '1', 'harga_satuan' => '5']]);
        $this->post($adj->id);
        $this->expectException(StockException::class);
        app(StockAdjustmentService::class)->delete($adj->id, $this->owner);
    }

    // ----------------------------------------------------------------------------- master

    public function test_master_kode_ganda_konversi_dipakai_dan_kunci_tipe_setelah_ada_stok(): void
    {
        $m = app(StockMasterService::class);
        try {
            $m->create('units', ['kode' => 'PCS', 'nama' => 'Dobel'], $this->owner);
            $this->fail();
        } catch (StockException $e) {
            $this->assertSame('duplicate_code', $e->errorCode);
        }

        $dus = $m->create('units', ['kode' => 'DUS', 'nama' => 'Dus'], $this->owner);
        $i = $m->create('items', ['kode' => 'K', 'nama' => 'K', 'tipe' => 'barang', 'unit_id' => $this->unit, 'conversions' => [['unit_id' => $dus->id, 'rasio_ke_dasar' => '12']]], $this->owner);
        $this->assertSame('12.000000', (string) $i->conversions->first()->rasio_ke_dasar);

        try {
            $m->update('items', $i->id, ['kode' => 'K', 'nama' => 'K', 'tipe' => 'barang', 'unit_id' => $this->unit, 'conversions' => [['unit_id' => $this->unit, 'rasio_ke_dasar' => '1']]], $this->owner);
            $this->fail('Konversi ke satuan dasar harus ditolak');
        } catch (StockException $e) {
            $this->assertSame('conversion_invalid', $e->errorCode);
        }

        $this->stock()->apply('t', 1, '2026-03-01', [$this->mv($i, 'in', '1', '10')]);
        try {
            $m->update('items', $i->id, ['kode' => 'K', 'nama' => 'K', 'tipe' => 'jasa', 'unit_id' => $this->unit], $this->owner);
            $this->fail();
        } catch (StockException $e) {
            $this->assertSame('item_locked', $e->errorCode);
        }

        try {
            $m->delete('items', $i->id, $this->owner);
            $this->fail();
        } catch (StockException $e) {
            $this->assertSame('in_use', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- laporan, HTTP, hak, isolasi

    public function test_laporan_barang_per_gudang_dan_kartu_stok_hpp_hanya_untuk_pemegang_hak(): void
    {
        $i = $this->item();
        $this->stock()->apply('t', 1, '2026-02-01', [$this->mv($i, 'in', '10', '100')]);
        $this->stock()->apply('t', 2, '2026-03-05', [$this->mv($i, 'out', '4')]);

        $kasir = $this->member('Kasir');   // lihat laporan stok, tanpa lihat_hpp
        $gudang = $this->member('Gudang'); // punya lihat_hpp
        $h = ['X-Company-Id' => $this->co->public_id];

        Sanctum::actingAs($gudang);
        $r = $this->withHeaders($h)->getJson('/api/v1/reports/stock-by-warehouse')->assertOk();
        $r->assertJsonPath('rows.0.qty_saldo', '6.0000')->assertJsonPath('rows.0.hpp_rata_rata', '100.0000');

        $card = $this->withHeaders($h)->getJson("/api/v1/reports/stock-card?item_id={$i->id}&warehouse_id={$this->gudang}&dari=2026-03-01&sampai=2026-03-31")->assertOk();
        $card->assertJsonPath('saldo_awal.qty', '10.0000')->assertJsonPath('saldo_awal.nilai', '1000.0000')
            ->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.qty_keluar', '4.0000')->assertJsonPath('rows.0.qty_saldo', '6.0000');

        Sanctum::actingAs($kasir);
        $r = $this->withHeaders($h)->getJson('/api/v1/reports/stock-by-warehouse')->assertOk();
        $this->assertArrayNotHasKey('hpp_rata_rata', $r->json('rows.0'));
        $this->assertArrayNotHasKey('nilai_saldo', $r->json('rows.0'));
    }

    public function test_hak_akses_kasir_tidak_bisa_menyesuaikan_atau_mengelola_barang_gudang_bisa(): void
    {
        $i = $this->item();
        $h = ['X-Company-Id' => $this->co->public_id];
        $body = ['tanggal' => '2026-03-10', 'warehouse_id' => $this->gudang, 'alasan' => 'Rusak', 'lines' => [['item_id' => $i->id, 'qty_selisih' => '3', 'harga_satuan' => '10']]];

        Sanctum::actingAs($this->member('Kasir'));
        $this->withHeaders($h)->postJson('/api/v1/stock-adjustments', $body)->assertForbidden();
        $this->withHeaders($h)->postJson('/api/v1/items', ['kode' => 'Z', 'nama' => 'Z', 'tipe' => 'barang', 'unit_id' => $this->unit])->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/items')->assertOk(); // Kasir boleh melihat barang

        Sanctum::actingAs($this->member('Gudang'));
        $id = $this->withHeaders($h)->postJson('/api/v1/stock-adjustments', $body)->assertCreated()->json('data.id');
        $this->withHeaders($h)->postJson("/api/v1/documents/stock_adjustment/{$id}/post")->assertOk();
        $this->withHeaders($h)->getJson("/api/v1/stock-adjustments/{$id}")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('3.0000', $this->bal($i)->qty_saldo);
    }

    public function test_isolasi_lintas_perusahaan(): void
    {
        $i = $this->item();
        $this->stock()->apply('t', 1, '2026-03-01', [$this->mv($i, 'in', '5', '10')]);

        $other = User::factory()->create();
        $coB = $this->newCompany($other);
        $h = ['X-Company-Id' => $coB->public_id];

        Sanctum::actingAs($other);
        $this->withHeaders($h)->getJson('/api/v1/items')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($h)->getJson("/api/v1/items/{$i->id}")->assertNotFound();
        $this->withHeaders($h)->getJson('/api/v1/reports/stock-by-warehouse')->assertOk()->assertJsonCount(0, 'rows');
        $this->withHeaders($h)->getJson("/api/v1/reports/stock-card?item_id={$i->id}&warehouse_id={$this->gudang}&dari=2026-03-01&sampai=2026-03-31")->assertNotFound();

        // B tidak bisa menyesuaikan stok barang/gudang milik A
        $this->as($other, $coB);
        $gB = (int) app(StockMasterService::class)->create('warehouses', ['kode' => 'GB', 'nama' => 'GB'], $other)->id;
        try {
            app(StockAdjustmentService::class)->create(['tanggal' => '2026-03-10', 'warehouse_id' => $gB, 'alasan' => 'x', 'lines' => [['item_id' => $i->id, 'qty_selisih' => '1', 'harga_satuan' => '1']]], $other);
            $this->fail('Barang perusahaan lain seharusnya ditolak');
        } catch (StockException $e) {
            $this->assertSame('not_stock_item', $e->errorCode);
        }
        $this->assertSame(0, Warehouse::query()->where('kode', 'GU')->count()); // gudang A tak terlihat dari B
    }
}
