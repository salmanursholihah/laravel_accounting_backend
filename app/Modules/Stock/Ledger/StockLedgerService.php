<?php

namespace App\Modules\Stock\Ledger;

use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Posting\StockMovementApplier;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\StockLedgerEntry;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * ALG-05: pergerakan stok dan HPP rata-rata bergerak. SATU-SATUNYA penulis stock_ledger dan stock_balances.
 * Dipanggil orkestrator posting (di dalam transaksi posting) lewat port StockMovementApplier.
 *
 *  masuk : HPP baru = (nilai_saldo + nilai_masuk) / (qty_saldo + qty_masuk)
 *  keluar: nilai = qty x HPP rata-rata saat itu (HPP tidak berubah); bila menghabiskan stok, nilai = seluruh nilai_saldo
 *          (tidak ada sisa pembulatan). harga_satuan eksplisit dipakai untuk retur (HPP asli dokumen sumber).
 *  pembalik (void): gerakan berlawanan dengan NILAI ASLI baris yang dibalik.
 *
 * Kunci baris saldo berurutan (barang, gudang) agar tidak deadlock. Transaksi mundur (tanggal lebih awal dari gerakan
 * terakhir barang/gudang itu) ditolak bila menyentuh periode Closed; selain itu saldo ditandai perlu_hitung_ulang
 * (job hitung ulang penuh: Step 7).
 */
class StockLedgerService implements StockMovementApplier
{
    public function __construct(private CurrentCompany $current)
    {
    }

    /**
     * @param list<array{item_id:int, warehouse_id:int, arah:string, qty:string, harga_satuan?:?string}> $movements
     */
    public function apply(string $sourceType, int $sourceId, string $tanggal, array $movements): StockMovementResult
    {
        // transaksi sendiri (atau ikut transaksi orkestrator): semua gerakan satu dokumen atomik
        return DB::transaction(fn () => $this->applyAll($sourceType, $sourceId, $tanggal, $movements));
    }

    private function applyAll(string $sourceType, int $sourceId, string $tanggal, array $movements): StockMovementResult
    {
        if ($movements === []) {
            return StockMovementResult::none();
        }

        $rows = $this->normalize($movements);
        [$items, $warehouses] = $this->masters($rows);
        $allowNegative = (bool) DB::table('company_settings')->where('company_id', $this->current->id())->value('izinkan_stok_minus');

        $lines = [];
        $masuk = '0';
        $keluar = '0';

        foreach ($rows as $m) {
            $item = $items[$m['item_id']];
            $wh = $warehouses[$m['warehouse_id']];
            $bal = $this->lockBalance($m['item_id'], $m['warehouse_id']);
            $this->guardBackdated($m['item_id'], $m['warehouse_id'], $tanggal, $bal);

            if ($m['arah'] === 'in') {
                [$harga, $nilai, $newQty, $newNilai, $newHpp] = $this->computeIn($bal, $m, $item);
            } else {
                if (! $allowNegative && Money::cmp($bal->qty_saldo, $m['qty']) < 0) {
                    throw StockException::insufficient($item->kode, $wh->kode, Money::round($bal->qty_saldo), $m['qty']);
                }
                [$harga, $nilai, $newQty, $newNilai, $newHpp] = $this->computeOut($bal, $m);
            }

            $this->write($sourceType, $sourceId, $tanggal, $m['item_id'], $m['warehouse_id'], $m['arah'], $m['qty'], $harga, $nilai, $newQty, $newNilai, $newHpp, null, $m['harga_satuan'] !== null);

            $lines[] = ['item_id' => $m['item_id'], 'warehouse_id' => $m['warehouse_id'], 'arah' => $m['arah'], 'qty' => $m['qty'], 'harga_satuan' => $harga, 'nilai' => $nilai];
            if ($m['arah'] === 'in') {
                $masuk = Money::add($masuk, $nilai);
            } else {
                $keluar = Money::add($keluar, $nilai);
            }
        }

        return new StockMovementResult($lines, Money::round($masuk), Money::round($keluar));
    }

    /** Membalik semua gerakan stok dokumen dengan nilai asli. Aman dipanggil untuk dokumen tanpa gerakan. */
    public function reverse(string $sourceType, int $sourceId, string $tanggal): void
    {
        DB::transaction(fn () => $this->reverseAll($sourceType, $sourceId, $tanggal));
    }

    private function reverseAll(string $sourceType, int $sourceId, string $tanggal): void
    {
        $all = StockLedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->get();
        $done = $all->whereNotNull('reversed_of_id')->pluck('reversed_of_id')->flip();
        $todo = $all->whereNull('reversed_of_id')->reject(fn ($r) => $done->has($r->id))
            ->sortBy([['item_id', 'asc'], ['warehouse_id', 'asc'], ['id', 'desc']])->values();

        if ($todo->isEmpty()) {
            return;
        }

        $allowNegative = (bool) DB::table('company_settings')->where('company_id', $this->current->id())->value('izinkan_stok_minus');
        $kode = fn (string $table, int $id) => (string) DB::table($table)->where('company_id', $this->current->id())->where('id', $id)->value('kode');

        foreach ($todo as $r) {
            $bal = $this->lockBalance((int) $r->item_id, (int) $r->warehouse_id);
            $this->guardBackdated((int) $r->item_id, (int) $r->warehouse_id, $tanggal, $bal);

            $wasIn = Money::cmp((string) $r->qty_masuk, '0') > 0;
            $qty = $wasIn ? (string) $r->qty_masuk : (string) $r->qty_keluar;
            $nilai = Money::round((string) $r->nilai);

            if ($wasIn) { // membalik masuk = keluar dengan nilai asli
                if (! $allowNegative && Money::cmp($bal->qty_saldo, $qty) < 0) {
                    throw StockException::insufficient($kode('items', (int) $r->item_id), $kode('warehouses', (int) $r->warehouse_id), Money::round($bal->qty_saldo), $qty);
                }
                $newQty = Money::sub($bal->qty_saldo, $qty);
                $newNilai = Money::sub($bal->nilai_saldo, $nilai);
            } else {      // membalik keluar = masuk dengan nilai asli
                $newQty = Money::add($bal->qty_saldo, $qty);
                $newNilai = Money::add($bal->nilai_saldo, $nilai);
            }

            $this->write($sourceType, $sourceId, $tanggal, (int) $r->item_id, (int) $r->warehouse_id, $wasIn ? 'out' : 'in', $qty, Money::round((string) $r->harga_satuan), $nilai, $newQty, $newNilai, $this->avg($newQty, $newNilai, $bal->hpp_rata_rata), (int) $r->id);
        }
    }

    /** HPP satuan rata-rata tertimbang gerakan KELUAR dokumen sumber (bukan pembalik): dasar retur penjualan. */
    public function costOfOut(string $sourceType, int $sourceId, int $itemId, ?int $warehouseId = null): ?string
    {
        $q = StockLedgerEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('item_id', $itemId)->whereNull('reversed_of_id')->where('qty_keluar', '>', 0);
        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }
        $rows = $q->get();
        $qty = Money::sum($rows->pluck('qty_keluar')->map(fn ($v) => (string) $v)->all());
        if ($rows->isEmpty() || Money::isZero($qty)) {
            return null;
        }

        return Money::round(Money::div(Money::sum($rows->pluck('nilai')->map(fn ($v) => (string) $v)->all()), $qty));
    }

    /** Qty tersedia (tanpa kunci) untuk validator pra-posting. */
    public function available(int $itemId, int $warehouseId): string
    {
        return Money::round((string) (DB::table('stock_balances')->where('company_id', $this->current->id())
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->value('qty_saldo') ?? '0'));
    }

    // ---------------------------------------------------------------------------------------- internal

    private function computeIn(object $bal, array $m, Item $item): array
    {
        $harga = $m['harga_satuan'];
        if ($harga === null) {
            if (Money::cmp($bal->hpp_rata_rata, '0') <= 0 && Money::cmp($bal->qty_saldo, '0') <= 0) {
                throw StockException::priceRequired($item->kode);
            }
            $harga = Money::round($bal->hpp_rata_rata);
        }

        $nilai = Money::round(Money::mul($m['qty'], $harga));
        $newQty = Money::add($bal->qty_saldo, $m['qty']);
        $newNilai = Money::add($bal->nilai_saldo, $nilai);

        return [$harga, $nilai, $newQty, $newNilai, $this->avg($newQty, $newNilai, $bal->hpp_rata_rata)];
    }

    private function computeOut(object $bal, array $m): array
    {
        if ($m['harga_satuan'] !== null) {                      // retur: harga asli dokumen sumber
            $harga = $m['harga_satuan'];
            $nilai = Money::round(Money::mul($m['qty'], $harga));
        } elseif (Money::cmp($m['qty'], $bal->qty_saldo) === 0 && Money::cmp($bal->qty_saldo, '0') > 0) {
            $nilai = Money::round($bal->nilai_saldo);           // menghabiskan stok: tanpa sisa pembulatan
            $harga = Money::round(Money::div($nilai, $m['qty']));
        } else {
            $harga = Money::round($bal->hpp_rata_rata);
            $nilai = Money::round(Money::mul($m['qty'], $harga));
        }

        $newQty = Money::sub($bal->qty_saldo, $m['qty']);
        $newNilai = Money::sub($bal->nilai_saldo, $nilai);

        return [$harga, $nilai, $newQty, $newNilai, $this->avg($newQty, $newNilai, $bal->hpp_rata_rata)];
    }

    /** HPP rata-rata baru; tidak berubah bila stok atau nilai tidak positif (stok minus). */
    private function avg(string $qty, string $nilai, string $old): string
    {
        if (Money::cmp($qty, '0') > 0 && Money::cmp($nilai, '0') > 0) {
            return Money::round(Money::div($nilai, $qty));
        }

        return Money::round($old);
    }

    private function write(string $sourceType, int $sourceId, string $tanggal, int $itemId, int $whId, string $arah, string $qty, string $harga, string $nilai, string $newQty, string $newNilai, string $newHpp, ?int $reversedOf, bool $hargaTetap = false): void
    {
        $cid = $this->current->id();

        DB::table('stock_ledger')->insert([
            'company_id' => $cid, 'tanggal' => $tanggal, 'item_id' => $itemId, 'warehouse_id' => $whId,
            'qty_masuk' => $arah === 'in' ? $qty : '0', 'qty_keluar' => $arah === 'out' ? $qty : '0',
            'harga_satuan' => $harga, 'nilai' => $nilai, 'harga_tetap' => $hargaTetap,
            'qty_saldo' => Money::round($newQty), 'nilai_saldo' => Money::round($newNilai),
            'source_type' => $sourceType, 'source_id' => $sourceId, 'reversed_of_id' => $reversedOf,
            'created_by' => Auth::id(), 'created_at' => now(),
        ]);

        DB::table('stock_balances')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $whId)->update([
            'qty_saldo' => Money::round($newQty), 'nilai_saldo' => Money::round($newNilai),
            'hpp_rata_rata' => $newHpp, 'updated_at' => now(),
        ]);
    }

    /** SELECT ... FOR UPDATE pada baris saldo (dibuat nol bila belum ada). */
    private function lockBalance(int $itemId, int $whId): object
    {
        $cid = $this->current->id();
        DB::table('stock_balances')->insertOrIgnore([
            'company_id' => $cid, 'item_id' => $itemId, 'warehouse_id' => $whId,
            'qty_saldo' => 0, 'nilai_saldo' => 0, 'hpp_rata_rata' => 0, 'stok_minimum' => 0, 'perlu_hitung_ulang' => 0, 'updated_at' => now(),
        ]);

        return DB::table('stock_balances')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $whId)->lockForUpdate()->first();
    }

    /** Transaksi mundur: tolak bila rentang menyentuh periode Closed; selain itu tandai perlu_hitung_ulang. */
    private function guardBackdated(int $itemId, int $whId, string $tanggal, object $bal): void
    {
        $last = DB::table('stock_ledger')->where('company_id', $this->current->id())->where('item_id', $itemId)->where('warehouse_id', $whId)->max('tanggal');
        if ($last === null || $tanggal >= $last) {
            return;
        }

        $closed = AccountingPeriod::query()->where('status', 'closed')
            ->where('tanggal_selesai', '>=', $tanggal)->where('tanggal_mulai', '<=', $last)->exists();
        if ($closed) {
            throw StockException::closedRange($tanggal, (string) $last);
        }

        $from = $bal->hitung_ulang_dari !== null && $bal->hitung_ulang_dari < $tanggal ? $bal->hitung_ulang_dari : $tanggal;
        DB::table('stock_balances')->where('company_id', $this->current->id())->where('item_id', $itemId)->where('warehouse_id', $whId)
            ->update(['perlu_hitung_ulang' => 1, 'hitung_ulang_dari' => $from]);
    }

    /** @return list<array{item_id:int, warehouse_id:int, arah:string, qty:string, harga_satuan:?string}> terurut (barang, gudang, urutan asal) */
    private function normalize(array $movements): array
    {
        $out = [];
        foreach (array_values($movements) as $i => $m) {
            if (! in_array($m['arah'] ?? null, ['in', 'out'], true)) {
                throw StockException::invalid('Arah gerakan stok harus in atau out.', 'bad_direction');
            }
            $qty = (string) ($m['qty'] ?? '0');
            if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $qty) || Money::isZero($qty)) {
                throw StockException::invalid('Qty gerakan stok harus lebih dari 0 (maksimal 4 desimal).', 'bad_qty');
            }
            $harga = $m['harga_satuan'] ?? null;
            if ($harga !== null && ! preg_match('/^\d{1,15}(\.\d{1,4})?$/', (string) $harga)) {
                throw StockException::invalid('Harga satuan gerakan stok tidak valid.', 'bad_price');
            }
            $out[] = ['item_id' => (int) $m['item_id'], 'warehouse_id' => (int) $m['warehouse_id'], 'arah' => $m['arah'], 'qty' => Money::round($qty), 'harga_satuan' => $harga === null ? null : Money::round((string) $harga), 'i' => $i];
        }
        usort($out, fn ($a, $b) => [$a['item_id'], $a['warehouse_id'], $a['i']] <=> [$b['item_id'], $b['warehouse_id'], $b['i']]);

        return $out;
    }

    /** @return array{0: array<int, Item>, 1: array<int, Warehouse>} */
    private function masters(array $rows): array
    {
        $items = Item::query()->whereIn('id', array_unique(array_column($rows, 'item_id')))->get()->keyBy('id');
        $whs = Warehouse::query()->whereIn('id', array_unique(array_column($rows, 'warehouse_id')))->get()->keyBy('id');

        foreach ($rows as $m) {
            $item = $items->get($m['item_id']);
            if (! $item || ! $item->isBarang()) {
                throw StockException::notStockItem($item->kode ?? (string) $m['item_id']);
            }
            if (! $item->is_aktif) {
                throw StockException::inactive('Barang', $item->kode);
            }
            $wh = $whs->get($m['warehouse_id']);
            if (! $wh) {
                throw StockException::invalid('Gudang tidak ditemukan.', 'warehouse_not_found');
            }
            if (! $wh->is_aktif) {
                throw StockException::inactive('Gudang', $wh->kode);
            }
        }

        return [$items->all(), $whs->all()];
    }
}
