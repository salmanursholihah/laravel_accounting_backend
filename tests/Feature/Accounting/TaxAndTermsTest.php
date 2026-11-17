<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\PaymentTerms\PaymentTermException;
use App\Modules\Accounting\PaymentTerms\PaymentTermService;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Accounting\Tax\TaxException;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxAndTermsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
    }

    private function company(bool $pkp = true, ?User $owner = null): Company
    {
        return app(CreateCompany::class)->handle($owner ?? $this->owner, [
            'nama' => 'PT Pajak ' . uniqid(), 'is_pkp' => $pkp, 'tanggal_mulai_pembukuan' => '2026-01-01',
        ]);
    }

    private function in(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, $fn);
    }

    private function member(Company $c, string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create([
            'company_id' => $c->id, 'user_id' => $u->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id'),
        ]);

        return $u;
    }

    private function ppn(array $over = []): array
    {
        return $over + [
            'kode' => 'PPN', 'nama' => 'PPN', 'jenis' => 'ppn', 'tarif' => '0.12',
            'dpp_pembilang' => 11, 'dpp_penyebut' => 12, 'berlaku_dari' => '2025-01-01',
        ];
    }

    public function test_tarif_berversi_versi_lama_ditutup_dan_dipilih_menurut_tanggal(): void
    {
        $c = $this->company();

        $this->in($c, function () {
            $svc = app(TaxCodeService::class);
            $lama = $svc->create($this->ppn(['tarif' => '0.11', 'dpp_pembilang' => 1, 'dpp_penyebut' => 1, 'berlaku_dari' => '2022-04-01']));
            $baru = $svc->create($this->ppn());

            $this->assertSame('2024-12-31', $lama->fresh()->berlaku_sampai->toDateString());
            $this->assertNull($baru->berlaku_sampai);

            // tanggal lama -> tarif lama; tanggal baru -> tarif baru (lewat id versi mana pun)
            $this->assertEquals($lama->id, $svc->resolveAt($baru->id, '2024-06-15')->id);
            $this->assertEquals($baru->id, $svc->resolveAt($lama->id, '2026-11-17')->id);
            $this->assertSame('0.110000', (string) $svc->resolveByKode('PPN', '2024-12-31')->tarif);
            $this->assertSame('0.120000', (string) $svc->resolveByKode('PPN', '2025-01-01')->tarif);
        });
    }

    public function test_tanggal_tanpa_tarif_ditolak(): void
    {
        $c = $this->company();
        $this->in($c, function () {
            app(TaxCodeService::class)->create($this->ppn());
            $this->expectException(TaxException::class);
            app(TaxCodeService::class)->resolveByKode('PPN', '2024-01-01');
        });
    }

    public function test_versi_bertabrakan_atau_kembar_ditolak(): void
    {
        $c = $this->company();
        $this->in($c, function () {
            $svc = app(TaxCodeService::class);
            $svc->create($this->ppn(['berlaku_dari' => '2025-01-01', 'berlaku_sampai' => '2025-12-31']));

            try {
                $svc->create($this->ppn(['berlaku_dari' => '2025-06-01']));
                $this->fail('Versi yang tumpang tindih harus ditolak.');
            } catch (TaxException $e) {
                $this->assertStringContainsString('bertabrakan', $e->getMessage());
            }

            $this->expectException(TaxException::class);
            $svc->create($this->ppn(['berlaku_dari' => '2025-01-01']));
        });
    }

    public function test_tarif_dan_dpp_tidak_valid_ditolak(): void
    {
        $c = $this->company();
        $this->in($c, function () {
            $svc = app(TaxCodeService::class);
            foreach ([
                ['tarif' => '1.5'],
                ['tarif' => '-0.1'],
                ['dpp_pembilang' => 13, 'dpp_penyebut' => 12],
                ['dpp_pembilang' => 0],
            ] as $bad) {
                try {
                    $svc->create($this->ppn($bad));
                    $this->fail('Harus ditolak: ' . json_encode($bad));
                } catch (TaxException) {
                    $this->assertTrue(true);
                }
            }
        });
    }

    public function test_versi_yang_dipakai_tidak_bisa_diubah_tarifnya_atau_dihapus(): void
    {
        $c = $this->company();
        $this->in($c, function () use ($c) {
            $svc = app(TaxCodeService::class);
            $tax = $svc->create($this->ppn());

            // belum dipakai: boleh ubah tarif
            $svc->update($tax, ['tarif' => '0.13']);
            $this->assertSame('0.130000', (string) $tax->fresh()->tarif);

            // dipakai dokumen -> terkunci (document_taxes wajib punya baris)
            DB::table('document_taxes')->insert($this->documentTaxRow($c, $tax));

            try {
                $svc->update($tax->fresh(), ['tarif' => '0.14']);
                $this->fail('Tarif versi terpakai tidak boleh diubah.');
            } catch (TaxException $e) {
                $this->assertStringContainsString('versi baru', $e->getMessage());
            }

            $svc->update($tax->fresh(), ['nama' => 'PPN Keluaran']); // nama tetap boleh
            $this->assertSame('PPN Keluaran', $tax->fresh()->nama);

            $this->expectException(TaxException::class);
            $svc->delete($tax->fresh());
        });
    }

    /** Baris document_taxes minimal; sesuaikan kolom bila skema 9b berbeda. */
    private function documentTaxRow(Company $c, TaxCode $tax): array
    {
        $cols = DB::getSchemaBuilder()->getColumnListing('document_taxes');
        $row = [
            'company_id' => $c->id, 'doc_type' => 'sales_invoice', 'doc_id' => 1, 'line_id' => null,
            'tax_code_id' => $tax->id, 'dpp' => '100.0000', 'tarif' => '0.120000', 'nominal' => '12.0000',
            'created_at' => now(), 'updated_at' => now(),
        ];

        return array_intersect_key($row, array_flip($cols));
    }

    public function test_akun_pajak_harus_postable_milik_perusahaan_sendiri(): void
    {
        $a = $this->company();
        $b = $this->company(true, User::factory()->create());

        $akunB = $this->in($b, fn () => Account::query()->where('is_header', false)->firstOrFail());
        $header = $this->in($a, fn () => Account::query()->where('is_header', true)->firstOrFail());
        $ok = $this->in($a, fn () => Account::query()->where('kode', '2-1300')->firstOrFail());

        $this->in($a, function () use ($akunB, $header, $ok) {
            $svc = app(TaxCodeService::class);
            foreach ([$akunB->id, $header->id] as $id) {
                try {
                    $svc->create($this->ppn(['akun_penjualan_id' => $id]));
                    $this->fail('Akun tidak valid harus ditolak.');
                } catch (TaxException) {
                    $this->assertTrue(true);
                }
            }
            $tax = $svc->create($this->ppn(['akun_penjualan_id' => $ok->id]));
            $this->assertEquals($ok->id, $tax->akun_penjualan_id);
        });
    }

    public function test_non_pkp_ppn_dibuat_nonaktif_dan_tidak_bisa_diaktifkan(): void
    {
        $c = $this->company(false);
        $this->in($c, function () {
            $svc = app(TaxCodeService::class);
            $ppn = $svc->create($this->ppn());
            $this->assertFalse($ppn->is_aktif);

            try {
                $svc->update($ppn, ['is_aktif' => true]);
                $this->fail('PPN non-PKP tidak boleh aktif.');
            } catch (TaxException) {
                $this->assertTrue(true);
            }

            // jenis lain (PPh) tidak terpengaruh mode PKP
            $pph = $svc->create(['kode' => 'PPH23', 'nama' => 'PPh 23', 'jenis' => 'pph', 'tarif' => '0.02', 'berlaku_dari' => '2025-01-01']);
            $this->assertTrue($pph->is_aktif);
        });
    }

    public function test_ganti_ke_non_pkp_menonaktifkan_semua_ppn_otomatis(): void
    {
        $c = $this->company(true);
        $this->in($c, function () {
            $svc = app(TaxCodeService::class);
            $svc->create($this->ppn());
            $svc->create($this->ppn(['kode' => 'PPNBM10', 'jenis' => 'ppnbm', 'tarif' => '0.10']));
            $svc->create(['kode' => 'PPH23', 'nama' => 'PPh 23', 'jenis' => 'pph', 'tarif' => '0.02', 'berlaku_dari' => '2025-01-01']);
        });

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings', ['mode_pajak' => 'non_pkp'], ['X-Company-Id' => $c->public_id])->assertOk();

        $this->in($c, function () {
            $this->assertFalse(TaxCode::where('kode', 'PPN')->value('is_aktif'));
            $this->assertTrue((bool) TaxCode::where('kode', 'PPNBM10')->value('is_aktif')); // hanya PPN
            $this->assertTrue((bool) TaxCode::where('kode', 'PPH23')->value('is_aktif'));
            $this->assertSame(1, DB::table('audit_logs')->where('company_id', app(CurrentCompany::class)->id())->where('aksi', 'deactivate_ppn_non_pkp')->count());
        });
    }

    public function test_api_pajak_crud_dan_filter_tanggal(): void
    {
        $c = $this->company();
        $h = ['X-Company-Id' => $c->public_id];
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/tax-codes', $this->ppn(['tarif' => '0.11', 'dpp_pembilang' => 1, 'dpp_penyebut' => 1, 'berlaku_dari' => '2022-04-01']), $h)->assertCreated();
        $this->postJson('/api/v1/tax-codes', $this->ppn(), $h)->assertCreated()->assertJsonPath('data.tarif', '0.120000');

        $this->getJson('/api/v1/tax-codes?tanggal=2024-06-01', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tarif', '0.110000');
        $this->getJson('/api/v1/tax-codes?tanggal=2026-11-17', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tarif', '0.120000');
        $this->getJson('/api/v1/tax-codes', $h)->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/tax-codes', $this->ppn(['berlaku_dari' => '2025-01-01']), $h)->assertStatus(422)->assertJsonPath('code', 'tax_rule');
    }

    public function test_api_isolasi_lintas_perusahaan_untuk_pajak_dan_syarat(): void
    {
        $a = $this->company();
        $ownerB = User::factory()->create();
        $b = $this->company(true, $ownerB);

        $taxB = $this->in($b, fn () => app(TaxCodeService::class)->create($this->ppn()));
        $termB = $this->in($b, fn () => app(PaymentTermService::class)->create(['nama' => 'Net 30', 'jumlah_hari' => 30]));

        Sanctum::actingAs($this->owner); // owner perusahaan A
        $hA = ['X-Company-Id' => $a->public_id];

        $this->getJson('/api/v1/tax-codes', $hA)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payment-terms', $hA)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/tax-codes/{$taxB->id}", ['nama' => 'x'], $hA)->assertNotFound();
        $this->deleteJson("/api/v1/tax-codes/{$taxB->id}", [], $hA)->assertNotFound();
        $this->putJson("/api/v1/payment-terms/{$termB->id}", ['nama' => 'x'], $hA)->assertNotFound();
        $this->deleteJson("/api/v1/payment-terms/{$termB->id}", [], $hA)->assertNotFound();

        // owner A tidak bisa memakai header perusahaan B
        $this->getJson('/api/v1/tax-codes', ['X-Company-Id' => $b->public_id])->assertStatus(403);
    }

    public function test_hak_akses_pajak_dan_syarat_per_role(): void
    {
        $c = $this->company();
        $h = ['X-Company-Id' => $c->public_id];

        Sanctum::actingAs($this->member($c, 'Akuntan')); // C: lihat, tambah, ubah, hapus
        $this->postJson('/api/v1/tax-codes', $this->ppn(), $h)->assertCreated();
        $this->postJson('/api/v1/payment-terms', ['nama' => 'Net 14', 'jumlah_hari' => 14], $h)->assertCreated();

        Sanctum::actingAs($this->member($c, 'Kasir')); // hanya lihat di modul Perusahaan
        $this->getJson('/api/v1/tax-codes', $h)->assertOk();
        $this->postJson('/api/v1/tax-codes', $this->ppn(['kode' => 'X']), $h)->assertStatus(403);
        $this->postJson('/api/v1/payment-terms', ['nama' => 'Net 7', 'jumlah_hari' => 7], $h)->assertStatus(403);

        Sanctum::actingAs($this->member($c, 'Gudang'));
        $this->postJson('/api/v1/tax-codes', $this->ppn(['kode' => 'Y']), $h)->assertStatus(403);
    }

    public function test_syarat_pembayaran_jatuh_tempo_dan_diskon(): void
    {
        $c = $this->company();
        $this->in($c, function () {
            $svc = app(PaymentTermService::class);
            $term = $svc->create(['nama' => '2/10 Net 30', 'jumlah_hari' => 30, 'diskon_persen' => '2', 'diskon_hari' => 10]);

            $this->assertSame('2026-12-17', $term->jatuhTempo('2026-11-17')->toDateString());
            $this->assertSame('2026-11-27', $term->batasDiskon('2026-11-17')->toDateString());

            $tunai = $svc->create(['nama' => 'Tunai', 'jumlah_hari' => 0]);
            $this->assertSame('2026-11-17', $tunai->jatuhTempo('2026-11-17')->toDateString());
            $this->assertNull($tunai->batasDiskon('2026-11-17'));
        });
    }

    public function test_syarat_pembayaran_validasi_dan_duplikat(): void
    {
        $c = $this->company();
        $this->in($c, function () {
            $svc = app(PaymentTermService::class);
            $svc->create(['nama' => 'Net 30', 'jumlah_hari' => 30]);

            foreach ([
                ['nama' => 'Net 30', 'jumlah_hari' => 45],                                             // duplikat
                ['nama' => 'A', 'jumlah_hari' => 10, 'diskon_persen' => '2', 'diskon_hari' => 0],      // diskon tanpa hari
                ['nama' => 'B', 'jumlah_hari' => 10, 'diskon_persen' => '2', 'diskon_hari' => 20],     // hari diskon > jatuh tempo
                ['nama' => 'C', 'jumlah_hari' => 10, 'diskon_persen' => '0', 'diskon_hari' => 5],      // hari tanpa persen
                ['nama' => 'D', 'jumlah_hari' => 10, 'diskon_persen' => '101', 'diskon_hari' => 5],    // > 100%
            ] as $bad) {
                try {
                    $svc->create($bad);
                    $this->fail('Harus ditolak: ' . json_encode($bad));
                } catch (PaymentTermException) {
                    $this->assertTrue(true);
                }
            }
        });
    }

    public function test_api_syarat_pembayaran_crud(): void
    {
        $c = $this->company();
        $h = ['X-Company-Id' => $c->public_id];
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/v1/payment-terms', ['nama' => 'Net 30', 'jumlah_hari' => 30], $h)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/payment-terms/{$id}", ['jumlah_hari' => 45, 'is_aktif' => false], $h)->assertOk()->assertJsonPath('data.jumlah_hari', 45);
        $this->getJson('/api/v1/payment-terms?aktif=1', $h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payment-terms', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/payment-terms/{$id}", [], $h)->assertOk();
        $this->postJson('/api/v1/payment-terms', ['nama' => 'X', 'jumlah_hari' => 10, 'diskon_persen' => 2, 'diskon_hari' => 0], $h)->assertStatus(422);
    }
}
