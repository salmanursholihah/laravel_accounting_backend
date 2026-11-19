<?php

namespace App\Modules\FixedAssets\Categories;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Kategori Aset: memetakan tiga akun (aset, akumulasi penyusutan, beban penyusutan) untuk jurnal penyusutan. */
class AssetCategoryService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function list(?bool $aktif = null): Collection
    {
        return AssetCategory::query()->when($aktif !== null, fn ($q) => $q->where('is_aktif', $aktif))->orderBy('kode')->get();
    }

    public function find(int $id): AssetCategory
    {
        return AssetCategory::query()->findOrFail($id);
    }

    public function create(array $data, User $user): AssetCategory
    {
        return DB::transaction(function () use ($data, $user) {
            $kode = trim((string) $data['kode']);
            if (AssetCategory::query()->where('kode', $kode)->exists()) {
                throw new FixedAssetException("Kode kategori '{$kode}' sudah dipakai.", 'duplicate_code', 409);
            }
            $this->assertAccounts($data);

            $cat = AssetCategory::create([
                'kode' => $kode, 'nama' => trim((string) $data['nama']),
                'akun_aset_id' => (int) $data['akun_aset_id'], 'akun_akumulasi_id' => (int) $data['akun_akumulasi_id'], 'akun_beban_id' => (int) $data['akun_beban_id'],
                'metode_default' => 'garis_lurus', 'umur_default_bulan' => $data['umur_default_bulan'] ?? null, 'is_aktif' => true,
                'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->audit->log('asset_category', (int) $cat->getKey(), 'create', null, $cat->only(['kode', 'nama', 'akun_aset_id', 'akun_akumulasi_id', 'akun_beban_id']));

            return $cat;
        });
    }

    /** Akun hanya boleh diganti selama belum ada aset di kategori ini; nama, umur default, status selalu boleh. */
    public function update(int $id, array $data, User $user): AssetCategory
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $cat = AssetCategory::query()->lockForUpdate()->findOrFail($id);
            $before = $cat->only(['nama', 'akun_aset_id', 'akun_akumulasi_id', 'akun_beban_id', 'umur_default_bulan', 'is_aktif']);

            $akunKeys = ['akun_aset_id', 'akun_akumulasi_id', 'akun_beban_id'];
            $akunBerubah = false;
            foreach ($akunKeys as $k) {
                if (array_key_exists($k, $data) && (int) $data[$k] !== (int) $cat->{$k}) {
                    $akunBerubah = true;
                }
            }
            if ($akunBerubah) {
                if (DB::table('fixed_assets')->where('company_id', $cat->company_id)->where('category_id', $id)->exists()) {
                    throw new FixedAssetException('Akun kategori tidak bisa diganti karena sudah dipakai aset. Buat kategori baru.', 'category_in_use', 409);
                }
                $this->assertAccounts($data + $cat->only($akunKeys));
            }

            $cat->fill(array_intersect_key($data, array_flip(['nama', 'umur_default_bulan', 'is_aktif', ...$akunKeys])));
            $cat->updated_by = $user->getKey();
            $cat->save();
            $this->audit->log('asset_category', $id, 'update', $before, $cat->only(array_keys($before)));

            return $cat;
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id) {
            $cat = AssetCategory::query()->lockForUpdate()->findOrFail($id);
            if (DB::table('fixed_assets')->where('company_id', $cat->company_id)->where('category_id', $id)->exists()) {
                throw new FixedAssetException('Kategori sudah dipakai aset; nonaktifkan saja.', 'category_in_use', 409);
            }
            $this->audit->log('asset_category', $id, 'delete', $cat->only(['kode', 'nama']), null);
            $cat->delete();
        });
    }

    private function assertAccounts(array $d): void
    {
        $rules = ['akun_aset_id' => ['asset', 'aset'], 'akun_akumulasi_id' => ['asset', 'akumulasi penyusutan'], 'akun_beban_id' => ['expense', 'beban penyusutan']];
        foreach ($rules as $key => [$tipe, $label]) {
            $a = Account::query()->find((int) ($d[$key] ?? 0))
                ?? throw new FixedAssetException("Akun {$label} tidak ditemukan di perusahaan ini.", 'account_not_found');
            if ($a->is_header || ! $a->is_aktif) {
                throw new FixedAssetException("Akun {$label} ({$a->kode}) harus aktif dan bukan akun induk.", 'account_not_usable');
            }
            if ($a->tipe !== $tipe) {
                throw new FixedAssetException("Akun {$label} ({$a->kode}) harus bertipe {$tipe}.", 'account_type_mismatch');
            }
        }
        if ((int) $d['akun_aset_id'] === (int) $d['akun_akumulasi_id']) {
            throw new FixedAssetException('Akun aset dan akun akumulasi penyusutan tidak boleh sama.', 'account_type_mismatch');
        }
    }
}
