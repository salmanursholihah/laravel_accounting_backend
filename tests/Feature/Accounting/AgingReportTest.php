<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Reports\AgingReportService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 6C: Umur Piutang dan Umur Utang. Data dimasukkan langsung ke tabel (modul Penerimaan/Retur belum ada),
 * sehingga yang diuji hanya aturan laporan: bucket, alokasi per tanggal, void, izin, isolasi tenant.
 */
class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private int $seq = 0;
    private ?int $bankId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
        $this->co = $this->makeCompany($this->owner);
    }

    private function makeCompany(User $owner, string $nama = 'PT Umur'): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => $nama, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($this->co, $fn);
    }

    private function h(?Company $c = null): array
    {
        return ['X-Company-Id' => ($c ?? $this->co)->public_id];
    }

    private function api(string $path, ?array $headers = null): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/' . $path, $headers ?? $this->h());
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

    private function mkContact(string $kode, string $nama, bool $pelanggan, ?Company $c = null): int
    {
        return $this->mkInsert('contacts', [
            'kode' => $kode, 'nama' => $nama, 'is_pelanggan' => $pelanggan, 'is_pemasok' => ! $pelanggan,
        ], $c);
    }

    private function mkJournal(string $tanggal, ?int $reversedOf = null): int
    {
        $periodId = $this->in(fn () => app(PeriodService::class)->periodFor($tanggal)->id);

        return $this->mkInsert('journals', [
            'nomor' => 'T/' . (++$this->seq), 'tanggal' => $tanggal, 'period_id' => $periodId,
            'tipe' => $reversedOf ? 'reversal' : 'auto', 'reversed_of_id' => $reversedOf,
            'total_debit' => '0', 'total_kredit' => '0', 'created_by' => $this->owner->id,
        ]);
    }

    private function mkBank(): int
    {
        return $this->bankId ??= $this->mkInsert('bank_accounts', [
            'account_id' => $this->in(fn () => Account::query()->where('kode', '1-1110')->value('id')),
        ]);
    }

    /** @param 'utang'|'piutang' $sisi */
    private function mkInvoice(string $sisi, int $contactId, string $nomor, string $jatuhTempo, string $total, string $status = 'posted', ?int $journalId = null, string $tanggal = '2026-01-15'): int
    {
        $row = [
            'public_id' => (string) Str::ulid(), 'nomor' => $nomor, 'tanggal' => $tanggal, 'jatuh_tempo' => $jatuhTempo,
            'status' => $status, 'contact_id' => $contactId, 'total' => $total, 'sisa_tagihan' => $total, 'journal_id' => $journalId,
        ];
        if ($sisi === 'utang') {
            $row['no_faktur_pemasok'] = $nomor;
        }

        return $this->mkInsert($sisi === 'utang' ? 'purchase_invoices' : 'sales_invoices', $row);
    }

    /** Pembayaran/penerimaan + satu alokasi ke satu faktur. */
    private function mkPayment(string $sisi, int $contactId, int $invoiceId, string $tanggal, string $nominal, string $status = 'posted', ?int $journalId = null): int
    {
        $utang = $sisi === 'utang';
        $payId = $this->mkInsert($utang ? 'purchase_payments' : 'sales_receipts', [
            'public_id' => (string) Str::ulid(), 'nomor' => 'P/' . (++$this->seq), 'tanggal' => $tanggal, 'status' => $status,
            'contact_id' => $contactId, 'nominal' => $nominal, 'total' => $nominal, 'journal_id' => $journalId,
            ($utang ? 'rekening_pembayaran_id' : 'rekening_penerimaan_id') => $this->mkBank(),
        ]);
        $this->mkInsert($utang ? 'purchase_payment_allocations' : 'sales_receipt_allocations', [
            ($utang ? 'purchase_payment_id' : 'sales_receipt_id') => $payId,
            ($utang ? 'purchase_invoice_id' : 'sales_invoice_id') => $invoiceId,
            'nominal_dialokasikan' => $nominal,
        ]);

        return $payId;
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

    private function kontak(array $data, string $kode): ?array
    {
        return collect($data['kontak'])->firstWhere('kode', $kode);
    }

    // ------------------------------------------------------------------ bucket

    public function test_batas_bucket_tepat_di_hari_ke_0_30_31_60_61_90_91(): void
    {
        $this->assertSame('belum_jatuh_tempo', AgingReportService::bucketFor(-5));
        $this->assertSame('belum_jatuh_tempo', AgingReportService::bucketFor(0));
        $this->assertSame('d1_30', AgingReportService::bucketFor(1));
        $this->assertSame('d1_30', AgingReportService::bucketFor(30));
        $this->assertSame('d31_60', AgingReportService::bucketFor(31));
        $this->assertSame('d31_60', AgingReportService::bucketFor(60));
        $this->assertSame('d61_90', AgingReportService::bucketFor(61));
        $this->assertSame('d61_90', AgingReportService::bucketFor(90));
        $this->assertSame('d_lebih_90', AgingReportService::bucketFor(91));
    }

    public function test_umur_utang_membagi_faktur_ke_bucket_per_tanggal_laporan(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        // per 2026-11-30: lewat 0, 30, 31, 60, 61, 90, 91 hari
        $this->mkInvoice('utang', $s, 'PI-1', '2026-11-30', '100');
        $this->mkInvoice('utang', $s, 'PI-2', '2026-10-31', '200');
        $this->mkInvoice('utang', $s, 'PI-3', '2026-10-30', '300');
        $this->mkInvoice('utang', $s, 'PI-4', '2026-10-01', '400');
        $this->mkInvoice('utang', $s, 'PI-5', '2026-09-30', '500');
        $this->mkInvoice('utang', $s, 'PI-6', '2026-09-01', '600');
        $this->mkInvoice('utang', $s, 'PI-7', '2026-08-31', '700');

        Sanctum::actingAs($this->owner);
        $d = $this->api('reports/ap-aging?per_tanggal=2026-11-30')->assertOk()->json('data');

        $b = $this->kontak($d, 'S-1')['buckets'];
        $this->assertSame('100.0000', $b['belum_jatuh_tempo']);
        $this->assertSame('200.0000', $b['d1_30']);
        $this->assertSame('700.0000', $b['d31_60']);
        $this->assertSame('1100.0000', $b['d61_90']);
        $this->assertSame('700.0000', $b['d_lebih_90']);
        $this->assertSame('2800.0000', $this->kontak($d, 'S-1')['total']);
        $this->assertSame('2800.0000', $d['total']['total']);
        $this->assertSame(7, $d['total']['jumlah_faktur']);
    }

    public function test_jatuh_tempo_kosong_memakai_tanggal_faktur(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $id = $this->mkInvoice('utang', $s, 'PI-1', '2026-11-01', '100', tanggal: '2026-09-01');
        DB::table('purchase_invoices')->where('id', $id)->update(['jatuh_tempo' => null]);

        Sanctum::actingAs($this->owner);
        $d = $this->api('reports/ap-aging?per_tanggal=2026-11-30&mode=rinci')->assertOk()->json('data');

        $this->assertSame('d61_90', $this->kontak($d, 'S-1')['faktur'][0]['bucket']);
    }

    // ------------------------------------------------------------------ alokasi dan tanggal mundur

    public function test_sisa_dihitung_dari_alokasi_sampai_tanggal_laporan_dan_faktur_lunas_hilang(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $sebagian = $this->mkInvoice('utang', $s, 'PI-1', '2026-10-01', '1000');
        $lunas = $this->mkInvoice('utang', $s, 'PI-2', '2026-10-01', '500');
        $this->mkPayment('utang', $s, $sebagian, '2026-11-10', '400');
        $this->mkPayment('utang', $s, $lunas, '2026-11-10', '500');

        Sanctum::actingAs($this->owner);

        $akhir = $this->api('reports/ap-aging?per_tanggal=2026-11-30&mode=rinci')->assertOk()->json('data');
        $this->assertSame('600.0000', $akhir['total']['total']);
        $this->assertSame(1, $akhir['total']['jumlah_faktur']);
        $this->assertSame('PI-1', $this->kontak($akhir, 'S-1')['faktur'][0]['nomor']);

        $mundur = $this->api('reports/ap-aging?per_tanggal=2026-11-05')->assertOk()->json('data');
        $this->assertSame('1500.0000', $mundur['total']['total']); // pembayaran 10 Nov belum terjadi
        $this->assertSame(2, $mundur['total']['jumlah_faktur']);
    }

    public function test_faktur_bertanggal_setelah_tanggal_laporan_tidak_ikut(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $this->mkInvoice('utang', $s, 'PI-1', '2026-12-31', '100', tanggal: '2026-12-01');

        Sanctum::actingAs($this->owner);
        $d = $this->api('reports/ap-aging?per_tanggal=2026-11-30')->assertOk()->json('data');

        $this->assertSame('0.0000', $d['total']['total']);
        $this->assertSame([], $d['kontak']);
    }

    // ------------------------------------------------------------------ void

    public function test_faktur_void_dengan_pembalik_bertanggal_sama_tidak_pernah_muncul(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $j = $this->mkJournal('2026-03-01');
        $this->mkJournal('2026-03-01', $j);
        $this->mkInvoice('utang', $s, 'PI-1', '2026-04-01', '500', 'void', $j, '2026-03-01');

        Sanctum::actingAs($this->owner);

        foreach (['2026-03-15', '2026-11-30'] as $tgl) {
            $this->assertSame('0.0000', $this->api("reports/ap-aging?per_tanggal={$tgl}")->assertOk()->json('data.total.total'));
        }
    }

    public function test_faktur_void_dengan_pembalik_di_tanggal_kemudian_masih_berlaku_sebelum_pembalik(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $j = $this->mkJournal('2026-03-01');
        $this->mkJournal('2026-12-05', $j); // periode asal terkunci: pembalik memakai tanggal void
        $this->mkInvoice('utang', $s, 'PI-1', '2026-04-01', '500', 'void', $j, '2026-03-01');

        Sanctum::actingAs($this->owner);

        $this->assertSame('500.0000', $this->api('reports/ap-aging?per_tanggal=2026-11-30')->assertOk()->json('data.total.total'));
        $this->assertSame('0.0000', $this->api('reports/ap-aging?per_tanggal=2026-12-31')->assertOk()->json('data.total.total'));
    }

    public function test_pembayaran_void_tidak_mengurangi_sisa(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $inv = $this->mkInvoice('utang', $s, 'PI-1', '2026-10-01', '1000');
        $j = $this->mkJournal('2026-11-10');
        $this->mkJournal('2026-11-10', $j);
        $this->mkPayment('utang', $s, $inv, '2026-11-10', '400', 'void', $j);

        Sanctum::actingAs($this->owner);

        $this->assertSame('1000.0000', $this->api('reports/ap-aging?per_tanggal=2026-11-30')->assertOk()->json('data.total.total'));
    }

    // ------------------------------------------------------------------ piutang, mode, filter

    public function test_umur_piutang_memakai_faktur_penjualan_dan_alokasi_penerimaan(): void
    {
        $c = $this->mkContact('C-1', 'Pelanggan A', true);
        $inv = $this->mkInvoice('piutang', $c, 'INV-1', '2026-10-15', '1000');
        $this->mkPayment('piutang', $c, $inv, '2026-11-20', '250');
        // faktur pembelian tidak boleh bocor ke piutang
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $this->mkInvoice('utang', $s, 'PI-1', '2026-10-15', '9999');

        Sanctum::actingAs($this->owner);
        $d = $this->api('reports/ar-aging?per_tanggal=2026-11-30')->assertOk()->json('data');

        $this->assertSame('piutang', $d['sisi']);
        $this->assertSame('750.0000', $d['total']['total']);
        $this->assertSame('750.0000', $this->kontak($d, 'C-1')['buckets']['d31_60']); // 46 hari
        $this->assertNull($this->kontak($d, 'S-1'));
    }

    public function test_mode_ringkas_tanpa_daftar_faktur_dan_rinci_dengan_daftar(): void
    {
        $c = $this->mkContact('C-1', 'Pelanggan A', true);
        $this->mkInvoice('piutang', $c, 'INV-1', '2026-10-15', '1000');

        Sanctum::actingAs($this->owner);

        $ringkas = $this->api('reports/ar-aging?per_tanggal=2026-11-30')->assertOk()->json('data');
        $this->assertSame([], $this->kontak($ringkas, 'C-1')['faktur']);

        $rinci = $this->api('reports/ar-aging?per_tanggal=2026-11-30&mode=rinci')->assertOk()->json('data');
        $f = $this->kontak($rinci, 'C-1')['faktur'][0];
        $this->assertSame('INV-1', $f['nomor']);
        $this->assertSame(46, $f['hari_lewat_jatuh_tempo']);
        $this->assertSame('d31_60', $f['bucket']);
    }

    public function test_filter_kontak_dan_urutan_dari_saldo_terbesar(): void
    {
        $a = $this->mkContact('C-1', 'Pelanggan A', true);
        $b = $this->mkContact('C-2', 'Pelanggan B', true);
        $this->mkInvoice('piutang', $a, 'INV-1', '2026-10-15', '100');
        $this->mkInvoice('piutang', $b, 'INV-2', '2026-10-15', '900');

        Sanctum::actingAs($this->owner);

        $semua = $this->api('reports/ar-aging?per_tanggal=2026-11-30')->assertOk()->json('data');
        $this->assertSame(['C-2', 'C-1'], array_column($semua['kontak'], 'kode'));

        $satu = $this->api('reports/ar-aging?per_tanggal=2026-11-30&kontak_kode=C-1')->assertOk()->json('data');
        $this->assertSame(['C-1'], array_column($satu['kontak'], 'kode'));
        $this->assertSame('100.0000', $satu['total']['total']);
    }

    public function test_per_tanggal_tidak_valid_ditolak(): void
    {
        Sanctum::actingAs($this->owner);

        $this->api('reports/ar-aging?per_tanggal=bukan-tanggal')->assertStatus(422);
        $this->api('reports/ap-aging?mode=aneh')->assertStatus(422);
    }

    // ------------------------------------------------------------------ izin dan tenant

    public function test_izin_per_role(): void
    {
        $matrix = [
            // role => [piutang boleh, utang boleh]
            'Owner' => [true, true], 'Admin' => [true, true], 'Akuntan' => [true, true],
            'Kasir' => [true, false], 'Purchasing' => [false, true], 'Gudang' => [false, false],
        ];

        foreach ($matrix as $role => [$ar, $ap]) {
            Sanctum::actingAs($role === 'Owner' ? $this->owner : $this->memberWithRole($role));
            $this->api('reports/ar-aging')->assertStatus($ar ? 200 : 403);
            $this->api('reports/ap-aging')->assertStatus($ap ? 200 : 403);
        }
    }

    public function test_perusahaan_lain_tidak_melihat_faktur_perusahaan_ini(): void
    {
        $s = $this->mkContact('S-1', 'Pemasok A', false);
        $this->mkInvoice('utang', $s, 'PI-1', '2026-10-01', '1000');

        $ownerB = User::factory()->create();
        $b = $this->makeCompany($ownerB, 'PT B');
        Sanctum::actingAs($ownerB);

        $d = $this->api('reports/ap-aging?per_tanggal=2026-11-30', $this->h($b))->assertOk()->json('data');
        $this->assertSame('0.0000', $d['total']['total']);
        $this->assertSame([], $d['kontak']);
    }
}
