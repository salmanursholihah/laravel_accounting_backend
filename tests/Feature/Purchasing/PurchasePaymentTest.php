<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Purchasing\Invoices\PurchaseInvoiceService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Payments\PurchasePaymentService;
use App\Modules\Purchasing\PurchasingException;
use App\Modules\Purchasing\Suppliers\SupplierService;
use App\Modules\Stock\Masters\StockMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pembayaran Pembelian + alokasi ALG-06. Tiga faktur (harga beli 600.000, PPN 12% DPP 11/12):
 *   C: 5 unit, jatuh tempo 01/03 = 3.330.000 . A: 10 unit, jatuh tempo 17/03 = 6.660.000 . B: 2 unit, jatuh tempo 04/04 = 1.332.000.
 * BELUM pernah dijalankan oleh penulisnya (tanpa MySQL/vendor); jalankan di MySQL 8 asli.
 */
class PurchasePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $supplier;
    private int $item;
    private int $bank; // bank_accounts.id
    private int $seq = 0;
    /** @var array<string,int> id faktur menurut nama */
    private array $inv = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tax_defaults.seed_on_company_created' => true]);
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = app(CreateCompany::class)->handle($this->owner, ['nama' => 'PT Bayar ' . uniqid(), 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
        $this->as($this->owner);

        $m = app(StockMasterService::class);
        $unit = (int) $m->create('units', ['kode' => 'PCS', 'nama' => 'Pcs'], $this->owner)->id;
        $m->create('warehouses', ['kode' => 'GU', 'nama' => 'Gudang Utama', 'is_default' => true], $this->owner);
        $this->item = (int) $m->create('items', [
            'kode' => 'BRS-5', 'nama' => 'Beras 5kg', 'unit_id' => $unit, 'harga_beli_standar' => '600000',
            'tax_code_beli_id' => app(TaxCodeService::class)->resolveByKode('PPN', '2026-03-10')->id,
        ], $this->owner)->id;
        $this->supplier = (int) app(SupplierService::class)->create(['kode' => 'S-0001', 'nama' => 'PT Pemasok Jaya'], $this->owner)['contact_id'];
        $this->bank = (int) app(BankAccountService::class)->create(['account_id' => $this->accKode('1-1120'), 'nama_bank' => 'BCA'], $this->owner)->id;

        // C (jatuh tempo paling awal), A, B diposting dalam urutan acak agar bukti urutan alokasi bukan urutan id
        $this->inv['A'] = $this->postedInvoice('2026-03-10', 'Net 7', '10');
        $this->inv['B'] = $this->postedInvoice('2026-03-05', 'Net 30', '2');
        $this->inv['C'] = $this->postedInvoice('2026-03-01', 'Tunai', '5');
    }

    // ----------------------------------------------------------------------------- pembantu

    private function as(User $u): void
    {
        app(CurrentCompany::class)->set($this->co, CompanyUser::where(['company_id' => $this->co->id, 'user_id' => $u->id])->firstOrFail());
        app(\App\Modules\Platform\Access\PermissionChecker::class)->flush();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create(['company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active', 'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id')]);

        return $u;
    }

    private function accKode(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    private function postedInvoice(string $tanggal, string $term, string $qty): int
    {
        $this->seq++;
        $id = (int) app(PurchaseInvoiceService::class)->create([
            'tanggal' => $tanggal, 'contact_id' => $this->supplier, 'no_faktur_pemasok' => 'S-' . $this->seq, 'no_faktur_pajak' => '010.000-26.0000000' . $this->seq,
            'payment_term_id' => PaymentTerm::query()->where('nama', $term)->value('id'),
            'lines' => [['item_id' => $this->item, 'qty' => $qty]],
        ], $this->owner)->id;
        $this->orch()->post('purchase_invoice', $id, $this->owner);

        return $id;
    }

    private function payment(string $nominal, array $over = [], ?User $by = null, string $tanggal = '2026-03-20'): int
    {
        return (int) app(PurchasePaymentService::class)->create($over + [
            'tanggal' => $tanggal, 'contact_id' => $this->supplier, 'rekening_id' => $this->bank, 'nominal' => $nominal,
        ], $by ?? $this->owner)->id;
    }

    private function invoice(string $k): PurchaseInvoice
    {
        return PurchaseInvoice::query()->findOrFail($this->inv[$k]);
    }

    private function assertMoney(string $expected, mixed $actual, string $m = ''): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "{$m} diharapkan {$expected}, didapat {$actual}");
    }

    private function assertInvoice(string $k, string $dibayar, string $sisa, string $status): void
    {
        $i = $this->invoice($k);
        $this->assertMoney($dibayar, $i->total_dibayar, "faktur {$k} dibayar");
        $this->assertMoney($sisa, $i->sisa_tagihan, "faktur {$k} sisa");
        $this->assertSame($status, $i->status_pembayaran, "faktur {$k} status");
    }

    // ----------------------------------------------------------------------------- tes

    public function test_faktur_awal_sesuai_perhitungan(): void
    {
        $this->assertInvoice('C', '0', '3330000', 'belum');
        $this->assertInvoice('A', '0', '6660000', 'belum');
        $this->assertInvoice('B', '0', '1332000', 'belum');
    }

    public function test_alokasi_otomatis_jatuh_tempo_terlama_dulu_dan_jurnal_utang_ke_bank(): void
    {
        $id = $this->payment('5000000');
        $r = $this->orch()->post('purchase_payment', $id, $this->owner);

        $this->assertSame('PY/2026/03/00001', $r->nomor);
        $this->assertInvoice('C', '3330000', '0', 'lunas');            // jatuh tempo 01/03 lunas dulu
        $this->assertInvoice('A', '1670000', '4990000', 'sebagian');   // sisa 1.670.000 ke faktur berikutnya (17/03)
        $this->assertInvoice('B', '0', '1332000', 'belum');            // jatuh tempo 04/04 tidak tersentuh

        $alokasi = DB::table('purchase_payment_allocations')->where('purchase_payment_id', $id)->pluck('nominal_dialokasikan', 'purchase_invoice_id');
        $this->assertCount(2, $alokasi);
        $this->assertMoney('5000000', $alokasi->sum());

        $j = DB::table('journals')->where(['source_type' => 'purchase_payment', 'source_id' => $id])->first();
        $utang = DB::table('journal_lines')->where(['journal_id' => $j->id, 'account_id' => $this->accKode('2-1100')])->first();
        $bank = DB::table('journal_lines')->where(['journal_id' => $j->id, 'account_id' => $this->accKode('1-1120')])->first();
        $this->assertMoney('5000000', $utang->debit);
        $this->assertSame($this->supplier, (int) $utang->contact_id); // subledger utang pemasok
        $this->assertMoney('5000000', $bank->kredit);
    }

    public function test_alokasi_manual_dan_validasinya(): void
    {
        $id = $this->payment('2000000', ['allocations' => [
            ['purchase_invoice_id' => $this->inv['B'], 'nominal' => '1332000'],
            ['purchase_invoice_id' => $this->inv['A'], 'nominal' => '668000'],
        ]]);
        $this->orch()->post('purchase_payment', $id, $this->owner);

        $this->assertInvoice('B', '1332000', '0', 'lunas');
        $this->assertInvoice('A', '668000', '5992000', 'sebagian');
        $this->assertInvoice('C', '0', '3330000', 'belum');

        foreach ([
            [['allocations' => [['purchase_invoice_id' => $this->inv['C'], 'nominal' => '3330001']]], '3330001'],  // melebihi sisa
            [['allocations' => [['purchase_invoice_id' => $this->inv['C'], 'nominal' => '100']]], '200'],          // jumlah != nominal
            [['allocations' => [['purchase_invoice_id' => 999999, 'nominal' => '100']]], '100'],                  // faktur tak dikenal
            [['allocations' => [['purchase_invoice_id' => $this->inv['C'], 'nominal' => '50'], ['purchase_invoice_id' => $this->inv['C'], 'nominal' => '50']]], '100'], // ganda
        ] as [$over, $nominal]) {
            try {
                $this->payment($nominal, $over);
                $this->fail('Seharusnya ditolak.');
            } catch (PurchasingException $e) {
                $this->assertSame('allocation_invalid', $e->errorCode);
            }
        }
    }

    public function test_pembayaran_tidak_mengalokasi_faktur_bertanggal_setelah_pembayaran(): void
    {
        $id = $this->payment('5000000', [], null, '2026-03-02'); // hanya faktur C (01/03) yang sudah ada pada 02/03
        try {
            $this->orch()->post('purchase_payment', $id, $this->owner);
            $this->fail('Seharusnya ditolak: nominal melebihi sisa faktur yang sudah ada.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $ok = $this->payment('3330000', [], null, '2026-03-02');
        $this->orch()->post('purchase_payment', $ok, $this->owner);
        $this->assertInvoice('C', '3330000', '0', 'lunas');
        $this->assertInvoice('A', '0', '6660000', 'belum');
    }

    public function test_kelebihan_nominal_membatalkan_semuanya_dan_nomor_tidak_berlubang(): void
    {
        $journalsSebelum = DB::table('journals')->count();
        $id = $this->payment('20000000'); // total sisa semua faktur hanya 11.322.000

        try {
            $this->orch()->post('purchase_payment', $id, $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        $this->assertSame('draft', app(PurchasePaymentService::class)->find($id)->status);
        $this->assertSame($journalsSebelum, DB::table('journals')->count());
        $this->assertInvoice('C', '0', '3330000', 'belum');
        $this->assertSame(0, DB::table('purchase_payment_allocations')->where('purchase_payment_id', $id)->count());

        $ok = $this->payment('1000000');
        $this->assertSame('PY/2026/03/00001', $this->orch()->post('purchase_payment', $ok, $this->owner)->nomor);
    }

    public function test_faktur_dengan_pembayaran_aktif_tidak_bisa_void_sampai_pembayaran_di_void(): void
    {
        $id = $this->payment('3330000');
        $this->orch()->post('purchase_payment', $id, $this->owner);

        try {
            $this->orch()->void('purchase_invoice', $this->inv['C'], 'salah', $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('has_active_children', $e->errorCode);
            $this->assertStringContainsString('PY/2026/03/00001', $e->getMessage());
        }

        $this->orch()->void('purchase_payment', $id, 'batal bayar', $this->owner);
        $this->assertSame('void', app(PurchasePaymentService::class)->find($id)->status);
        $this->assertInvoice('C', '0', '3330000', 'belum'); // sisa tagihan pulih
        $this->assertTrue(DB::table('journals')->where('reversed_of_id', DB::table('journals')->where(['source_type' => 'purchase_payment', 'source_id' => $id])->value('id'))->exists());

        $this->orch()->void('purchase_invoice', $this->inv['C'], 'salah', $this->owner); // kini boleh
        $this->assertSame('void', $this->invoice('C')->status);
    }

    public function test_void_pembayaran_sebagian_mengembalikan_status_sebagian(): void
    {
        $p1 = $this->payment('1000000');
        $p2 = $this->payment('500000');
        $this->orch()->post('purchase_payment', $p1, $this->owner);
        $this->orch()->post('purchase_payment', $p2, $this->owner);
        $this->assertInvoice('C', '1500000', '1830000', 'sebagian');

        $this->orch()->void('purchase_payment', $p2, 'x', $this->owner);
        $this->assertInvoice('C', '1000000', '2330000', 'sebagian');
        $this->orch()->void('purchase_payment', $p1, 'x', $this->owner);
        $this->assertInvoice('C', '0', '3330000', 'belum');
    }

    public function test_draft_hanya_bisa_diubah_sebelum_posting_dan_versi_basi_ditolak(): void
    {
        $svc = app(PurchasePaymentService::class);
        $id = $this->payment('1000000');

        $u = $svc->update($id, ['tanggal' => '2026-03-20', 'contact_id' => $this->supplier, 'rekening_id' => $this->bank, 'nominal' => '1200000', 'version' => 1], $this->owner);
        $this->assertSame(2, (int) $u->version);
        $this->assertMoney('1200000', $u->nominal);

        try {
            $svc->update($id, ['tanggal' => '2026-03-20', 'contact_id' => $this->supplier, 'rekening_id' => $this->bank, 'nominal' => '1', 'version' => 1], $this->owner);
            $this->fail('Seharusnya konflik.');
        } catch (PurchasingException $e) {
            $this->assertSame('edit_conflict', $e->errorCode);
        }

        $this->orch()->post('purchase_payment', $id, $this->owner);
        $this->expectException(PurchasingException::class);
        $svc->delete($id);
    }

    public function test_daftar_faktur_terbuka_dengan_saran_alokasi(): void
    {
        $r = app(PurchasePaymentService::class)->openInvoices($this->supplier, '5000000');

        $this->assertSame([$this->inv['C'], $this->inv['A'], $this->inv['B']], array_column($r['faktur'], 'id')); // urut jatuh tempo
        $this->assertMoney('11322000', $r['total_sisa']);
        $this->assertSame([$this->inv['C'], $this->inv['A']], array_column($r['saran_alokasi'], 'invoice_id'));
        $this->assertMoney('0', $r['belum_teralokasi']);
    }

    public function test_http_alur_penuh_dan_hak_akses_kasir(): void
    {
        $h = ['X-Company-Id' => $this->co->public_id];
        $kasir = $this->member('Kasir');
        $payload = ['tanggal' => '2026-03-20', 'contact_id' => $this->supplier, 'rekening_id' => $this->bank, 'nominal' => '3330000'];
        $faktur = $this->inv['C'];

        Sanctum::actingAs($this->owner);
        $id = $this->withHeaders($h)->postJson('/api/v1/purchase-payments', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withHeaders($h)->getJson("/api/v1/purchase-payments/open-invoices?contact_id={$this->supplier}&nominal=3330000")
            ->assertOk()->assertJsonPath('data.saran_alokasi.0.invoice_id', $faktur);
        $this->withHeaders($h)->postJson("/api/v1/documents/purchase_payment/{$id}/post")->assertOk()->assertJsonPath('data.nomor', 'PY/2026/03/00001');
        $this->withHeaders($h)->getJson("/api/v1/purchase-payments/{$id}")->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonCount(1, 'data.alokasi');

        Sanctum::actingAs($kasir); // Kasir: Pembayaran Pembelian hanya lihat
        $this->withHeaders($h)->getJson('/api/v1/purchase-payments')->assertOk();
        $this->withHeaders($h)->postJson('/api/v1/purchase-payments', $payload)->assertForbidden();
        $this->withHeaders($h)->postJson("/api/v1/documents/purchase_payment/{$id}/void", ['alasan' => 'x'])->assertForbidden();
    }
}
