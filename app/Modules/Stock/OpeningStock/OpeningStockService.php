<?php

namespace App\Modules\Stock\OpeningStock;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\OpeningStockPort;
use App\Modules\Accounting\Posting\StockMovementApplier;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemCategory;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saldo Awal Stok (UC-16, bagian stok). Draf = baris opening_stock_lines tanpa journal_id.
 * Posting dan Void-nya ikut Saldo Awal (POST/void /opening-balances/...) lewat OpeningStockPort:
 *  - nilai = qty x harga per baris, dijumlah per akun persediaan (urutan: barang -> kategori -> akun sistem 'persediaan'),
 *    menjadi debit di jurnal saldo awal;
 *  - gerakan stok masuk dicatat ALG-05 (source_type 'opening_balance', source_id = id jurnal saldo awal), sehingga
 *    nilai stock_ledger selalu sama dengan nilai jurnal.
 */
class OpeningStockService implements OpeningStockPort
{
    public const SOURCE = 'opening_balance';

    public function __construct(
        private CurrentCompany $current,
        private StockMovementApplier $stock,
        private SystemAccountService $system,
        private AuditLogger $audit,
    ) {
    }

    public function isPosted(): bool
    {
        return DB::table('opening_stock_lines')->where('company_id', $this->current->id())->whereNotNull('journal_id')->exists();
    }

    public function rows(): Collection
    {
        return DB::table('opening_stock_lines as o')
            ->join('items as i', fn ($j) => $j->on('i.id', '=', 'o.item_id')->on('i.company_id', '=', 'o.company_id'))
            ->join('warehouses as w', fn ($j) => $j->on('w.id', '=', 'o.warehouse_id')->on('w.company_id', '=', 'o.company_id'))
            ->where('o.company_id', $this->current->id())
            ->orderBy('i.kode')->orderBy('w.kode')
            ->get(['o.id', 'o.item_id', 'i.kode as kode_barang', 'i.nama as nama_barang', 'o.warehouse_id', 'w.kode as kode_gudang', 'o.qty', 'o.harga_satuan', 'o.journal_id'])
            ->map(function ($r) {
                $r->nilai = Money::round(Money::mul((string) $r->qty, (string) $r->harga_satuan));

                return $r;
            });
    }

    /** Mengganti seluruh baris draf. @param list<array{item_id:int, warehouse_id:int, qty:string|int|float, harga_satuan:string|int|float}> $rows */
    public function save(array $rows, User $user): int
    {
        if ($this->isPosted()) {
            throw StockException::invalid('Saldo awal stok sudah diposting. Void saldo awal terlebih dahulu bila perlu mengubahnya.', 'opening_stock_posted');
        }

        $clean = [];
        $seen = [];
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $qty = $this->num($r['qty'] ?? null, $n, 'qty', 14);
            $harga = $this->num($r['harga_satuan'] ?? null, $n, 'harga_satuan', 15);
            if (Money::cmp($qty, '0') <= 0) {
                throw StockException::invalid("Baris {$n}: qty harus lebih dari 0.", 'bad_qty');
            }
            $key = ((int) $r['item_id']) . '|' . ((int) $r['warehouse_id']);
            if (isset($seen[$key])) {
                throw StockException::invalid("Baris {$n}: kombinasi barang dan gudang sudah ada di baris lain.", 'duplicate_row');
            }
            $seen[$key] = true;
            $clean[] = ['item_id' => (int) $r['item_id'], 'warehouse_id' => (int) $r['warehouse_id'], 'qty' => $qty, 'harga_satuan' => $harga];
        }

        $items = Item::query()->whereIn('id', array_column($clean, 'item_id'))->get()->keyBy('id');
        $whs = Warehouse::query()->whereIn('id', array_column($clean, 'warehouse_id'))->get()->keyBy('id');
        foreach ($clean as $i => $c) {
            $n = $i + 1;
            $item = $items->get($c['item_id']);
            if (! $item || ! $item->isBarang()) {
                throw StockException::invalid("Baris {$n}: barang tidak ditemukan atau bertipe jasa.", 'not_stock_item');
            }
            if (! $item->is_aktif) {
                throw StockException::inactive('Barang', $item->kode);
            }
            $wh = $whs->get($c['warehouse_id']) ?? throw StockException::invalid("Baris {$n}: gudang tidak ditemukan.", 'warehouse_not_found');
            if (! $wh->is_aktif) {
                throw StockException::inactive('Gudang', $wh->kode);
            }
        }

        return DB::transaction(function () use ($clean, $user) {
            $cid = $this->current->id();
            DB::table('opening_stock_lines')->where('company_id', $cid)->whereNull('journal_id')->delete();
            if ($clean !== []) {
                DB::table('opening_stock_lines')->insert(array_map(fn ($c) => $c + ['company_id' => $cid, 'created_by' => $user->getKey(), 'created_at' => now()], $clean));
            }
            $this->audit->log('opening_stock', null, 'update', null, ['baris' => count($clean)], null, $cid, (int) $user->getKey());

            return count($clean);
        });
    }

    // ----------------------------------------------------------------------------- OpeningStockPort

    public function draftAccountAmounts(): array
    {
        $rows = $this->draft();
        if ($rows->isEmpty()) {
            return [];
        }

        $items = Item::query()->whereIn('id', $rows->pluck('item_id')->unique()->all())->get()->keyBy('id');
        $cats = ItemCategory::query()->whereIn('id', $items->pluck('category_id')->filter()->unique()->all())->get()->keyBy('id');
        $default = null;

        $sum = [];
        foreach ($rows as $r) {
            $item = $items[$r->item_id];
            $akun = $item->akun_persediaan_id
                ?? ($item->category_id ? ($cats[$item->category_id]->akun_persediaan_id ?? null) : null)
                ?? ($default ??= (int) $this->system->resolve('persediaan')->getKey());
            $akun = (int) $akun;
            $sum[$akun] = Money::add($sum[$akun] ?? '0', $this->nilai($r));
        }
        ksort($sum);

        return array_map(fn ($v) => Money::round($v), $sum);
    }

    public function commit(int $journalId, string $tanggal, int $userId): void
    {
        $rows = $this->draft(true);
        if ($rows->isEmpty()) {
            return;
        }

        $expected = Money::round(Money::sum($rows->map(fn ($r) => $this->nilai($r))->all()));
        $movements = $rows->map(fn ($r) => [
            'item_id' => (int) $r->item_id, 'warehouse_id' => (int) $r->warehouse_id, 'arah' => 'in',
            'qty' => (string) $r->qty, 'harga_satuan' => (string) $r->harga_satuan,
        ])->all();

        $res = $this->stock->apply(self::SOURCE, $journalId, $tanggal, $movements);
        if (Money::cmp($res->nilaiMasuk, $expected) !== 0) { // tidak terjadi; pengaman jurnal = stock_ledger
            throw StockException::invalid("Nilai stok ({$res->nilaiMasuk}) tidak sama dengan nilai jurnal ({$expected}).", 'opening_stock_mismatch');
        }

        DB::table('opening_stock_lines')->whereIn('id', $rows->pluck('id')->all())->update(['journal_id' => $journalId]);
    }

    public function revert(int $journalId, string $tanggal): void
    {
        $cid = $this->current->id();
        if (! DB::table('opening_stock_lines')->where('company_id', $cid)->where('journal_id', $journalId)->exists()) {
            return;
        }

        $this->stock->reverse(self::SOURCE, $journalId, $tanggal); // gagal bila stok sudah terpakai: seluruh void batal
        DB::table('opening_stock_lines')->where('company_id', $cid)->where('journal_id', $journalId)->update(['journal_id' => null]);
    }

    // ----------------------------------------------------------------------------- internal

    private function draft(bool $lock = false): Collection
    {
        $q = DB::table('opening_stock_lines')->where('company_id', $this->current->id())->whereNull('journal_id')->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    private function nilai(object $r): string
    {
        return Money::round(Money::mul((string) $r->qty, (string) $r->harga_satuan));
    }

    private function num(mixed $raw, int $row, string $field, int $intDigits): string
    {
        $s = is_float($raw) ? rtrim(rtrim(number_format($raw, 6, '.', ''), '0'), '.') : (string) ($raw ?? '');
        if (! preg_match('/^\d{1,' . $intDigits . '}(\.\d{1,4})?$/', $s)) {
            throw StockException::invalid("Baris {$row}: {$field} harus angka tidak negatif dengan paling banyak 4 desimal.", 'invalid_amount');
        }

        return Money::round($s);
    }
}
