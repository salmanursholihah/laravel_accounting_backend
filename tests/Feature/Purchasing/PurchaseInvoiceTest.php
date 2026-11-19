<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
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
use App\Modules\Purchasing\Invoices\PurchaseInvoiceService;
use App\Modules\Purchasing\PurchasingException;
use App\Modules\Purchasing\Suppliers\SupplierService;
use App\Modules\Stock\Ledger\StockLedgerService;
use App\Modules\Stock\Masters\StockMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pemasok dan Faktur Pembelian (Step 5b). Contoh dokumen Tahap 4: beli 10 @ Rp600.000 (belum termasuk pajak), PPN 12% DPP 11/12:
 * Persediaan 6.000.000 . PPN Masukan 660.000 . Utang Usaha 6.660.000.
 * BELUM pernah dijalankan oleh penulisnya (tanpa MySQL/vendor); jalankan di MySQL 8 asli.
 */
class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $unit;
    private int $gudang;
    private int $supplier; // contact_id
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
            'kode' => 'BRS-5', 'nama' => 'Beras 5kg', 'unit_id' => $this->unit, 'harga_beli_standar' => '600000', 'tax_code_beli_id' => $this->ppn(),
        ], $this->owner)->id;
        $this->supplier = (int) app(SupplierService::class)->create(['kode' => 'S-0001', 'nama' => 'PT Pemasok Jaya'], $this->owner)['contact_id'];
    }

    // ----------------------------------------------------------------------------- pembantu

    private function newCompany(User $owner): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => 'PT Beli ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
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

    private function accKode(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private int $seq = 0;

    private function invoice(array $over = [], ?User $by = null): int
    {
        $this->seq++;

        return (int) app(PurchaseInvoiceService::class)->create($over + [
            'tanggal' => '2026-03-10', 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'INV-S-' . $this->seq, 'no_faktur_pajak' => '010.000-26.00000001',
            'lines' => [['item_id' => $this->item, 'qty' => '10']],
        ], $by ?? $this->owner)->id;
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    private function net(int $accountId): string
    {
        return (string) DB::table('journal_lines')->where('company_id', $this->co->id)->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit - kredit), 0) as s')->value('s');
    }

    private function assertMoney(string $expected, mixed $actual, string $m = ''): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$m} diharapkan {$expected}, didapat {$actual}");
    }

    private function bal(): ?object
    {
        return DB::table('stock_balances')->where('item_id', $this->item)->where('warehouse_id', $this->gudang)->first();
    }

    private function close(string $tanggal): void
    {
        $p = app(PeriodService::class)->periodFor($tanggal);
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($p->id), $this->owner);
    }

    // ----------------------------------------------------------------------------- pemasok

    public function test_pemasok_adalah_ekstensi_kontak_dan_kontak_pelanggan_bisa_jadi_pemasok(): void
    {
        $svc = app(SupplierService::class);
        $this->assertTrue((bool) DB::table('contacts')->where('id', $this->supplier)->value('is_pemasok'));

        try {
            $svc->create(['kode' => 'S-0001', 'nama' => 'Dobel'], $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PurchasingException $e) {
            $this->assertSame('duplicate_code', $e->errorCode);
        }

        $pelanggan = DB::table('contacts')->insertGetId(['company_id' => $this->co->id, 'kode' => 'C-1', 'nama' => 'CV Pelanggan', 'is_pelanggan' => true, 'created_at' => now(), 'updated_at' => now()]);
        $r = $svc->create(['contact_id' => $pelanggan, 'no_rekening' => '123-456'], $this->owner);
        $this->assertSame($pelanggan, $r['contact_id']);
        $this->assertTrue((bool) DB::table('contacts')->where('id', $pelanggan)->value('is_pelanggan'), 'tetap pelanggan');
        $this->assertTrue((bool) DB::table('contacts')->where('id', $pelanggan)->value('is_pemasok'));

        try {
            $svc->create(['contact_id' => $pelanggan], $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PurchasingException $e) {
            $this->assertSame('supplier_exists', $e->errorCode);
        }

        // akun utang override harus akun utang yang bisa dipakai
        $this->expectException(PurchasingException::class);
        $svc->update((int) DB::table('suppliers')->where('contact_id', $this->supplier)->value('id'), ['akun_utang_override_id' => $this->accKode('6-2000')], $this->owner);
    }

    // ----------------------------------------------------------------------------- posting

    public function test_contoh_dokumen_faktur_beli_10_x_600rb_menjurnal_persediaan_ppn_masukan_dan_utang(): void
    {
        $id = $this->invoice();
        $draft = DB::table('purchase_invoices')->find($id);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->nomor);
        $this->assertMoney('6660000', $draft->total, 'total draft sudah dihitung ALG-03');

        $r = $this->orch()->post('purchase_invoice', $id, $this->owner);

        $doc = DB::table('purchase_invoices')->find($id);
        $this->assertSame('posted', $doc->status);
        $this->assertStringStartsWith('PI/', $doc->nomor);
        $this->assertMoney('6660000', $doc->sisa_tagihan);
        $this->assertSame('belum', $doc->status_pembayaran);
        $this->assertCount(1, $r->journalIds, 'faktur beli langsung = satu jurnal');

        $this->assertMoney('6000000', $this->net($this->acc('persediaan')), 'Dr Persediaan');
        $this->assertMoney('660000', $this->net($this->acc('ppn_masukan')), 'Dr PPN Masukan');
        $this->assertMoney('-6660000', $this->net($this->acc('utang_usaha')), 'Cr Utang Usaha');

        $b = $this->bal();
        $this->assertMoney('10', $b->qty_saldo);
        $this->assertMoney('6000000', $b->nilai_saldo);
        $this->assertMoney('600000', $b->hpp_rata_rata);

        // subledger pemasok di baris utang
        $this->assertSame($this->supplier, (int) DB::table('journal_lines')->where('journal_id', $r->journalIds[0])->where('account_id', $this->acc('utang_usaha'))->value('contact_id'));
        $this->assertSame(1, DB::table('document_taxes')->where('doc_type', 'purchase_invoice')->where('doc_id', $id)->count());
    }

    public function test_ppn_tanpa_faktur_pajak_atau_tidak_dikreditkan_masuk_biaya_perolehan(): void
    {
        foreach ([['no_faktur_pajak' => null], ['dapat_dikreditkan' => false]] as $over) {
            $id = $this->invoice($over);
            $this->orch()->post('purchase_invoice', $id, $this->owner);
        }

        // dua faktur @6.660.000 (PPN ikut persediaan), tanpa PPN Masukan
        $this->assertMoney('13320000', $this->net($this->acc('persediaan')));
        $this->assertMoney('0', $this->net($this->acc('ppn_masukan')));
        $this->assertMoney('-13320000', $this->net($this->acc('utang_usaha')));
        $this->assertMoney('666000', $this->bal()->hpp_rata_rata, 'HPP naik karena PPN tidak dikreditkan');
    }

    public function test_harga_termasuk_pajak_dihitung_mundur(): void
    {
        $id = $this->invoice(['harga_termasuk_pajak' => true, 'lines' => [['item_id' => $this->item, 'qty' => '10', 'harga' => '666000']]]);
        $this->assertMoney('6660000', DB::table('purchase_invoices')->find($id)->total);

        $this->orch()->post('purchase_invoice', $id, $this->owner);
        $this->assertMoney('6000000', $this->net($this->acc('persediaan')));
        $this->assertMoney('660000', $this->net($this->acc('ppn_masukan')));
    }

    public function test_hpp_rata_rata_bergerak_dari_dua_pembelian(): void
    {
        foreach ([['qty' => '10', 'harga' => '100'], ['qty' => '10', 'harga' => '120']] as $l) {
            $id = $this->invoice(['lines' => [['item_id' => $this->item, 'tax_code_id' => null] + $l]]);
            $this->orch()->post('purchase_invoice', $id, $this->owner);
        }

        $b = $this->bal();
        $this->assertMoney('20', $b->qty_saldo);
        $this->assertMoney('2200', $b->nilai_saldo);
        $this->assertMoney('110', $b->hpp_rata_rata);
    }

    public function test_baris_non_barang_memakai_akun_beban_dan_tanpa_stok(): void
    {
        $beban = $this->accKode('6-2000');
        $id = $this->invoice(['lines' => [['account_id' => $beban, 'deskripsi' => 'Jasa servis', 'qty' => '1', 'harga' => '1000000', 'tax_code_id' => $this->ppn()]]]);
        $this->orch()->post('purchase_invoice', $id, $this->owner);

        $this->assertMoney('1000000', $this->net($beban));
        $this->assertMoney('110000', $this->net($this->acc('ppn_masukan')));
        $this->assertMoney('-1110000', $this->net($this->acc('utang_usaha')));
        $this->assertSame(0, DB::table('stock_ledger')->count());
    }

    public function test_baris_barang_persediaan_tidak_boleh_memakai_akun_manual_dan_akun_harus_beban(): void
    {
        $svc = app(PurchaseInvoiceService::class);
        foreach ([
            [['item_id' => $this->item, 'account_id' => $this->accKode('6-2000'), 'qty' => '1']],
            [['account_id' => $this->accKode('4-1000'), 'qty' => '1', 'harga' => '1000']], // akun pendapatan
        ] as $lines) {
            try {
                $svc->create(['tanggal' => '2026-03-10', 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'X-' . uniqid(), 'lines' => $lines], $this->owner);
                $this->fail('Seharusnya ditolak.');
            } catch (PurchasingException $e) {
                $this->assertContains($e->errorCode, ['account_not_allowed', 'account_invalid']);
            }
        }
    }

    // ----------------------------------------------------------------------------- nomor faktur pemasok

    public function test_nomor_faktur_pemasok_unik_per_pemasok_dan_tidak_dipakai_ulang_setelah_void(): void
    {
        $id = $this->invoice(['no_faktur_pemasok' => 'FP-001']);

        try {
            $this->invoice(['no_faktur_pemasok' => 'FP-001']);
            $this->fail('Nomor ganda harus ditolak.');
        } catch (PurchasingException $e) {
            $this->assertSame('supplier_invoice_duplicate', $e->errorCode);
        }

        // pemasok lain boleh memakai nomor yang sama
        $lain = (int) app(SupplierService::class)->create(['kode' => 'S-0002', 'nama' => 'CV Lain'], $this->owner)['contact_id'];
        $this->invoice(['contact_id' => $lain, 'no_faktur_pemasok' => 'FP-001']);

        // void tidak melepaskan nomor
        $this->orch()->post('purchase_invoice', $id, $this->owner);
        $this->orch()->void('purchase_invoice', $id, 'salah input', $this->owner);
        $this->expectException(PurchasingException::class);
        $this->invoice(['no_faktur_pemasok' => 'FP-001']);
    }

    public function test_mengubah_draft_sendiri_tidak_dianggap_duplikat_tetapi_draft_lain_ya(): void
    {
        $svc = app(PurchaseInvoiceService::class);
        $a = $this->invoice(['no_faktur_pemasok' => 'A-1']);
        $this->invoice(['no_faktur_pemasok' => 'B-1']);

        $doc = $svc->find($a);
        $svc->update($a, ['tanggal' => '2026-03-11', 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'A-1', 'version' => $doc->version, 'lines' => [['item_id' => $this->item, 'qty' => '3']]], $this->owner);

        $this->expectException(PurchasingException::class);
        $svc->update($a, ['tanggal' => '2026-03-11', 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'B-1', 'lines' => [['item_id' => $this->item, 'qty' => '3']]], $this->owner);
    }

    // ----------------------------------------------------------------------------- void dan atomisitas

    public function test_void_faktur_membalik_jurnal_dan_stok_dengan_nilai_asli(): void
    {
        $id = $this->invoice();
        $this->orch()->post('purchase_invoice', $id, $this->owner);
        $nomor = DB::table('purchase_invoices')->find($id)->nomor;

        $this->orch()->void('purchase_invoice', $id, 'salah input', $this->owner);

        $doc = DB::table('purchase_invoices')->find($id);
        $this->assertSame('void', $doc->status);
        $this->assertSame($nomor, $doc->nomor, 'nomor tetap tampil dan tidak dipakai ulang');
        $this->assertMoney('0', $doc->sisa_tagihan);
        foreach (['persediaan', 'ppn_masukan', 'utang_usaha'] as $k) {
            $this->assertMoney('0', $this->net($this->acc($k)), "saldo {$k} kembali nol");
        }
        $b = $this->bal();
        $this->assertMoney('0', $b->qty_saldo);
        $this->assertMoney('0', $b->nilai_saldo);
    }

    public function test_void_ditolak_bila_stok_sudah_terpakai_dan_semuanya_utuh(): void
    {
        $id = $this->invoice();
        $this->orch()->post('purchase_invoice', $id, $this->owner);
        app(StockLedgerService::class)->apply('uji_jual', 1, '2026-03-12', [['item_id' => $this->item, 'warehouse_id' => $this->gudang, 'arah' => 'out', 'qty' => '8']]);

        try {
            $this->orch()->void('purchase_invoice', $id, 'coba', $this->owner);
            $this->fail('Void harus ditolak: stok sudah terpakai.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        $this->assertSame('posted', DB::table('purchase_invoices')->find($id)->status);
        $this->assertMoney('-6660000', $this->net($this->acc('utang_usaha')), 'jurnal tidak setengah jadi');
        $this->assertSame(0, DB::table('journals')->where('company_id', $this->co->id)->whereNotNull('reversed_of_id')->count());
    }

    public function test_alg02_error_di_jurnal_membatalkan_stok_dan_dokumen_tetap_draft(): void
    {
        $beban = $this->accKode('6-2000');
        $id = $this->invoice(['lines' => [
            ['item_id' => $this->item, 'qty' => '10'],
            ['account_id' => $beban, 'qty' => '1', 'harga' => '1000'],
        ]]);
        Account::query()->whereKey($beban)->update(['is_aktif' => false]); // jurnal gagal divalidasi setelah stok masuk dicatat

        try {
            $this->orch()->post('purchase_invoice', $id, $this->owner);
            $this->fail('Posting harus gagal.');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        $doc = DB::table('purchase_invoices')->find($id);
        $this->assertSame('draft', $doc->status);
        $this->assertNull($doc->nomor, 'nomor ikut batal (tanpa celah)');
        $this->assertSame(0, DB::table('stock_ledger')->where('company_id', $this->co->id)->count());
        $this->assertSame(0, DB::table('journals')->where('company_id', $this->co->id)->where('source_type', 'purchase_invoice')->count());
        $this->assertMoney('0', $this->bal()->qty_saldo ?? '0');
    }

    // ----------------------------------------------------------------------------- periode dan validasi

    public function test_periode_terkunci_menolak_draft_baru_dan_posting(): void
    {
        $id = $this->invoice();
        $this->close('2026-03-10');

        try {
            $this->orch()->post('purchase_invoice', $id, $this->owner);
            $this->fail('Posting di periode Closed harus ditolak.');
        } catch (PeriodException|PostingException) {
            $this->assertSame('draft', DB::table('purchase_invoices')->find($id)->status);
        }

        $this->expectException(PeriodException::class);
        $this->invoice();
    }

    public function test_validasi_draft_pemasok_nonaktif_total_nol_dan_pemasok_lintas_perusahaan(): void
    {
        $svc = app(PurchaseInvoiceService::class);
        $base = ['tanggal' => '2026-03-10', 'no_faktur_pemasok' => 'V-1', 'lines' => [['item_id' => $this->item, 'qty' => '1']]];

        $ownerB = User::factory()->create();
        $coB = $this->newCompany($ownerB);
        $this->as($ownerB, $coB);
        $supB = (int) app(SupplierService::class)->create(['kode' => 'S-B', 'nama' => 'Pemasok B'], $ownerB)['contact_id'];
        $this->as($this->owner, $this->co);

        try {
            $svc->create($base + ['contact_id' => $supB], $this->owner);
            $this->fail('Pemasok perusahaan lain harus ditolak.');
        } catch (PurchasingException $e) {
            $this->assertSame('supplier_not_found', $e->errorCode);
        }

        try {
            $svc->create(['no_faktur_pemasok' => 'V-2', 'contact_id' => $this->supplier, 'tanggal' => '2026-03-10', 'lines' => [['item_id' => $this->item, 'qty' => '1', 'harga' => '0', 'tax_code_id' => null]]], $this->owner);
            $this->fail('Total nol harus ditolak.');
        } catch (PurchasingException $e) {
            $this->assertSame('total_zero', $e->errorCode);
        }

        DB::table('contacts')->where('id', $this->supplier)->update(['is_aktif' => false]);
        $this->expectException(PurchasingException::class);
        $svc->create($base + ['contact_id' => $this->supplier], $this->owner);
    }

    public function test_hanya_draft_yang_boleh_diubah_atau_dihapus(): void
    {
        $svc = app(PurchaseInvoiceService::class);
        $id = $this->invoice();
        $this->orch()->post('purchase_invoice', $id, $this->owner);

        $this->expectException(PurchasingException::class);
        $svc->delete($id);
    }

    // ----------------------------------------------------------------------------- hak akses dan isolasi (API)

    public function test_hak_akses_api_kasir_dan_gudang_ditolak_purchasing_boleh_membuat_tetapi_tidak_void(): void
    {
        $h = ['X-Company-Id' => $this->co->public_id];
        $payload = ['tanggal' => '2026-03-10', 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'API-1', 'lines' => [['item_id' => $this->item, 'qty' => '2']]];

        Sanctum::actingAs($this->member('Kasir'));
        $this->postJson('/api/v1/purchase-invoices', $payload, $h)->assertStatus(403);

        Sanctum::actingAs($this->member('Gudang'));
        $this->postJson('/api/v1/purchase-invoices', $payload, $h)->assertStatus(403);

        $pu = $this->member('Purchasing');
        Sanctum::actingAs($pu);
        $id = $this->postJson('/api/v1/purchase-invoices', $payload, $h)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/purchase-invoices?no_faktur_pemasok=API-1', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/documents/purchase_invoice/{$id}/post", [], $h)->assertOk();
        $this->postJson("/api/v1/documents/purchase_invoice/{$id}/void", ['alasan' => 'coba'], $h)->assertStatus(403);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/documents/purchase_invoice/{$id}/void", ['alasan' => 'salah input'], $h)->assertOk();
    }

    public function test_api_isolasi_lintas_perusahaan_pemasok_dan_faktur(): void
    {
        $id = $this->invoice();
        $supId = (int) DB::table('suppliers')->where('contact_id', $this->supplier)->value('id');

        $ownerB = User::factory()->create();
        $coB = $this->newCompany($ownerB);
        $hB = ['X-Company-Id' => $coB->public_id];

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/v1/suppliers', $hB)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchase-invoices', $hB)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/purchase-invoices/{$id}", $hB)->assertNotFound();
        $this->getJson("/api/v1/suppliers/{$supId}", $hB)->assertNotFound();
        $this->putJson("/api/v1/purchase-invoices/{$id}", ['tanggal' => '2026-03-10'], $hB)->assertStatus(404);
        $this->deleteJson("/api/v1/purchase-invoices/{$id}", [], $hB)->assertNotFound();
        $this->postJson("/api/v1/documents/purchase_invoice/{$id}/post", [], $hB)->assertNotFound();

        // owner B tidak bisa memakai header perusahaan A
        $this->getJson('/api/v1/purchase-invoices', ['X-Company-Id' => $this->co->public_id])->assertStatus(403);
    }
}
