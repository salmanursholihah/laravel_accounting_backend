<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\JournalRuleEngine;
use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementApplier;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** ALG-04 (mesin aturan jurnal) dan ALG-02/ALG-07 (orkestrator posting dan void dokumen), dengan dokumen tiruan. */
class PostingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $co;
    private FakeInvoiceHandler $handler;
    private FakeStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tax_defaults.seed_on_company_created' => true]); // PPN bawaan dipakai untuk menghitung pajak
        $this->seed(RbacSeeder::class);

        $this->owner = User::factory()->create();
        $this->co = app(CreateCompany::class)->handle($this->owner, [
            'nama' => 'PT Posting', 'is_pkp' => true, 'tanggal_mulai_pembukuan' => '2026-01-01', 'template' => 'dagang',
        ]);

        $this->stock = new FakeStock();
        $this->app->instance(StockMovementApplier::class, $this->stock);

        $this->handler = new FakeInvoiceHandler();
        app(PostingHandlerRegistry::class)->register($this->handler);
        app(JournalRuleRegistry::class)->register(
            new JournalRule('sales_invoice', 'D', 'piutang_usaha', 'total', withContact: true),
            new JournalRule('sales_invoice', 'K', 'penjualan', 'dasar'),
            new JournalRule('sales_invoice', 'K', 'ppn_keluaran', 'total_pajak'),
            new JournalRule('sales_invoice', 'K', 'pembulatan', 'pembulatan_kredit'),
            new JournalRule('sales_invoice', 'D', 'pembulatan', 'pembulatan_debit'),
            new JournalRule('sales_invoice', 'D', 'hpp', 'hpp', when: 'ada_stok', group: 'cogs'),
            new JournalRule('sales_invoice', 'K', 'persediaan', 'hpp', when: 'ada_stok', group: 'cogs'),
        );

        $this->as($this->owner);
    }

    /** Menetapkan perusahaan aktif beserta keanggotaan user (seperti middleware 'company'). */
    private function as(User $u): void
    {
        $m = CompanyUser::where(['company_id' => $this->co->id, 'user_id' => $u->id])->firstOrFail();
        app(CurrentCompany::class)->set($this->co, $m);
        app(\App\Modules\Platform\Access\PermissionChecker::class)->flush();
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->co->id, 'user_id' => $u->id, 'status' => 'active',
            'role_id' => Role::whereNull('company_id')->where('nama', $role)->value('id'),
        ]);

        return $u;
    }

    private function acc(string $kode): int
    {
        return (int) Account::query()->where('kode', $kode)->value('id');
    }

    private function newDoc(array $over = []): Model
    {
        $ppn = app(TaxCodeService::class)->resolveByKode('PPN', '2026-11-17')->id;
        $this->handler->doc = new class extends Model {
            protected $guarded = [];
        };
        $this->handler->doc->forceFill($over + [
            'id' => 501, 'status' => 'draft', 'tanggal' => '2026-11-17', 'created_by' => $this->owner->id,
            'qty' => '10', 'harga' => '1000000', 'tax_code_id' => $ppn, 'contact_id' => null, 'ada_stok' => false,
        ]);

        return $this->handler->doc;
    }

    private function orch(): PostingOrchestrator
    {
        return app(PostingOrchestrator::class);
    }

    private function line(int $journalId, string $kode): ?object
    {
        return DB::table('journal_lines')->where(['journal_id' => $journalId, 'account_id' => $this->acc($kode)])->first();
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 4), "Diharapkan {$expected}, didapat {$actual}");
    }

    // ----------------------------------------------------------------------- posting

    public function test_posting_membuat_nomor_pajak_jurnal_otomatis_audit_dan_outbox(): void
    {
        $doc = $this->newDoc();

        $r = $this->orch()->post('sales_invoice', 501, $this->owner);

        $this->assertSame('INV/2026/11/00001', $r->nomor);
        $this->assertSame('posted', $doc->status);
        $this->assertSame('INV/2026/11/00001', $doc->nomor);
        $this->assertCount(1, $r->journalIds);

        $j = Journal::query()->findOrFail($r->journalIds[0]);
        $this->assertSame('auto', $j->tipe);
        $this->assertSame('sales_invoice', $j->source_type);
        $this->assertSame(501, (int) $j->source_id);
        $this->assertSame('JU/2026/11/00001', $j->nomor);
        $this->assertMoney('11100000', $j->total_debit);

        $this->assertMoney('11100000', $this->line($j->id, '1-1200')->debit); // Piutang Usaha
        $this->assertMoney('10000000', $this->line($j->id, '4-1000')->kredit); // Penjualan (dasar, bukan DPP)
        $this->assertMoney('1100000', $this->line($j->id, '2-1300')->kredit);  // PPN Keluaran

        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sales_invoice', 'entity_id' => 501, 'aksi' => 'post']);
        $this->assertDatabaseHas('outbox_events', ['company_id' => $this->co->id, 'event_type' => 'DocumentPosted', 'status' => 'pending']);
        $this->assertSame(1, DB::table('account_balances')->where(['company_id' => $this->co->id, 'account_id' => $this->acc('1-1200')])->count());
    }

    public function test_dokumen_bergerak_stok_menghasilkan_dua_jurnal_penjualan_dan_hpp(): void
    {
        $this->newDoc(['ada_stok' => true]);
        $this->stock->nilaiKeluar = '6000000';

        $r = $this->orch()->post('sales_invoice', 501, $this->owner);

        $this->assertCount(2, $r->journalIds);
        $cogs = Journal::query()->findOrFail($r->journalIds[1]);
        $this->assertMoney('6000000', $this->line($cogs->id, '5-1000')->debit);   // HPP
        $this->assertMoney('6000000', $this->line($cogs->id, '1-1300')->kredit);  // Persediaan
        $this->assertSame([['sales_invoice', 501]], array_map(fn ($a) => [$a[0], $a[1]], $this->stock->applied));
    }

    public function test_kontak_dibawa_ke_baris_subledger_dan_baris_akun_sama_digabung(): void
    {
        $cid = DB::table('contacts')->insertGetId([
            'company_id' => $this->co->id, 'kode' => 'C-0001', 'nama' => 'CV Sinar', 'is_pelanggan' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->newDoc(['contact_id' => $cid]);

        $r = $this->orch()->post('sales_invoice', 501, $this->owner);

        $this->assertSame($cid, (int) $this->line($r->journalIds[0], '1-1200')->contact_id);
        $this->assertNull($this->line($r->journalIds[0], '4-1000')->contact_id);
    }

    public function test_kegagalan_di_tengah_posting_membatalkan_semuanya_dan_nomor_tidak_berlubang(): void
    {
        $doc = $this->newDoc(['ada_stok' => true]);
        $this->stock->fail = true;

        try {
            $this->orch()->post('sales_invoice', 501, $this->owner);
            $this->fail('Seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertSame('stok gagal', $e->getMessage());
        }

        $this->assertSame('draft', $doc->status);
        $this->assertSame(0, DB::table('journals')->count());
        $this->assertSame(0, DB::table('account_balances')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());

        // percobaan berikutnya memakai nomor pertama: kegagalan tidak meninggalkan celah
        $this->stock->fail = false;
        $r = $this->orch()->post('sales_invoice', 501, $this->owner);
        $this->assertSame('INV/2026/11/00001', $r->nomor);
        $this->assertSame('JU/2026/11/00001', Journal::query()->findOrFail($r->journalIds[0])->nomor);
    }

    public function test_posting_dua_kali_ditolak(): void
    {
        $this->newDoc();
        $this->orch()->post('sales_invoice', 501, $this->owner);

        try {
            $this->orch()->post('sales_invoice', 501, $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('wrong_status', $e->errorCode);
        }
        $this->assertSame(1, DB::table('journals')->count());
    }

    public function test_periode_terkunci_atau_sebelum_mulai_pembukuan_ditolak(): void
    {
        $this->newDoc(['tanggal' => '2025-12-31']);
        $this->expectPostingFailure(fn () => $this->orch()->post('sales_invoice', 501, $this->owner), \App\Modules\Accounting\Periods\PeriodException::class);

        $this->newDoc(['tanggal' => '2026-11-17']);
        $periode = app(PeriodService::class)->periodFor('2026-11-17');
        app(PeriodService::class)->close(AccountingPeriod::query()->findOrFail($periode->id), $this->owner);
        $this->expectPostingFailure(fn () => $this->orch()->post('sales_invoice', 501, $this->owner), \App\Modules\Accounting\Periods\PeriodException::class);

        $this->assertSame(0, DB::table('journals')->count());
    }

    public function test_hak_akses_kasir_hanya_untuk_dokumen_miliknya(): void
    {
        $kasir = $this->member('Kasir');
        $this->as($kasir);

        $this->newDoc(['created_by' => $this->owner->id]); // dokumen orang lain
        try {
            $this->orch()->post('sales_invoice', 501, $kasir);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }

        $this->newDoc(['created_by' => $kasir->id]); // dokumen sendiri
        $this->assertSame('INV/2026/11/00001', $this->orch()->post('sales_invoice', 501, $kasir)->nomor);
    }

    public function test_jenis_dokumen_tak_dikenal_ditolak(): void
    {
        $this->expectPostingFailure(fn () => $this->orch()->post('tidak_ada', 1, $this->owner), PostingException::class);
    }

    // ----------------------------------------------------------------------- mesin aturan (ALG-04)

    public function test_mesin_menolak_nilai_negatif_dan_jurnal_tidak_seimbang(): void
    {
        $engine = app(JournalRuleEngine::class);
        $ctx = fn (array $v) => ['values' => $v + ['pembulatan_kredit' => '0', 'pembulatan_debit' => '0', 'hpp' => '0'], 'flags' => []];

        $this->expectPostingFailure(fn () => $engine->build('sales_invoice', $ctx(['total' => '100', 'dasar' => '90', 'total_pajak' => '9']), '2026-11-17', 1, 1), PostingException::class); // 100 != 99
        $this->expectPostingFailure(fn () => $engine->build('sales_invoice', $ctx(['total' => '-5', 'dasar' => '0', 'total_pajak' => '0']), '2026-11-17', 1, 1), PostingException::class);
        $this->expectPostingFailure(fn () => $engine->build('sales_invoice', $ctx(['total' => '0', 'dasar' => '0', 'total_pajak' => '0']), '2026-11-17', 1, 1), PostingException::class); // tanpa jurnal
    }

    public function test_aturan_each_override_akun_dan_ekspresi(): void
    {
        app(JournalRuleRegistry::class)->register(
            new JournalRule('uji_each', 'D', 'piutang_usaha', 'total-diskon'),
            new JournalRule('uji_each', 'K', 'penjualan', 'x', each: 'baris'),
        );

        $khusus = $this->acc('7-2000'); // Pendapatan Lain-lain sebagai override akun baris ke-2
        $drafts = app(JournalRuleEngine::class)->build('uji_each', [
            'values' => ['total' => '300', 'diskon' => '50'],
            'lists' => ['baris' => [
                ['amount' => '100'],
                ['amount' => '100'],               // digabung dengan baris pertama (akun sama)
                ['amount' => '50', 'account_id' => $khusus],
            ]],
        ], '2026-11-17', 9, 1);

        $this->assertCount(1, $drafts);
        $kredit = collect($drafts[0]->lines)->where('kredit', '!=', '0')->keyBy('account_id');
        $this->assertSame(200.0, (float) $kredit[$this->acc('4-1000')]['kredit']);
        $this->assertSame(50.0, (float) $kredit[$khusus]['kredit']);
        $this->assertSame(250.0, (float) collect($drafts[0]->lines)->firstWhere('account_id', $this->acc('1-1200'))['debit']);
    }

    // ----------------------------------------------------------------------- void (ALG-07)

    public function test_void_membalik_jurnal_stok_dan_memulihkan_sumber(): void
    {
        $doc = $this->newDoc(['ada_stok' => true]);
        $r = $this->orch()->post('sales_invoice', 501, $this->owner);

        $this->orch()->void('sales_invoice', 501, 'Salah input', $this->owner);

        $this->assertSame('void', $doc->status);
        $this->assertSame('Salah input', $doc->void_reason);
        $this->assertTrue($this->handler->sourcesRestored);
        $this->assertSame([['sales_invoice', 501]], array_map(fn ($a) => [$a[0], $a[1]], $this->stock->reversed));

        foreach ($r->journalIds as $id) {
            $this->assertTrue(Journal::query()->where('reversed_of_id', $id)->exists(), "Jurnal #{$id} belum dibalik.");
        }
        $this->assertSame(4, DB::table('journals')->count()); // 2 asli + 2 pembalik
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sales_invoice', 'entity_id' => 501, 'aksi' => 'void', 'alasan' => 'Salah input']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DocumentVoided']);
    }

    public function test_void_ditolak_bila_ada_turunan_aktif_atau_sudah_direkonsiliasi_atau_tanpa_alasan(): void
    {
        $this->newDoc();
        $this->orch()->post('sales_invoice', 501, $this->owner);

        $this->handler->blockerList = [['doc_type' => 'sales_receipt', 'nomor' => 'RC/2026/11/00033']];
        try {
            $this->orch()->void('sales_invoice', 501, 'x', $this->owner);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('has_active_children', $e->errorCode);
            $this->assertStringContainsString('RC/2026/11/00033', $e->getMessage());
        }

        $this->handler->blockerList = [];
        $this->handler->reconciled = true;
        $this->expectPostingFailure(fn () => $this->orch()->void('sales_invoice', 501, 'x', $this->owner), PostingException::class);

        $this->handler->reconciled = false;
        $this->expectPostingFailure(fn () => $this->orch()->void('sales_invoice', 501, '  ', $this->owner), PostingException::class);

        $this->assertSame(1, DB::table('journals')->count()); // tidak ada pembalik yang bocor
        $this->assertSame('posted', $this->handler->doc->status);
    }

    public function test_kasir_tidak_boleh_void(): void
    {
        $kasir = $this->member('Kasir');
        $this->newDoc(['created_by' => $kasir->id]);
        $this->as($kasir);
        $this->orch()->post('sales_invoice', 501, $kasir);

        try {
            $this->orch()->void('sales_invoice', 501, 'alasan', $kasir);
            $this->fail('Seharusnya ditolak.');
        } catch (PostingException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
    }

    private function expectPostingFailure(callable $fn, string $exception): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            return;
        }
        $this->fail("Seharusnya melempar {$exception}.");
    }
}

/** Gerakan stok tiruan: mencatat panggilan, nilai keluar bisa diatur, bisa dipaksa gagal. */
class FakeStock implements StockMovementApplier
{
    public array $applied = [];
    public array $reversed = [];
    public string $nilaiKeluar = '0';
    public bool $fail = false;

    public function apply(string $sourceType, int $sourceId, string $tanggal, array $movements): StockMovementResult
    {
        if ($this->fail) {
            throw new RuntimeException('stok gagal');
        }
        $this->applied[] = [$sourceType, $sourceId, $movements];

        return new StockMovementResult([], '0', $this->nilaiKeluar);
    }

    public function reverse(string $sourceType, int $sourceId, string $tanggal): void
    {
        $this->reversed[] = [$sourceType, $sourceId, $tanggal];
    }
}

/** Handler Faktur Penjualan tiruan: dokumen = Model dalam memori; menunjukkan pola yang dipakai modul Penjualan nanti. */
class FakeInvoiceHandler extends AbstractPostingHandler
{
    public ?Model $doc = null;
    public array $blockerList = [];
    public bool $reconciled = false;
    public bool $sourcesRestored = false;

    public function docType(): string { return 'sales_invoice'; }
    public function feature(): string { return 'faktur_penjualan'; }
    public function find(int $id, bool $lock = false): Model { return $this->doc ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException(); }
    public function tanggal(Model $doc): string { return (string) $doc->tanggal; }
    public function status(Model $doc): string { return (string) $doc->status; }
    public function blockers(Model $doc): array { return $this->blockerList; }
    public function isReconciled(Model $doc): bool { return $this->reconciled; }
    public function restoreSources(Model $doc): void { $this->sourcesRestored = true; }
    public function eventPayload(Model $doc): array { return ['total' => (string) ($doc->total ?? '0')]; }

    public function taxInput(Model $doc): ?array
    {
        return ['tanggal' => $doc->tanggal, 'lines' => [['qty' => $doc->qty, 'harga' => $doc->harga, 'tax_code_id' => $doc->tax_code_id]]];
    }

    public function stockMovements(Model $doc): array
    {
        return $doc->ada_stok ? [['item_id' => 1, 'warehouse_id' => 1, 'arah' => 'out', 'qty' => '10']] : [];
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        // Pola pembulatan: aturan jurnal tidak menerima nilai negatif, jadi handler memecahnya ke sisi debit dan kredit.
        $bulat = $taxes['pembulatan'];

        return [
            'values' => [
                'total' => $taxes['total'], 'dasar' => $taxes['dasar'], 'total_pajak' => $taxes['total_pajak'],
                'pembulatan_kredit' => bccomp($bulat, '0', 4) > 0 ? $bulat : '0',
                'pembulatan_debit' => bccomp($bulat, '0', 4) < 0 ? ltrim($bulat, '-') : '0',
                'hpp' => $stock->nilaiKeluar,
            ],
            'flags' => ['ada_stok' => (bool) $doc->ada_stok],
            'contact_id' => $doc->contact_id,
        ];
    }

    public function markPosted(Model $doc, PostingResult $result, \App\Models\User $user): void
    {
        $doc->nomor = $result->nomor;
        $doc->status = 'posted';
        $doc->total = $result->taxes['total'];
    }

    public function markVoid(Model $doc, string $reason, \App\Models\User $user): void
    {
        $doc->status = 'void';
        $doc->void_reason = $reason;
    }
}
