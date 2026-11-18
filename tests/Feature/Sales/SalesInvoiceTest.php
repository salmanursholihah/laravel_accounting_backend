<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodException;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Sales\Customers\CustomerService;
use App\Modules\Sales\Invoices\SalesInvoiceService;
use App\Modules\Sales\SalesException;
use App\Modules\Stock\Ledger\StockLedgerService;
use App\Modules\Stock\Masters\StockMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pelanggan dan Faktur Penjualan (Step 5a). Contoh dokumen Tahap 4: jual 10 @ Rp1.000.000 (belum termasuk pajak), HPP Rp600.000,
 * barang nonmewah: Piutang 11.100.000 . Penjualan 10.000.000 . PPN Keluaran 1.100.000 . HPP 6.000.000 . Persediaan 6.000.000.
 * BELUM pernah dijalankan oleh penulisnya (tanpa MySQL/vendor); jalankan di MySQL 8 asli.
 */
class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $unit;
    private int $gudang;
    private int $customer; // contact_id
    private int $item;

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
        $this->item = (int) $m->create('items', [
            'kode' => 'BRS-5', 'nama' => 'Beras 5kg', 'unit_id' => $this->unit, 'harga_jual' => '1000000', 'tax_code_jual_id' => $this->ppn(),
        ], $this->owner)->id;
        $this->customer = (int) app(CustomerService::class)->create(['kode' => 'C-0001', 'nama' => 'CV Sinar Jaya'], $this->owner)['contact_id'];
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => 'PT Jual ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
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

    private function ppn(): int
    {
        return app(TaxCodeService::class)->resolveByKode('PPN', '2026-03-10')->id;
    }

    private function acc(string $key): int
    {
        return (int) app(SystemAccountService::class)->resolve($key)->getKey();
    }

    private function stokMasuk(string $qty = '10', string $harga = '600000'): void
    {
        app(StockLedgerService::class)->apply('uji_beli', 1, '2026-03-01', [['item_id' => $this->item, 'warehouse_id' => $this->gudang, 'arah' => 'in', 'qty' => $qty, 'harga_satuan' => $harga]]);
    }

    private function invoice(array $over = [], ?User $by = null): int
    {
        return (int) app(SalesInvoiceService::class)->create($over + [
            'tanggal' => '2026-03-10', 'contact_id' => $this->customer,
            'lines' => [['item_id' => $this->item, 'qty' => '10']],
        ], $by ?? $this->owner)->id;
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    /** debit - kredit satu akun dari semua jurnal perusahaan aktif */
    private function net(int $accountId): string
    {
        return (string) DB::table('journal_lines')->where('company_id', $this->co->id)->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit - kredit), 0) as s')->value('s');
    }

    private function assertMoney(string $expected, mixed $actual, string $m = ''): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$m} diharapkan {$expected}, didapat {$actual}");
    }

    private function bal(): object
    {
        return DB::table('stock_balances')->where('item_id', $this->item)->where('warehouse_id', $this->gudang)->first();
    }

    private function close(string $tanggal): void
    {
        $p = app(PeriodService::class)->periodFor($tanggal);
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($p->id), $this->owner);
    }

    // ----------------------------------------------------------------------------- pelanggan

    public function test_pelanggan_adalah_ekstensi_kontak_dan_kontak_pemasok_bisa_jadi_pelanggan(): void
    {
        $svc = app(CustomerService::class);
        $this->assertTrue((bool) DB::table('contacts')->where('id', $this->customer)->value('is_pelanggan'));

        try {
            $svc->create(['kode' => 'C-0001', 'nama' => 'Dobel'], $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (SalesException $e) {
            $this->assertSame('duplicate_code', $e->errorCode);
        }

        $pemasok = DB::table('contacts')->insertGetId(['company_id' => $this->co->id, 'kode' => 'S-1', 'nama' => 'PT Pemasok', 'is_pemasok' => true, 'created_at' => now(), 'updated_at' => now()]);
        $r = $svc->create(['contact_id' => $pemasok, 'limit_kredit' => '5000000'], $this->owner);
        $this->assertSame($pemasok, $r['contact_id']);
        $this->assertTrue((bool) DB::table('contacts')->where('id', $pemasok)->value('is_pemasok'), 'tetap pemasok');
        $this->assertTrue((bool) DB::table('contacts')->where('id', $pemasok)->value('is_pelanggan'));

        try {
            $svc->create(['contact_id' => $pemasok], $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (SalesException $e) {
            $this->assertSame('customer_exists', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- posting

    public function test_contoh_dokumen_faktur_10_x_1jt_hpp_600rb_menjurnal_penjualan_dan_hpp_dalam_satu_transaksi(): void
    {
        $this->stokMasuk();
        $id = $this->invoice();
        $draft = DB::table('sales_invoices')->find($id);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->nomor);
        $this->assertMoney('11100000', $draft->total, 'total draft sudah dihitung ALG-03');

        $r = $this->orch()->post('sales_invoice', $id, $this->owner);
        $this->assertSame('INV/2026/03/00001', $r->nomor);
        $this->assertCount(2, $r->journalIds, 'jurnal penjualan dan jurnal HPP');

        $this->assertMoney('11100000', $this->net($this->acc('piutang_usaha')));
        $this->assertMoney('-10000000', $this->net($this->acc('penjualan')));
        $this->assertMoney('-1100000', $this->net($this->acc('ppn_keluaran')));
        $this->assertMoney('6000000', $this->net($this->acc('hpp')));
        $this->assertMoney('-6000000', $this->net($this->acc('persediaan')));

        $this->assertMoney('0', $this->bal()->qty_saldo);
        $f = DB::table('sales_invoices')->find($id);
        $this->assertSame('posted', $f->status);
        $this->assertMoney('11100000', $f->sisa_tagihan);
        $this->assertSame('belum', $f->status_pembayaran);
        $this->assertSame(1, DB::table('document_taxes')->where(['doc_type' => 'sales_invoice', 'doc_id' => $id])->count());
        $this->assertSame($this->customer, (int) DB::table('journal_lines')->where('account_id', $this->acc('piutang_usaha'))->value('contact_id'), 'subledger piutang');
    }

    public function test_harga_termasuk_pajak_menghitung_mundur_dengan_total_konsisten(): void
    {
        $this->stokMasuk('1');
        $id = $this->invoice(['harga_termasuk_pajak' => true, 'lines' => [['item_id' => $this->item, 'qty' => '1', 'harga' => '11100000']]]);
        $this->orch()->post('sales_invoice', $id, $this->owner);

        $this->assertMoney('11100000', DB::table('sales_invoices')->where('id', $id)->value('total'));
        $this->assertMoney('-10000000', $this->net($this->acc('penjualan')));
        $this->assertMoney('-1100000', $this->net($this->acc('ppn_keluaran')));
    }

    public function test_jatuh_tempo_dari_syarat_pembayaran_dan_baris_jasa_tanpa_stok(): void
    {
        $term = (int) DB::table('payment_terms')->insertGetId(['company_id' => $this->co->id, 'nama' => 'Net 30', 'jumlah_hari' => 30, 'diskon_persen' => 0, 'is_aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
        $akunJasa = $this->acc('penjualan');
        $id = $this->invoice(['payment_term_id' => $term, 'lines' => [['account_id' => $akunJasa, 'deskripsi' => 'Jasa instalasi', 'qty' => '1', 'harga' => '500000', 'tax_code_id' => null]]]);

        $this->assertSame('2026-04-09', (string) DB::table('sales_invoices')->where('id', $id)->value('jatuh_tempo'));
        $this->orch()->post('sales_invoice', $id, $this->owner);
        $this->assertSame(0, DB::table('stock_ledger')->where('source_type', 'sales_invoice')->count());
        $this->assertSame(1, DB::table('journals')->where(['source_type' => 'sales_invoice', 'source_id' => $id])->count(), 'tanpa barang: tidak ada jurnal HPP');
        $this->assertMoney('500000', $this->net($this->acc('piutang_usaha')));
    }

    public function test_stok_tidak_cukup_ditolak_faktur_tetap_draft_tanpa_mutasi(): void
    {
        $this->stokMasuk('5');
        $id = $this->invoice(); // minta 10, ada 5

        try {
            $this->orch()->post('sales_invoice', $id, $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame('draft', DB::table('sales_invoices')->where('id', $id)->value('status'));
        $this->assertMoney('5', $this->bal()->qty_saldo);
        $this->assertSame(0, DB::table('journals')->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, DB::table('stock_ledger')->where('source_type', 'sales_invoice')->count());
    }

    public function test_atomisitas_gagal_di_jurnal_hpp_membatalkan_stok_nomor_dan_jurnal_penjualan(): void
    {
        $this->stokMasuk();
        $id = $this->invoice();
        DB::table('system_accounts')->where(['company_id' => $this->co->id, 'kunci_akun' => 'hpp'])->delete(); // jurnal HPP gagal, jurnal penjualan sudah tersusun

        try {
            $this->orch()->post('sales_invoice', $id, $this->owner);
            $this->fail('Seharusnya gagal.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $f = DB::table('sales_invoices')->find($id);
        $this->assertSame('draft', $f->status);
        $this->assertNull($f->nomor);
        $this->assertMoney('10', $this->bal()->qty_saldo);
        $this->assertSame(0, DB::table('journals')->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, DB::table('stock_ledger')->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, DB::table('document_taxes')->where('doc_type', 'sales_invoice')->count());
    }

    // ----------------------------------------------------------------------------- void

    public function test_void_membalik_semua_jurnal_mengembalikan_stok_dan_menolak_ulang(): void
    {
        $this->stokMasuk();
        $id = $this->invoice();
        $this->orch()->post('sales_invoice', $id, $this->owner);

        $this->orch()->void('sales_invoice', $id, 'Salah input', $this->owner);

        $f = DB::table('sales_invoices')->find($id);
        $this->assertSame('void', $f->status);
        $this->assertMoney('0', $f->sisa_tagihan);
        $this->assertMoney('0', $this->net($this->acc('piutang_usaha')));
        $this->assertMoney('0', $this->net($this->acc('hpp')));
        $this->assertMoney('10', $this->bal()->qty_saldo, 'stok kembali');
        $this->assertSame('INV/2026/03/00001', $f->nomor, 'nomor tetap tampil, tidak dipakai ulang');

        $id2 = $this->invoice();
        $this->assertSame('INV/2026/03/00002', $this->orch()->post('sales_invoice', $id2, $this->owner)->nomor);

        try {
            $this->orch()->void('sales_invoice', $id, 'lagi', $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('wrong_status', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- hak akses

    public function test_kasir_batas_diskon_posting_miliknya_dan_tidak_boleh_void(): void
    {
        $this->stokMasuk();
        $kasir = $this->member('Kasir');
        $this->as($kasir);

        try { // diskon 10% melebihi batas 5% peran Kasir
            $this->invoice(['lines' => [['item_id' => $this->item, 'qty' => '1', 'diskon_persen' => '10']]], $kasir);
            $this->fail('Seharusnya ditolak.');
        } catch (SalesException $e) {
            $this->assertSame('discount_limit', $e->errorCode);
        }

        // diskon 5% (dalam batas): netto 950.000, PPN 12% x (950.000 x 11/12) = 104.500, total 1.054.500
        $ok = $this->invoice(['lines' => [['item_id' => $this->item, 'qty' => '1', 'diskon_persen' => '5']]], $kasir);
        $this->assertMoney('1054500', DB::table('sales_invoices')->where('id', $ok)->value('total'));

        $milikOwner = $this->invoice([], $this->owner);
        try {
            $this->orch()->post('sales_invoice', $milikOwner, $kasir);
            $this->fail('Kasir hanya boleh memposting miliknya.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }

        $this->orch()->post('sales_invoice', $ok, $kasir);
        try {
            $this->orch()->void('sales_invoice', $ok, 'x', $kasir);
            $this->fail('Kasir tidak punya hak void.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
    }

    public function test_peran_tanpa_hak_ubah_harga_tidak_boleh_mengubah_harga_atau_diskon(): void
    {
        $purch = $this->member('Purchasing'); // tidak punya akses Penjualan; ubah_harga_diskon tidak dimiliki
        $this->as($purch);
        try {
            $this->invoice(['lines' => [['item_id' => $this->item, 'qty' => '1', 'harga' => '900000']]], $purch);
            $this->fail('Seharusnya ditolak.');
        } catch (SalesException $e) {
            $this->assertSame('price_forbidden', $e->errorCode);
        }
    }

    // ----------------------------------------------------------------------------- periode, draft

    public function test_draft_di_periode_terkunci_ditolak_dan_posting_setelah_dikunci_ditolak_tanpa_mutasi(): void
    {
        $this->stokMasuk();
        $id = $this->invoice();
        $this->close('2026-03-10');

        try {
            $this->orch()->post('sales_invoice', $id, $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PeriodException $e) {
            $this->assertSame('period_closed', $e->errorCode);
        }
        $this->assertSame(0, DB::table('journals')->where('source_type', 'sales_invoice')->count());
        $this->assertMoney('10', $this->bal()->qty_saldo);

        $this->expectException(PeriodException::class);
        $this->invoice();
    }

    public function test_hanya_draft_yang_bisa_diubah_dihapus_dan_locking_optimistik(): void
    {
        $this->stokMasuk();
        $svc = app(SalesInvoiceService::class);
        $id = $this->invoice();
        $base = ['tanggal' => '2026-03-10', 'contact_id' => $this->customer, 'lines' => [['item_id' => $this->item, 'qty' => '2']]];

        $this->assertSame(2, (int) $svc->update($id, $base + ['version' => 1], $this->owner)->version);
        try {
            $svc->update($id, $base + ['version' => 1], $this->owner);
            $this->fail('Seharusnya konflik.');
        } catch (SalesException $e) {
            $this->assertSame('edit_conflict', $e->errorCode);
        }

        $this->orch()->post('sales_invoice', $id, $this->owner);
        foreach ([fn () => $svc->delete($id), fn () => $svc->update($id, $base, $this->owner)] as $aksi) {
            try {
                $aksi();
                $this->fail('Seharusnya ditolak.');
            } catch (SalesException $e) {
                $this->assertSame('not_draft', $e->errorCode);
            }
        }
    }

    public function test_limit_kredit_terlampaui_hanya_peringatan_bukan_penolakan(): void
    {
        app(CustomerService::class)->update(DB::table('customers')->where('contact_id', $this->customer)->value('id'), ['limit_kredit' => '5000000'], $this->owner);
        $id = $this->invoice(); // total 11.100.000 > 5.000.000
        $w = app(SalesInvoiceService::class)->warnings(app(SalesInvoiceService::class)->find($id));
        $this->assertSame('credit_limit', $w[0]['kode']);
    }

    // ----------------------------------------------------------------------------- isolasi

    public function test_pelanggan_dan_faktur_perusahaan_lain_tidak_terlihat_dan_tidak_bisa_dipakai(): void
    {
        $this->stokMasuk();
        $idA = $this->invoice();
        $contactA = $this->customer;
        $h = ['X-Company-Id' => null];

        $other = User::factory()->create();
        $coB = $this->newCompany($other);
        $this->as($other, $coB);

        try {
            app(SalesInvoiceService::class)->find($idA);
            $this->fail('Seharusnya tidak ditemukan.');
        } catch (SalesException $e) {
            $this->assertSame('not_found', $e->errorCode);
        }
        try {
            app(SalesInvoiceService::class)->create(['tanggal' => '2026-03-10', 'contact_id' => $contactA, 'lines' => [['account_id' => 1, 'qty' => '1', 'harga' => '1']]], $other);
            $this->fail('Seharusnya ditolak.');
        } catch (SalesException $e) {
            $this->assertSame('customer_not_found', $e->errorCode);
        }

        $h = ['X-Company-Id' => $coB->public_id];
        Sanctum::actingAs($other);
        $this->withHeaders($h)->getJson("/api/v1/sales-invoices/{$idA}")->assertNotFound();
        $this->withHeaders($h)->getJson('/api/v1/sales-invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($h)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');
    }

    // ----------------------------------------------------------------------------- HTTP

    public function test_alur_http_pelanggan_faktur_posting_dan_void(): void
    {
        $this->stokMasuk();
        $item = $this->item;
        $h = ['X-Company-Id' => $this->co->public_id];
        Sanctum::actingAs($this->owner);

        $c = $this->withHeaders($h)->postJson('/api/v1/customers', ['kode' => 'C-0002', 'nama' => 'Toko Makmur', 'limit_kredit' => '20000000'])
            ->assertCreated()->assertJsonPath('data.kode', 'C-0002');
        $contactId = $c->json('data.contact_id');

        $res = $this->withHeaders($h)->postJson('/api/v1/sales-invoices', [
            'tanggal' => '2026-03-10', 'contact_id' => $contactId, 'lines' => [['item_id' => $item, 'qty' => '10']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '11100000.0000');
        $id = $res->json('data.id');

        $this->withHeaders($h)->postJson("/api/v1/documents/sales_invoice/{$id}/post")->assertOk()->assertJsonPath('data.nomor', 'INV/2026/03/00001');
        $this->withHeaders($h)->getJson("/api/v1/sales-invoices/{$id}")->assertOk()->assertJsonPath('data.sisa_tagihan', '11100000.0000');
        $this->withHeaders($h)->putJson("/api/v1/sales-invoices/{$id}", ['tanggal' => '2026-03-10', 'contact_id' => $contactId, 'lines' => [['item_id' => $item, 'qty' => '1']]])
            ->assertStatus(422)->assertJsonPath('code', 'not_draft');
        $this->withHeaders($h)->postJson("/api/v1/documents/sales_invoice/{$id}/void", ['alasan' => 'uji'])->assertOk()->assertJsonPath('data.status', 'void');
    }
}
