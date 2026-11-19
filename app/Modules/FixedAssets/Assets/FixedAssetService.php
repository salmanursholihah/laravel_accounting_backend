<?php

namespace App\Modules\FixedAssets\Assets;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Master Aset Tetap (UC-54), metode garis lurus. Perolehan dicatat manual (tanpa jurnal perolehan; jurnalnya dibuat lewat
 * Jurnal Umum/Faktur Pembelian). Awal penyusutan mengikuti company_settings.aturan_awal_penyusutan.
 * Aset yang sudah punya jadwal penyusutan: field keuangan terkunci.
 */
class FixedAssetService
{
    public const FIELD_KEUANGAN = ['category_id', 'tanggal_perolehan', 'harga_perolehan', 'nilai_residu', 'umur_bulan'];

    public function __construct(private CurrentCompany $current, private AuditLogger $audit)
    {
    }

    /** @return array{data: Collection, next_before_id: ?int} */
    public function list(array $f = []): array
    {
        $limit = min(100, max(1, (int) ($f['limit'] ?? 50)));
        $q = FixedAsset::query()->orderByDesc('id');
        foreach (['status', 'category_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('kode', 'like', $like)->orWhere('nama', 'like', $like));
        }
        if (! empty($f['before_id'])) {
            $q->where('id', '<', (int) $f['before_id']);
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        return ['data' => $rows, 'next_before_id' => $more ? (int) $rows->last()->id : null];
    }

    public function find(int $id): FixedAsset
    {
        return FixedAsset::query()->findOrFail($id);
    }

    /** Jadwal penyusutan komersial satu aset, urut bulan. */
    public function schedule(int $id): array
    {
        $a = $this->find($id);

        return DB::table('depreciation_schedules as s')
            ->join('accounting_periods as p', fn ($j) => $j->on('p.id', '=', 's.period_id')->on('p.company_id', '=', 's.company_id'))
            ->where('s.company_id', $a->company_id)->where('s.asset_id', $id)->where('s.jenis', 'komersial')
            ->orderBy('p.tanggal_mulai')
            ->get(['s.bulan_ke', 'p.tanggal_mulai as periode', 's.nilai_penyusutan', 's.akumulasi', 's.nilai_buku', 's.journal_id'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function create(array $data, User $user): FixedAsset
    {
        return DB::transaction(function () use ($data, $user) {
            $kode = trim((string) ($data['kode'] ?? ''));
            if ($kode === '' || trim((string) ($data['nama'] ?? '')) === '') {
                throw new FixedAssetException('Kode dan nama aset wajib diisi.', 'validation');
            }
            if (FixedAsset::query()->where('kode', $kode)->exists()) {
                throw new FixedAssetException("Kode aset '{$kode}' sudah dipakai.", 'duplicate_code', 409);
            }

            $cat = $this->usableCategory((int) ($data['category_id'] ?? 0));
            $umur = (int) ($data['umur_bulan'] ?? $cat->umur_default_bulan ?? 0);
            [$harga, $residu] = $this->amounts($data['harga_perolehan'] ?? null, $data['nilai_residu'] ?? '0');
            $this->assertLife($umur);
            $tanggal = $this->date($data['tanggal_perolehan'] ?? null);
            $this->assertAfterBooksStart($tanggal);

            $asset = FixedAsset::create([
                'kode' => $kode, 'nama' => trim((string) $data['nama']), 'category_id' => $cat->getKey(), 'tanggal_perolehan' => $tanggal,
                'harga_perolehan' => $harga, 'nilai_residu' => $residu, 'umur_bulan' => $umur, 'metode' => 'garis_lurus',
                'tanggal_mulai_susut' => $this->startOfDepreciation($tanggal), 'status' => 'aktif', 'keterangan' => $data['keterangan'] ?? null,
                'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->audit->log('fixed_asset', (int) $asset->getKey(), 'create', null, $asset->only(['kode', 'nama', 'harga_perolehan', 'nilai_residu', 'umur_bulan', 'tanggal_mulai_susut']));

            return $asset;
        });
    }

    public function update(int $id, array $data, User $user): FixedAsset
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $a = FixedAsset::query()->lockForUpdate()->findOrFail($id);
            if ($a->status === 'dilepas') {
                throw new FixedAssetException('Aset sudah dilepas dan tidak bisa diubah. Void disposisinya bila salah.', 'asset_disposed', 409);
            }
            $before = $a->only(['nama', 'keterangan', ...self::FIELD_KEUANGAN, 'tanggal_mulai_susut']);

            $keuangan = array_intersect_key($data, array_flip(self::FIELD_KEUANGAN));
            if ($keuangan !== []) {
                if ($this->hasSchedules($a)) {
                    throw new FixedAssetException('Aset sudah punya jadwal penyusutan. Harga, residu, umur, tanggal, dan kategori tidak bisa diubah di sini (gunakan Perubahan Aset, belum tersedia).', 'asset_locked', 409);
                }
                $cat = $this->usableCategory((int) ($data['category_id'] ?? $a->category_id));
                [$harga, $residu] = $this->amounts($data['harga_perolehan'] ?? $a->harga_perolehan, $data['nilai_residu'] ?? $a->nilai_residu);
                $umur = (int) ($data['umur_bulan'] ?? $a->umur_bulan);
                $this->assertLife($umur);
                $tanggal = $this->date($data['tanggal_perolehan'] ?? $a->tanggal_perolehan->toDateString());
                $this->assertAfterBooksStart($tanggal);

                $a->forceFill([
                    'category_id' => $cat->getKey(), 'harga_perolehan' => $harga, 'nilai_residu' => $residu, 'umur_bulan' => $umur,
                    'tanggal_perolehan' => $tanggal, 'tanggal_mulai_susut' => $this->startOfDepreciation($tanggal),
                ]);
            }
            foreach (['nama', 'keterangan'] as $k) {
                if (array_key_exists($k, $data)) {
                    $a->{$k} = $data[$k] === null ? null : trim((string) $data[$k]);
                }
            }
            $a->updated_by = $user->getKey();
            $a->save();
            $this->audit->log('fixed_asset', $id, 'update', $before, $a->only(array_keys($before)));

            return $a;
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id) {
            $a = FixedAsset::query()->lockForUpdate()->findOrFail($id);
            if ($this->hasSchedules($a)) {
                throw new FixedAssetException('Aset sudah punya jadwal penyusutan dan tidak bisa dihapus.', 'asset_locked', 409);
            }
            if (DB::table('asset_disposals')->where('company_id', $a->company_id)->where('asset_id', $a->getKey())->exists()) {
                throw new FixedAssetException('Aset sudah punya disposisi dan tidak bisa dihapus.', 'asset_locked', 409);
            }
            $this->audit->log('fixed_asset', $id, 'delete', $a->only(['kode', 'nama']), null);
            $a->delete();
        });
    }

    /** Tanggal 1 bulan pertama penyusutan: bulan perolehan, atau bulan berikutnya, sesuai Preferensi. */
    public function startOfDepreciation(string $tanggalPerolehan): string
    {
        $aturan = DB::table('company_settings')->where('company_id', $this->current->id())->value('aturan_awal_penyusutan') ?? 'acquisition_month';
        $first = CarbonImmutable::parse($tanggalPerolehan)->startOfMonth();

        return ($aturan === 'next_month' ? $first->addMonth() : $first)->toDateString();
    }

    private function hasSchedules(FixedAsset $a): bool
    {
        return DB::table('depreciation_schedules')->where('company_id', $a->company_id)->where('asset_id', $a->getKey())->exists();
    }

    private function usableCategory(int $id): AssetCategory
    {
        $cat = AssetCategory::query()->find($id) ?? throw new FixedAssetException('Kategori aset tidak ditemukan.', 'category_not_found', 404);
        if (! $cat->is_aktif) {
            throw new FixedAssetException("Kategori '{$cat->kode}' nonaktif.", 'category_inactive');
        }

        return $cat;
    }

    /** @return array{0:string,1:string} [harga, residu] sebagai string DECIMAL(19,4); harga > residu >= 0 */
    private function amounts(mixed $harga, mixed $residu): array
    {
        foreach ([['harga perolehan', $harga], ['nilai residu', $residu]] as [$label, $v]) {
            if (! is_string($v) && ! is_int($v)) {
                $v = is_numeric($v) ? (string) $v : null;
            }
            if ($v === null || ! preg_match('/^\d{1,15}(\.\d{1,4})?$/', (string) $v)) {
                throw new FixedAssetException("Nilai {$label} tidak valid (angka tidak negatif, maks 4 desimal).", 'validation');
            }
        }
        $h = bcadd((string) $harga, '0', 4);
        $r = bcadd((string) $residu, '0', 4);
        if (Money::cmp($h, '0') <= 0) {
            throw new FixedAssetException('Harga perolehan harus lebih dari nol.', 'validation');
        }
        if (Money::cmp($r, $h) >= 0) {
            throw new FixedAssetException('Nilai residu harus lebih kecil dari harga perolehan.', 'validation');
        }

        return [$h, $r];
    }

    private function assertLife(int $umur): void
    {
        if ($umur < 1 || $umur > 600) {
            throw new FixedAssetException('Umur manfaat harus 1 sampai 600 bulan.', 'validation');
        }
    }

    private function date(mixed $v): string
    {
        try {
            return CarbonImmutable::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            throw new FixedAssetException('Tanggal perolehan tidak valid.', 'validation');
        }
    }

    /** Aset lama sebelum tanggal mulai pembukuan butuh akumulasi awal; belum didukung di 7D. */
    private function assertAfterBooksStart(string $tanggal): void
    {
        $mulai = DB::table('companies')->where('id', $this->current->id())->value('tanggal_mulai_pembukuan');
        if ($mulai !== null && $tanggal < (string) $mulai) {
            throw new FixedAssetException("Tanggal perolehan sebelum tanggal mulai pembukuan ({$mulai}). Migrasi aset lama dengan akumulasi awal belum didukung.", 'before_books_start');
        }
    }
}
