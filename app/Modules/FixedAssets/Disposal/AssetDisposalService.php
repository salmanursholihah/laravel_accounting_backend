<?php

namespace App\Modules\FixedAssets\Disposal;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\AssetDisposal;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draf Disposisi Aset Tetap (UC-57). Hanya Draft yang boleh diubah/dihapus. Angka (akumulasi, nilai buku, laba/rugi), nomor,
 * dan jurnal dibuat saat Posting lewat orkestrator (ALG-02) + AssetDisposalPostingHandler; di sini hanya pratinjau.
 * Satu disposisi aktif per aset (dijaga UNIQUE aktif_key di database dan dicek di sini untuk pesan yang jelas).
 */
class AssetDisposalService
{
    public const DOC = 'asset_disposal';

    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
        private PeriodService $periods,
        private DisposalCalculator $calc,
        private SystemAccountService $accounts,
    ) {
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'asset_id' => ['required', 'integer'],
            'nilai_pelepasan' => ['sometimes', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'akun_penerimaan_id' => ['nullable', 'integer'],
            'alasan' => ['required', 'string', 'max:500'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function find(int $id): AssetDisposal
    {
        return AssetDisposal::query()->findOrFail($id);
    }

    public function list(array $f = []): array
    {
        $limit = min(100, max(1, (int) ($f['limit'] ?? 50)));
        $q = AssetDisposal::query()->orderByDesc('id');
        foreach (['status', 'asset_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['before_id'])) {
            $q->where('id', '<', (int) $f['before_id']);
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        return ['data' => $rows, 'next_before_id' => $more ? (int) $rows->last()->id : null];
    }

    /** Pratinjau laba/rugi dan jurnal TANPA menyimpan apa pun. Gagal bila syarat penyusutan belum terpenuhi. */
    public function preview(int $assetId, string $tanggal, string $nilaiPelepasan, ?int $akunPenerimaanId): array
    {
        $asset = FixedAsset::query()->findOrFail($assetId);
        if ($asset->status !== 'aktif') {
            throw new FixedAssetException('Aset sudah dilepas.', 'asset_disposed', 409);
        }
        $tanggal = Carbon::parse($tanggal)->toDateString();
        $nilai = Money::round($nilaiPelepasan);

        $f = $this->calc->figures($asset, $tanggal, $nilai);
        $lists = $this->calc->journalLists(AssetCategory::query()->findOrFail($asset->category_id), $f, $nilai, $akunPenerimaanId, $asset->kode);

        $baris = [];
        foreach (['debit' => 'D', 'kredit' => 'K'] as $list => $sisi) {
            foreach ($lists[$list] as $b) {
                if (Money::isZero($b['amount'])) {
                    continue;
                }
                $akun = isset($b['account_id']) ? Account::query()->find($b['account_id']) : $this->accounts->resolve($b['account_key']);
                $baris[] = ['sisi' => $sisi, 'account_id' => $akun?->getKey(), 'kode' => $akun?->kode, 'nama' => $akun?->nama, 'jumlah' => $b['amount']];
            }
        }

        return ['asset_id' => (int) $asset->getKey(), 'tanggal' => $tanggal, 'nilai_pelepasan' => $nilai] + $f + ['jurnal' => $baris];
    }

    public function create(array $data, User $user): AssetDisposal
    {
        $header = $this->prepare($data, null);

        return DB::transaction(function () use ($header, $user) {
            $d = AssetDisposal::create($header + ['status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            $this->audit->log(self::DOC, (int) $d->getKey(), 'create', null, $header, null, null, (int) $user->getKey());

            return $d;
        });
    }

    public function update(int $id, array $data, User $user): AssetDisposal
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $d = AssetDisposal::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($d);
            if (isset($data['version']) && (int) $data['version'] !== (int) $d->version) {
                throw new FixedAssetException('Dokumen sudah diubah orang lain. Muat ulang lalu ulangi perubahan Anda.', 'edit_conflict', 409);
            }

            $before = $d->only(['tanggal', 'asset_id', 'nilai_pelepasan', 'akun_penerimaan_id', 'alasan', 'memo']);
            $header = $this->prepare($data, $d);
            $d->forceFill($header + ['version' => (int) $d->version + 1, 'updated_by' => $user->getKey()])->save();
            $this->audit->log(self::DOC, $id, 'update', $before, $header, null, null, (int) $user->getKey());

            return $d;
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $d = AssetDisposal::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($d);
            $this->audit->log(self::DOC, $id, 'delete', $d->only(['asset_id', 'tanggal', 'nilai_pelepasan']), null, null, null, (int) $user->getKey());
            $d->delete();
        });
    }

    public function present(AssetDisposal $d): array
    {
        $a = FixedAsset::query()->find($d->asset_id);

        return [
            'id' => (int) $d->id, 'nomor' => $d->nomor, 'status' => $d->status, 'tanggal' => $d->tanggal->toDateString(),
            'asset' => ['id' => (int) $d->asset_id, 'kode' => $a?->kode, 'nama' => $a?->nama],
            'nilai_pelepasan' => Money::round((string) $d->nilai_pelepasan), 'akun_penerimaan_id' => $d->akun_penerimaan_id,
            'alasan' => $d->alasan, 'memo' => $d->memo,
            'harga_perolehan' => $d->harga_perolehan !== null ? (string) $d->harga_perolehan : null,
            'akumulasi' => $d->akumulasi !== null ? (string) $d->akumulasi : null,
            'nilai_buku' => $d->nilai_buku !== null ? (string) $d->nilai_buku : null,
            'laba_rugi' => $d->laba_rugi !== null ? (string) $d->laba_rugi : null,
            'journal_id' => $d->journal_id, 'version' => (int) $d->version,
            'diposting_pada' => $d->diposting_pada?->toIso8601String(), 'void_pada' => $d->void_pada?->toIso8601String(), 'alasan_void' => $d->alasan_void,
        ];
    }

    // ------------------------------------------------------------------------------------------ internal

    private function assertDraft(AssetDisposal $d): void
    {
        if ($d->status !== 'draft') {
            throw new FixedAssetException("Disposisi berstatus '{$d->status}' tidak bisa diubah atau dihapus. Gunakan Void untuk membatalkan yang sudah diposting.", 'not_draft');
        }
    }

    private function prepare(array $data, ?AssetDisposal $existing): array
    {
        $tanggal = Carbon::parse($data['tanggal'] ?? $existing?->tanggal)->toDateString();
        $this->periods->assertPostable($tanggal);

        $asset = FixedAsset::query()->find((int) ($data['asset_id'] ?? $existing?->asset_id ?? 0))
            ?? throw new FixedAssetException('Aset tidak ditemukan.', 'asset_not_found', 404);
        if ($asset->status !== 'aktif') {
            throw new FixedAssetException("Aset {$asset->kode} sudah dilepas.", 'asset_disposed', 409);
        }
        if ($tanggal < $asset->tanggal_perolehan->toDateString()) {
            throw new FixedAssetException('Tanggal pelepasan tidak boleh sebelum tanggal perolehan aset.', 'disposal_before_acquisition');
        }

        $ada = AssetDisposal::query()->where('asset_id', $asset->getKey())->where('status', '!=', 'void')
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->getKey()))->first();
        if ($ada) {
            throw new FixedAssetException("Aset {$asset->kode} sudah punya disposisi aktif (#{$ada->id}, {$ada->status}).", 'disposal_exists', 409);
        }

        $nilai = Money::round((string) ($data['nilai_pelepasan'] ?? $existing?->nilai_pelepasan ?? '0'));
        if (Money::cmp($nilai, '0') < 0) {
            throw new FixedAssetException('Nilai pelepasan tidak boleh negatif.', 'amount_invalid');
        }

        $akunId = array_key_exists('akun_penerimaan_id', $data) ? $data['akun_penerimaan_id'] : $existing?->akun_penerimaan_id;
        $akunId = $akunId !== null ? (int) $akunId : null;
        if (Money::cmp($nilai, '0') > 0) {
            $akun = $akunId ? Account::query()->find($akunId) : null;
            if (! $akun || $akun->tipe !== 'asset' || ! $akun->isPostable()) {
                throw new FixedAssetException('Akun penerimaan wajib berupa akun aset (kas/bank/piutang) yang aktif dan bukan akun induk bila nilai pelepasan lebih dari 0.', 'account_invalid');
            }
        } else {
            $akunId = null; // dihapus tanpa hasil
        }

        $alasan = trim((string) ($data['alasan'] ?? $existing?->alasan ?? ''));
        if ($alasan === '') {
            throw new FixedAssetException('Alasan disposisi wajib diisi.', 'reason_required');
        }

        return [
            'tanggal' => $tanggal, 'asset_id' => (int) $asset->getKey(), 'nilai_pelepasan' => $nilai, 'akun_penerimaan_id' => $akunId,
            'alasan' => mb_substr($alasan, 0, 500), 'memo' => array_key_exists('memo', $data) ? $data['memo'] : $existing?->memo,
        ];
    }
}
