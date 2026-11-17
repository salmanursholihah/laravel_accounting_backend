<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Ledger\AccountBalanceService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Ledger\JournalException;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Mesin jurnal: 8 validasi, nomor tanpa celah, saldo ringkas, void, jurnal koreksi, saldo awal, laporan. */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->owner = User::factory()->create();
        $this->co = $this->company($this->owner);
    }

    private function company(User $owner, string $nama = 'PT Uji'): Company
    {
        return app(CreateCompany::class)->handle($owner, ['nama' => $nama, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang']);
    }

    private function in(Company $c, callable $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($c, $fn);
    }

    private function acc(string $kode, ?Company $c = null): int
    {
        return $this->in($c ?? $this->co, fn () => Account::query()->where('kode', $kode)->value('id'));
    }

    private function h(?Company $c = null): array
    {
        return ['X-Company-Id' => ($c ?? $this->co)->public_id];
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

    /** Penjualan tunai 1.000.000: Dr Kas, Cr Penjualan. */
    private function payload(string $tanggal = '2026-03-10', string $nominal = '1000000'): array
    {
        return ['tanggal' => $tanggal, 'memo' => 'Uji', 'lines' => [
            ['account_id' => $this->acc('1-1110'), 'debit' => $nominal],
            ['account_id' => $this->acc('4-1000'), 'kredit' => $nominal],
        ]];
    }

    private function balanceOf(string $kode): array
    {
        $row = DB::table('account_balances')->where(['company_id' => $this->co->id, 'account_id' => $this->acc($kode)])->first();

        return $row ? [(string) (float) $row->debit, (string) (float) $row->kredit] : ['0', '0'];
    }

    // ---------------------------------------------------------------- posting dan 8 validasi

    public function test_posting_jurnal_umum_membuat_nomor_baris_dan_saldo_ringkas(): void
    {
        Sanctum::actingAs($this->owner);
        $res = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->assertCreated();

        $this->assertSame('JU/2026/03/00001', $res->json('data.nomor'));
        $j = Journal::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame('general', $j->tipe);
        $this->assertSame('manual', $j->source_type);
        $this->assertSame('1000000.0000', $j->total_debit);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_id', $j->id)->count());
        $this->assertSame(['1000000', '0'], $this->balanceOf('1-1110'));
        $this->assertSame(['0', '1000000'], $this->balanceOf('4-1000'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'journal', 'entity_id' => $j->id, 'aksi' => 'post']);
    }

    public function test_jurnal_tidak_seimbang_ditolak_dan_tidak_menyentuh_data_maupun_nomor(): void
    {
        Sanctum::actingAs($this->owner);
        $bad = $this->payload();
        $bad['lines'][1]['kredit'] = '999999';

        $this->postJson('/api/v1/journals', $bad, $this->h())->assertStatus(422)->assertJsonPath('code', 'unbalanced');
        $this->assertSame(0, DB::table('journals')->count());
        $this->assertSame(0, DB::table('account_balances')->count());

        // nomor pertama tetap 00001: kegagalan tidak membuat celah
        $this->postJson('/api/v1/journals', $this->payload(), $this->h())->assertCreated()->assertJsonPath('data.nomor', 'JU/2026/03/00001');
    }

    public function test_akun_induk_nonaktif_dan_akun_perusahaan_lain_ditolak(): void
    {
        Sanctum::actingAs($this->owner);

        $p = $this->payload();
        $p['lines'][0]['account_id'] = $this->acc('1-1000'); // header
        $this->postJson('/api/v1/journals', $p, $this->h())->assertStatus(422)->assertJsonPath('code', 'account_header');

        $this->in($this->co, fn () => Account::query()->where('kode', '4-1000')->update(['is_aktif' => false]));
        $this->postJson('/api/v1/journals', $this->payload(), $this->h())->assertStatus(422)->assertJsonPath('code', 'account_inactive');
        $this->in($this->co, fn () => Account::query()->where('kode', '4-1000')->update(['is_aktif' => true]));

        $other = $this->company(User::factory()->create(), 'PT Lain');
        $p = $this->payload();
        $p['lines'][0]['account_id'] = $this->acc('1-1110', $other);
        $this->postJson('/api/v1/journals', $p, $this->h())->assertStatus(422)->assertJsonPath('code', 'account_not_found');
        $this->assertSame(0, DB::table('journals')->count());
    }

    public function test_periode_terkunci_dan_sebelum_tanggal_mulai_pembukuan_ditolak(): void
    {
        $this->in($this->co, function () {
            $svc = app(PeriodService::class);
            $svc->close($svc->periodFor('2026-05-10'), $this->owner);
        });

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/journals', $this->payload('2026-05-20'), $this->h())->assertStatus(422)->assertJsonPath('code', 'period_closed');
        $this->postJson('/api/v1/journals', $this->payload('2025-12-31'), $this->h())->assertStatus(422)->assertJsonPath('code', 'before_book_start');
        $this->assertSame(0, DB::table('journals')->count());
    }

    public function test_source_type_wajib_untuk_jurnal_otomatis(): void
    {
        $this->in($this->co, function () {
            $this->expectException(JournalException::class);
            app(JournalService::class)->post(new JournalDraft(
                tanggal: '2026-03-10', tipe: 'auto', lines: $this->payload()['lines'], userId: $this->owner->id,
            ));
        });
    }

    public function test_jurnal_posted_tidak_bisa_diubah_atau_dihapus_langsung_di_database(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        $this->expectException(QueryException::class);
        DB::table('journal_lines')->where('journal_id', $id)->update(['debit' => 1]);
    }

    public function test_nomor_berurutan_tanpa_celah(): void
    {
        Sanctum::actingAs($this->owner);
        $nomor = [];
        for ($i = 0; $i < 5; $i++) {
            $nomor[] = $this->postJson('/api/v1/journals', $this->payload('2026-03-1' . $i), $this->h())->json('data.nomor');
        }
        $this->assertSame(['JU/2026/03/00001', 'JU/2026/03/00002', 'JU/2026/03/00003', 'JU/2026/03/00004', 'JU/2026/03/00005'], $nomor);
    }

    public function test_kegagalan_setelah_nomor_diambil_membatalkan_semuanya_dan_nomor_tidak_bolong(): void
    {
        $this->in($this->co, function () {
            $svc = app(JournalService::class);

            try {
                // branch_id tidak ada: gagal di FK saat INSERT jurnal, SETELAH nomor diambil
                $svc->post(new JournalDraft(
                    tanggal: '2026-03-10', tipe: 'general', lines: $this->payload()['lines'],
                    sourceType: 'manual', branchId: 999999, userId: $this->owner->id,
                ));
                $this->fail('Seharusnya gagal');
            } catch (QueryException) {
            }

            $this->assertSame(0, DB::table('journals')->count());
            $this->assertSame(0, DB::table('account_balances')->count());

            $j = $svc->post(new JournalDraft(
                tanggal: '2026-03-10', tipe: 'general', lines: $this->payload()['lines'], sourceType: 'manual', userId: $this->owner->id,
            ));
            $this->assertSame('JU/2026/03/00001', $j->nomor);
        });
    }

    // ---------------------------------------------------------------- void

    public function test_void_membuat_jurnal_pembalik_dan_saldo_kembali_nol(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        $res = $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'Salah input'], $this->h())->assertOk();

        $rev = Journal::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame('reversal', $rev->tipe);
        $this->assertSame($id, (int) $rev->reversed_of_id);
        $this->assertSame('2026-03-10', $rev->tanggal->toDateString()); // periode masih terbuka: tanggal asli
        $this->assertSame(['1000000', '1000000'], $this->balanceOf('1-1110'));
        $this->assertSame('void', $this->getJson("/api/v1/journals/{$id}", $this->h())->json('data.status'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'journal', 'entity_id' => $id, 'aksi' => 'void', 'alasan' => 'Salah input']);
    }

    public function test_void_wajib_alasan_dan_tidak_bisa_dua_kali_dan_pembalik_tidak_bisa_dibalik(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        $this->postJson("/api/v1/journals/{$id}/void", [], $this->h())->assertStatus(422);
        $revId = $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'x'], $this->h())->json('data.id');
        $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'lagi'], $this->h())->assertStatus(422)->assertJsonPath('code', 'already_reversed');
        $this->postJson("/api/v1/journals/{$revId}/void", ['alasan' => 'batal pembalik'], $this->h())->assertStatus(422)->assertJsonPath('code', 'not_reversible');
    }

    public function test_void_pada_periode_terkunci_memakai_tanggal_hari_ini(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload('2026-03-10'), $this->h())->json('data.id');

        $this->in($this->co, function () {
            $svc = app(PeriodService::class);
            $svc->close($svc->periodFor('2026-03-10'), $this->owner);
        });

        $res = $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'Koreksi'], $this->h())->assertOk();
        $this->assertSame('2026-11-17', $res->json('data.tanggal'));
    }

    public function test_jurnal_otomatis_dokumen_tidak_bisa_di_void_langsung(): void
    {
        $id = $this->in($this->co, fn () => app(JournalService::class)->post(new JournalDraft(
            tanggal: '2026-03-10', tipe: 'auto', lines: $this->payload()['lines'],
            sourceType: 'sales_invoice', sourceId: 99, userId: $this->owner->id,
        ))->id);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'x'], $this->h())->assertStatus(422)->assertJsonPath('code', 'not_reversible');

        // void dokumen (ALG-07) memakai JournalService::reverse() langsung dan boleh
        $this->in($this->co, function () use ($id) {
            $svc = app(JournalService::class);
            $this->assertCount(1, $svc->activeJournalsOf('sales_invoice', 99));
            $rev = $svc->reverse($id, 'Void faktur', $this->owner->id);
            $this->assertSame('sales_invoice', $rev->source_type); // tetap menunjuk dokumen sumber
            $this->assertCount(0, $svc->activeJournalsOf('sales_invoice', 99));
        });
    }

    // ---------------------------------------------------------------- hak akses dan jurnal koreksi

    public function test_hak_akses_posting_void_dan_koreksi(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        Sanctum::actingAs($this->memberWithRole('Kasir'));
        $this->postJson('/api/v1/journals', $this->payload(), $this->h())->assertForbidden();
        $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'x'], $this->h())->assertForbidden();

        Sanctum::actingAs($this->memberWithRole('Admin')); // Buku Besar hanya lihat
        $this->getJson('/api/v1/journals', $this->h())->assertOk();
        $this->postJson('/api/v1/journals', $this->payload(), $this->h())->assertForbidden();
        $this->postJson('/api/v1/journal-corrections', ['journal_asal_id' => $id] + $this->payload() + ['alasan' => 'x'], $this->h())->assertForbidden();
    }

    public function test_jurnal_koreksi_merujuk_jurnal_asal_dan_tidak_mengubahnya(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        Sanctum::actingAs($this->memberWithRole('Akuntan'));
        $koreksi = ['journal_asal_id' => $id, 'tanggal' => '2026-03-12', 'alasan' => 'Salah akun', 'lines' => [
            ['account_id' => $this->acc('1-1120'), 'debit' => '1000000'],
            ['account_id' => $this->acc('1-1110'), 'kredit' => '1000000'],
        ]];

        $this->postJson('/api/v1/journal-corrections', array_merge($koreksi, ['alasan' => '']), $this->h())->assertStatus(422);
        $res = $this->postJson('/api/v1/journal-corrections', $koreksi, $this->h())->assertCreated();

        $j = Journal::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame('correction', $j->tipe);
        $this->assertSame('journal', $j->source_type);
        $this->assertSame($id, (int) $j->source_id);
        $this->assertSame('posted', $this->getJson("/api/v1/journals/{$id}", $this->h())->json('data.status')); // asli utuh
    }

    // ---------------------------------------------------------------- saldo awal

    public function test_saldo_awal_harus_seimbang_dan_bisa_di_void_lalu_diposting_ulang(): void
    {
        Sanctum::actingAs($this->owner);
        $kas = $this->acc('1-1110');
        $modal = $this->acc('3-1000');

        $this->putJson('/api/v1/opening-balances', ['rows' => [
            ['account_id' => $kas, 'jumlah' => '5000000'], ['account_id' => $modal, 'jumlah' => '4000000'],
        ]], $this->h())->assertOk();
        $this->postJson('/api/v1/opening-balances/post', [], $this->h())->assertStatus(422)->assertJsonPath('code', 'unbalanced');

        $this->putJson('/api/v1/opening-balances', ['rows' => [
            ['account_id' => $kas, 'jumlah' => '5000000'], ['account_id' => $modal, 'jumlah' => '5000000'],
        ]], $this->h())->assertOk();
        $res = $this->postJson('/api/v1/opening-balances/post', [], $this->h())->assertCreated();

        $j = Journal::withoutGlobalScopes()->findOrFail($res->json('data.journal_id'));
        $this->assertTrue($j->is_opening);
        $this->assertSame('2026-01-01', $j->tanggal->toDateString());
        $this->assertSame('posted', $this->getJson('/api/v1/opening-balances', $this->h())->json('data.status'));

        // sudah diposting: tidak bisa diubah atau diposting dua kali
        $this->putJson('/api/v1/opening-balances', ['rows' => []], $this->h())->assertStatus(422)->assertJsonPath('code', 'opening_already_posted');
        $this->postJson('/api/v1/opening-balances/post', [], $this->h())->assertStatus(422);

        $this->postJson('/api/v1/opening-balances/void', ['alasan' => 'Salah angka'], $this->h())->assertOk();
        $this->assertSame('draft', $this->getJson('/api/v1/opening-balances', $this->h())->json('data.status'));
        $this->postJson('/api/v1/opening-balances/post', [], $this->h())->assertCreated(); // posting ulang
    }

    public function test_saldo_awal_piutang_wajib_per_kontak(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/opening-balances', ['rows' => [
            ['account_id' => $this->acc('1-1200'), 'jumlah' => '100000'], ['account_id' => $this->acc('3-1000'), 'jumlah' => '100000'],
        ]], $this->h())->assertOk();

        $this->postJson('/api/v1/opening-balances/post', [], $this->h())->assertStatus(422)->assertJsonPath('code', 'contact_required');
    }

    // ---------------------------------------------------------------- laporan dan integritas

    public function test_histori_akun_saldo_berjalan_dan_paginasi(): void
    {
        Sanctum::actingAs($this->owner);
        foreach (['2026-03-01' => '100000', '2026-03-05' => '250000', '2026-03-09' => '50000'] as $t => $n) {
            $this->postJson('/api/v1/journals', $this->payload($t, $n), $this->h())->assertCreated();
        }
        $kas = $this->acc('1-1110');
        $q = "/api/v1/reports/account-history?account_id={$kas}&dari=2026-03-01&sampai=2026-03-31";

        $all = $this->getJson($q, $this->h())->assertOk()->json('data');
        $this->assertSame(['100000.0000', '350000.0000', '400000.0000'], array_column($all['rows'], 'saldo'));
        $this->assertSame('400000.0000', $all['saldo_akhir']);

        $p1 = $this->getJson($q . '&limit=2', $this->h())->json('data');
        $this->assertCount(2, $p1['rows']);
        $p2 = $this->getJson($q . '&limit=2&cursor=' . urlencode($p1['next_cursor']), $this->h())->json('data');
        $this->assertSame(['400000.0000'], array_column($p2['rows'], 'saldo')); // saldo berjalan lanjut benar
        $this->assertNull($p2['next_cursor']);
    }

    public function test_neraca_saldo_seimbang_dan_saldo_ringkas_sama_dengan_journal_lines(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/journals', $this->payload('2026-03-02', '700000'), $this->h())->assertCreated();
        $id = $this->postJson('/api/v1/journals', $this->payload('2026-03-03', '300000'), $this->h())->json('data.id');
        $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'x'], $this->h());

        $periodId = $this->in($this->co, fn () => app(PeriodService::class)->periodFor('2026-03-15')->id);
        $tb = $this->getJson("/api/v1/reports/trial-balance?period_id={$periodId}", $this->h())->assertOk()->json('data');

        $this->assertTrue($tb['seimbang']);
        $this->assertSame($tb['total']['akhir_debit'], $tb['total']['akhir_kredit']);
        $kas = collect($tb['rows'])->firstWhere('kode', '1-1110');
        $this->assertSame('700000.0000', $kas['akhir_debit']);

        $this->in($this->co, function () {
            $svc = app(AccountBalanceService::class);
            $this->assertSame([], $svc->discrepancies());

            DB::table('account_balances')->where('company_id', $this->co->id)->limit(1)->update(['debit' => DB::raw('debit + 1')]); // rusak sengaja
            $this->assertNotSame([], $svc->discrepancies());

            $svc->rebuild();
            $this->assertSame([], $svc->discrepancies());
        });
    }

    public function test_jurnal_penutup_dipisah_dari_mutasi_biasa(): void
    {
        $this->in($this->co, function () {
            app(JournalService::class)->post(new JournalDraft(
                tanggal: '2026-03-10', tipe: 'general', lines: $this->payload()['lines'], sourceType: 'manual', userId: $this->owner->id,
            ));
            app(JournalService::class)->post(new JournalDraft(
                tanggal: '2026-03-31', tipe: 'closing', lines: [
                    ['account_id' => $this->acc('4-1000'), 'debit' => '1000000'],
                    ['account_id' => $this->acc('3-2000'), 'kredit' => '1000000'],
                ], sourceType: 'fiscal_year', userId: $this->owner->id,
            ));

            $pl = DB::table('account_balances')->where(['company_id' => $this->co->id, 'account_id' => $this->acc('4-1000')])->first();
            $this->assertSame(1000000.0, (float) $pl->kredit);
            $this->assertSame(1000000.0, (float) $pl->debit_penutup);
            $this->assertSame(0.0, (float) $pl->debit);

            $period = app(PeriodService::class)->periodFor('2026-03-10');
            $sebelum = app(AccountBalanceService::class)->trialBalance($period, false);
            $penjualan = collect($sebelum['rows'])->firstWhere('kode', '4-1000');
            $this->assertSame('1000000.0000', $penjualan['akhir_kredit']); // laba rugi sebelum penutup
        });
    }

    // ---------------------------------------------------------------- isolasi tenant

    public function test_perusahaan_lain_tidak_bisa_melihat_atau_membalik_jurnal(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/journals', $this->payload(), $this->h())->json('data.id');

        $ownerB = User::factory()->create();
        $b = $this->company($ownerB, 'PT B');
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/v1/journals/{$id}", $this->h($b))->assertNotFound();
        $this->postJson("/api/v1/journals/{$id}/void", ['alasan' => 'x'], $this->h($b))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/journals', $this->h($b))->json('data'));
    }
}
