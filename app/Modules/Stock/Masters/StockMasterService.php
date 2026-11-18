<?php

namespace App\Modules\Stock\Masters;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Models\Brand;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemCategory;
use App\Modules\Stock\Models\ItemUnitConversion;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CRUD master stok: satuan, merek, kategori barang, gudang, barang (+ konversi satuan).
 * Master yang sudah dipakai tidak dihapus fisik (FK RESTRICT), cukup dinonaktifkan (Tahap 5 bagian 0).
 */
class StockMasterService
{
    /** resource => [model, label, fitur RBAC, jenis entitas audit] */
    public const RESOURCES = [
        'units' => [Unit::class, 'Satuan', 'satuan_barang', 'unit'],
        'brands' => [Brand::class, 'Merek', 'merek_barang', 'brand'],
        'item-categories' => [ItemCategory::class, 'Kategori barang', 'barang_jasa', 'item_category'],
        'warehouses' => [Warehouse::class, 'Gudang', 'gudang', 'warehouse'],
        'items' => [Item::class, 'Barang', 'barang_jasa', 'item'],
    ];

    public function __construct(private CurrentCompany $current, private AuditLogger $audit)
    {
    }

    public function rules(string $res): array
    {
        $dec = ['nullable', 'regex:/^\d{1,15}(\.\d{1,4})?$/'];
        $base = [
            'kode' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'nama' => ['required', 'string', 'max:200'],
            'is_aktif' => ['sometimes', 'boolean'],
        ];

        return match ($res) {
            'warehouses' => $base + ['alamat' => ['nullable', 'string', 'max:500'], 'penanggung_jawab_id' => ['nullable', 'integer'], 'is_default' => ['sometimes', 'boolean']],
            'item-categories' => $base + ['parent_id' => ['nullable', 'integer'], 'akun_persediaan_id' => ['nullable', 'integer'], 'akun_penjualan_id' => ['nullable', 'integer'], 'akun_hpp_id' => ['nullable', 'integer']],
            'items' => $base + [
                'tipe' => ['required', 'in:barang,jasa'], 'category_id' => ['nullable', 'integer'], 'brand_id' => ['nullable', 'integer'],
                'unit_id' => ['required', 'integer'], 'harga_jual' => $dec, 'harga_beli_standar' => $dec, 'barcode' => ['nullable', 'string', 'max:60'],
                'tax_code_jual_id' => ['nullable', 'integer'], 'tax_code_beli_id' => ['nullable', 'integer'],
                'akun_persediaan_id' => ['nullable', 'integer'], 'akun_penjualan_id' => ['nullable', 'integer'], 'akun_hpp_id' => ['nullable', 'integer'],
                'conversions' => ['sometimes', 'array', 'max:20'],
                'conversions.*.unit_id' => ['required', 'integer'],
                'conversions.*.rasio_ke_dasar' => ['required', 'regex:/^\d{1,11}(\.\d{1,6})?$/'],
            ],
            default => $base,
        };
    }

    public function list(string $res, array $f): array
    {
        $q = $this->model($res)::query()->orderBy('kode');
        if (! empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $q->where(fn ($w) => $w->where('kode', 'like', $like)->orWhere('nama', 'like', $like));
        }
        if (isset($f['is_aktif'])) {
            $q->where('is_aktif', (bool) $f['is_aktif']);
        }
        if ($res === 'items' && ! empty($f['tipe'])) {
            $q->where('tipe', $f['tipe']);
        }
        if (! empty($f['after_id'])) {
            $q->where('id', '>', (int) $f['after_id']);
        }
        $limit = (int) ($f['limit'] ?? 50);
        $rows = $q->orderBy('id')->limit($limit + 1)->get();

        return ['rows' => $rows->take($limit), 'next_after_id' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null];
    }

    public function find(string $res, int $id): Model
    {
        $m = $this->model($res)::query()->findOrFail($id);

        return $res === 'items' ? $m->load('conversions') : $m;
    }

    public function create(string $res, array $data, User $user): Model
    {
        return DB::transaction(function () use ($res, $data, $user) {
            $attrs = $this->prepare($res, $data, null);
            $conv = $attrs['__conv'] ?? null;
            unset($attrs['__conv']);

            $m = $this->model($res)::create($attrs + ['created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            if ($res === 'warehouses' && ! empty($attrs['is_default'])) {
                $this->model($res)::query()->where('id', '!=', $m->getKey())->update(['is_default' => false]);
            }
            if ($res === 'items') {
                $this->writeConversions($m, $conv ?? []);
            }
            $this->audit->log($this->entity($res), (int) $m->getKey(), 'create', null, $attrs, null, null, (int) $user->getKey());

            return $res === 'items' ? $m->load('conversions') : $m;
        });
    }

    public function update(string $res, int $id, array $data, User $user): Model
    {
        return DB::transaction(function () use ($res, $id, $data, $user) {
            $m = $this->model($res)::query()->lockForUpdate()->findOrFail($id);
            $before = $m->only(array_keys($this->rules($res)));
            $attrs = $this->prepare($res, $data, $m);
            $conv = $attrs['__conv'] ?? null;
            unset($attrs['__conv']);

            $m->forceFill($attrs + ['updated_by' => $user->getKey()])->save();
            if ($res === 'warehouses' && ! empty($attrs['is_default'])) {
                $this->model($res)::query()->where('id', '!=', $m->getKey())->update(['is_default' => false]);
            }
            if ($res === 'items' && $conv !== null) {
                $this->writeConversions($m, $conv);
            }
            $this->audit->log($this->entity($res), (int) $m->getKey(), 'update', $before, $attrs, null, null, (int) $user->getKey());

            return $res === 'items' ? $m->load('conversions') : $m;
        });
    }

    public function delete(string $res, int $id, User $user): void
    {
        DB::transaction(function () use ($res, $id, $user) {
            $m = $this->model($res)::query()->lockForUpdate()->findOrFail($id);
            try {
                if ($res === 'items') {
                    ItemUnitConversion::query()->where('item_id', $m->getKey())->delete();
                }
                $m->delete();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') === '23000') {
                    throw StockException::inUse(self::RESOURCES[$res][1]);
                }
                throw $e;
            }
            $this->audit->log($this->entity($res), $id, 'delete', $m->getAttributes(), null, null, null, (int) $user->getKey());
        });
    }

    // ----------------------------------------------------------------------------------------

    /** @return array atribut siap simpan (+ '__conv' bila ada konversi) */
    private function prepare(string $res, array $d, ?Model $existing): array
    {
        $label = self::RESOURCES[$res][1];
        $kode = trim($d['kode']);
        $dupe = $this->model($res)::query()->where('kode', $kode)->when($existing, fn ($q) => $q->where('id', '!=', $existing->getKey()))->exists();
        if ($dupe) {
            throw StockException::invalid("{$label} dengan kode {$kode} sudah ada.", 'duplicate_code');
        }

        $a = ['kode' => $kode, 'nama' => trim($d['nama']), 'is_aktif' => (bool) ($d['is_aktif'] ?? $existing?->is_aktif ?? true)];

        if ($res === 'warehouses') {
            $pic = $d['penanggung_jawab_id'] ?? null;
            if ($pic !== null && ! DB::table('contacts')->where('company_id', $this->current->id())->where('id', $pic)->exists()) {
                throw StockException::invalid('Penanggung jawab (kontak) tidak ditemukan.', 'contact_invalid');
            }
            $a += ['alamat' => $d['alamat'] ?? null, 'penanggung_jawab_id' => $pic, 'is_default' => (bool) ($d['is_default'] ?? false)];
        }

        if ($res === 'item-categories') {
            $parent = $d['parent_id'] ?? null;
            if ($parent !== null) {
                $this->assertParent((int) $parent, $existing?->getKey());
            }
            $a += ['parent_id' => $parent] + $this->accounts($d);
        }

        if ($res === 'items') {
            $a += $this->itemAttrs($d, $existing);
        }

        return $a;
    }

    private function itemAttrs(array $d, ?Model $existing): array
    {
        $this->mustExist(Unit::class, $d['unit_id'], 'Satuan dasar');
        foreach (['category_id' => ItemCategory::class, 'brand_id' => Brand::class] as $col => $cls) {
            if (($d[$col] ?? null) !== null) {
                $this->mustExist($cls, $d[$col], $col === 'category_id' ? 'Kategori' : 'Merek');
            }
        }
        foreach (['tax_code_jual_id', 'tax_code_beli_id'] as $col) {
            if (($d[$col] ?? null) !== null) {
                $this->mustExist(TaxCode::class, $d[$col], 'Kode pajak');
            }
        }

        if ($existing && ((string) $existing->tipe !== $d['tipe'] || (int) $existing->unit_id !== (int) $d['unit_id'])) {
            $used = DB::table('stock_ledger')->where('company_id', $this->current->id())->where('item_id', $existing->getKey())->exists();
            if ($used) {
                throw StockException::invalid('Tipe dan satuan dasar barang tidak bisa diubah setelah ada transaksi stok.', 'item_locked');
            }
        }

        $a = [
            'tipe' => $d['tipe'], 'category_id' => $d['category_id'] ?? null, 'brand_id' => $d['brand_id'] ?? null, 'unit_id' => (int) $d['unit_id'],
            'harga_jual' => $d['harga_jual'] ?? '0', 'harga_beli_standar' => $d['harga_beli_standar'] ?? '0', 'barcode' => $d['barcode'] ?? null,
            'tax_code_jual_id' => $d['tax_code_jual_id'] ?? null, 'tax_code_beli_id' => $d['tax_code_beli_id'] ?? null,
        ] + $this->accounts($d);

        if (isset($d['conversions'])) {
            $seen = [];
            foreach ($d['conversions'] as $c) {
                $uid = (int) $c['unit_id'];
                $this->mustExist(Unit::class, $uid, 'Satuan konversi');
                if ($uid === (int) $d['unit_id'] || isset($seen[$uid]) || bccomp((string) $c['rasio_ke_dasar'], '0', 6) <= 0) {
                    throw StockException::invalid('Konversi satuan tidak valid: satuan harus berbeda dari satuan dasar, tidak ganda, dan rasio lebih dari 0.', 'conversion_invalid');
                }
                $seen[$uid] = true;
            }
            $a['__conv'] = array_values($d['conversions']);
        }

        return $a;
    }

    /** akun override (barang/kategori): harus akun perusahaan ini yang bisa dipakai transaksi */
    private function accounts(array $d): array
    {
        $out = [];
        foreach (['akun_persediaan_id', 'akun_penjualan_id', 'akun_hpp_id'] as $col) {
            $id = $d[$col] ?? null;
            if ($id !== null) {
                $acc = Account::query()->find((int) $id);
                if (! $acc || ! $acc->isPostable()) {
                    throw StockException::invalid("Akun untuk {$col} tidak ditemukan, induk, atau nonaktif.", 'account_invalid');
                }
            }
            $out[$col] = $id;
        }

        return $out;
    }

    private function assertParent(int $parentId, ?int $selfId): void
    {
        $this->mustExist(ItemCategory::class, $parentId, 'Kategori induk');
        for ($cur = $parentId, $depth = 0; $cur !== null && $depth < 20; $depth++) {
            if ($selfId !== null && $cur === $selfId) {
                throw StockException::invalid('Kategori tidak boleh menjadi induk dirinya sendiri.', 'category_cycle');
            }
            $cur = ItemCategory::query()->where('id', $cur)->value('parent_id');
            $cur = $cur === null ? null : (int) $cur;
        }
    }

    private function writeConversions(Model $item, array $conv): void
    {
        ItemUnitConversion::query()->where('item_id', $item->getKey())->delete();
        foreach ($conv as $c) {
            ItemUnitConversion::create(['item_id' => $item->getKey(), 'unit_id' => (int) $c['unit_id'], 'rasio_ke_dasar' => $c['rasio_ke_dasar']]);
        }
    }

    private function mustExist(string $class, mixed $id, string $label): void
    {
        if (! $class::query()->whereKey((int) $id)->exists()) {
            throw StockException::invalid("{$label} tidak ditemukan.", 'ref_not_found');
        }
    }

    /** @return class-string<Model> */
    private function model(string $res): string
    {
        return self::RESOURCES[$res][0] ?? abort(404);
    }

    private function entity(string $res): string
    {
        return self::RESOURCES[$res][3];
    }
}
