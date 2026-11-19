<?php

namespace App\Modules\FixedAssets\Disposal;

use App\Models\User;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\AssetDisposal;
use App\Modules\FixedAssets\Models\FixedAsset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posting Disposisi Aset Tetap lewat orkestrator ALG-02 (satu transaksi dengan nomor, jurnal, dan status aset).
 * Jurnal (Tahap 4): Dr Kas/Piutang . Dr Akumulasi Penyusutan . Dr Rugi Pelepasan // Cr Aset Tetap . Cr Laba Pelepasan.
 * Laba dan rugi memakai satu akun sistem laba_rugi_pelepasan_aset (7-1000): kredit bila laba, debit bila rugi.
 * Void: jurnal dibalik, aset kembali 'aktif'. Ditolak bila penyusutan periode SETELAH bulan pelepasan sudah dijalankan
 * (jadwal aset akan bolong), atau jurnal penerimaan sudah direkonsiliasi bank.
 */
class AssetDisposalPostingHandler extends AbstractPostingHandler
{
    public const DOC = 'asset_disposal';

    public function journalRules(): array
    {
        return [
            new JournalRule(self::DOC, 'D', 'laba_rugi_pelepasan_aset', '0', each: 'debit'),
            new JournalRule(self::DOC, 'K', 'laba_rugi_pelepasan_aset', '0', each: 'kredit'),
        ];
    }

    public function docType(): string
    {
        return self::DOC;
    }

    public function feature(): string
    {
        return 'disposisi_aset_tetap';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = AssetDisposal::query();
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id);
    }

    public function tanggal(Model $doc): string
    {
        return $doc->tanggal->toDateString();
    }

    public function status(Model $doc): string
    {
        return (string) $doc->status;
    }

    public function validate(Model $doc, User $user): void
    {
        $asset = FixedAsset::query()->findOrFail($doc->asset_id);
        if ($asset->status !== 'aktif') {
            throw PostingException::rule("Aset {$asset->kode} sudah dilepas.");
        }
        app(DisposalCalculator::class)->figures($asset, $this->tanggal($doc), (string) $doc->nilai_pelepasan); // lempar FixedAssetException
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $asset = FixedAsset::query()->lockForUpdate()->findOrFail($doc->asset_id); // kunci aset: urutan dokumen -> aset
        if ($asset->status !== 'aktif') {
            throw PostingException::rule("Aset {$asset->kode} sudah dilepas.");
        }

        $calc = app(DisposalCalculator::class);
        $f = $calc->figures($asset, $this->tanggal($doc), (string) $doc->nilai_pelepasan);
        $lists = $calc->journalLists(
            AssetCategory::query()->findOrFail($asset->category_id), $f, (string) $doc->nilai_pelepasan,
            $doc->akun_penerimaan_id !== null ? (int) $doc->akun_penerimaan_id : null, $asset->kode,
        );

        return ['lists' => $lists];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        $asset = FixedAsset::query()->lockForUpdate()->findOrFail($doc->asset_id);
        $f = app(DisposalCalculator::class)->figures($asset, $this->tanggal($doc), (string) $doc->nilai_pelepasan);

        $doc->forceFill([
            'nomor' => $result->nomor, 'status' => 'posted',
            'harga_perolehan' => $f['harga_perolehan'], 'akumulasi' => $f['akumulasi'], 'nilai_buku' => $f['nilai_buku'], 'laba_rugi' => $f['laba_rugi'],
            'journal_id' => $result->journalIds[0] ?? null, 'diposting_oleh' => $user->getKey(), 'diposting_pada' => now(), 'updated_by' => $user->getKey(),
        ])->save();

        $asset->forceFill(['status' => 'dilepas', 'tanggal_dilepas' => $this->tanggal($doc), 'updated_by' => $user->getKey()])->save();
    }

    /** Penghalang void: penyusutan bulan setelah bulan pelepasan sudah dijalankan. */
    public function blockers(Model $doc): array
    {
        $awal = CarbonImmutable::parse($this->tanggal($doc))->startOfMonth()->toDateString();

        return DB::table('depreciation_runs as r')
            ->join('accounting_periods as p', fn ($j) => $j->on('p.id', '=', 'r.period_id')->on('p.company_id', '=', 'r.company_id'))
            ->where('r.company_id', $doc->company_id)->where('p.tanggal_mulai', '>', $awal)
            ->orderBy('p.tanggal_mulai')->get(['p.tanggal_mulai'])
            ->map(fn ($r) => ['doc_type' => 'depreciation_run', 'nomor' => substr((string) $r->tanggal_mulai, 0, 7)])->all();
    }

    /** Jurnal penerimaan ke rekening bank yang sudah dicocokkan di rekonsiliasi (pemeriksaan tabel, bukan memanggil modul Kas & Bank). */
    public function isReconciled(Model $doc): bool
    {
        return DB::table('reconciliation_items as ri')
            ->join('journal_lines as jl', fn ($j) => $j->on('jl.id', '=', 'ri.journal_line_id')->on('jl.company_id', '=', 'ri.company_id'))
            ->join('journals as j', fn ($j) => $j->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id'))
            ->where('ri.company_id', $doc->company_id)->where('j.source_type', self::DOC)->where('j.source_id', $doc->getKey())
            ->exists();
    }

    public function restoreSources(Model $doc): void
    {
        FixedAsset::query()->where('id', $doc->asset_id)->update(['status' => 'aktif', 'tanggal_dilepas' => null]);
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill([
            'status' => 'void', 'void_oleh' => $user->getKey(), 'void_pada' => now(), 'alasan_void' => $reason, 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return ['asset_id' => (int) $doc->asset_id, 'nilai_pelepasan' => (string) $doc->nilai_pelepasan];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo ?: "Disposisi aset: {$doc->alasan}";
    }
}
