<?php

namespace App\Modules\Stock\Reports;

use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Barang per Gudang (UC-53) dan Kartu Stok (US-24). Semua query memfilter company_id eksplisit; paginasi keyset. */
class StockReportService
{
    public function __construct(private CurrentCompany $current)
    {
    }

    /**
     * @param bool $withCost tampilkan HPP dan nilai (hak khusus lihat_hpp)
     * @return array{rows: list<array>, next: ?array}
     */
    public function perWarehouse(array $f, bool $withCost): array
    {
        $cid = $this->current->id();
        $limit = (int) ($f['limit'] ?? 50);

        $q = DB::table('stock_balances as b')
            ->join('items as i', fn ($j) => $j->on('i.id', '=', 'b.item_id')->on('i.company_id', '=', 'b.company_id'))
            ->join('warehouses as w', fn ($j) => $j->on('w.id', '=', 'b.warehouse_id')->on('w.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $cid)
            ->orderBy('b.item_id')->orderBy('b.warehouse_id')
            ->select('b.item_id', 'b.warehouse_id', 'i.kode as kode_barang', 'i.nama as nama_barang', 'w.kode as kode_gudang', 'w.nama as nama_gudang',
                'b.qty_saldo', 'b.nilai_saldo', 'b.hpp_rata_rata', 'b.stok_minimum', 'b.perlu_hitung_ulang');

        if (! empty($f['warehouse_id'])) {
            $q->where('b.warehouse_id', (int) $f['warehouse_id']);
        }
        if (! empty($f['item_id'])) {
            $q->where('b.item_id', (int) $f['item_id']);
        }
        if (! empty($f['hanya_ada_stok'])) {
            $q->where('b.qty_saldo', '<>', 0);
        }
        if (! empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $q->where(fn ($w) => $w->where('i.kode', 'like', $like)->orWhere('i.nama', 'like', $like));
        }
        if (! empty($f['after_item_id'])) {
            $ai = (int) $f['after_item_id'];
            $aw = (int) ($f['after_warehouse_id'] ?? 0);
            $q->where(fn ($w) => $w->where('b.item_id', '>', $ai)->orWhere(fn ($x) => $x->where('b.item_id', $ai)->where('b.warehouse_id', '>', $aw)));
        }

        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'rows' => $rows->map(function ($r) use ($withCost) {
                $o = [
                    'item_id' => (int) $r->item_id, 'warehouse_id' => (int) $r->warehouse_id,
                    'kode_barang' => $r->kode_barang, 'nama_barang' => $r->nama_barang,
                    'kode_gudang' => $r->kode_gudang, 'nama_gudang' => $r->nama_gudang,
                    'qty_saldo' => Money::round($r->qty_saldo), 'stok_minimum' => Money::round($r->stok_minimum),
                    'perlu_hitung_ulang' => (bool) $r->perlu_hitung_ulang,
                ];

                return $withCost ? $o + ['hpp_rata_rata' => Money::round($r->hpp_rata_rata), 'nilai_saldo' => Money::round($r->nilai_saldo)] : $o;
            })->values()->all(),
            'next' => $more ? ['after_item_id' => (int) $rows->last()->item_id, 'after_warehouse_id' => (int) $rows->last()->warehouse_id] : null,
        ];
    }

    /** @return array{saldo_awal: array, rows: list<array>, next_after_id: ?int, perlu_hitung_ulang: bool} */
    public function stockCard(int $itemId, int $warehouseId, string $dari, string $sampai, bool $withCost, ?int $afterId = null, int $limit = 100): array
    {
        $cid = $this->current->id();
        $base = fn () => DB::table('stock_ledger')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $warehouseId);

        $awal = $base()->where('tanggal', '<', $dari)->orderByDesc('tanggal')->orderByDesc('id')->first(['qty_saldo', 'nilai_saldo']);
        $rows = $base()->whereBetween('tanggal', [$dari, $sampai])
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('tanggal')->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $flag = (bool) DB::table('stock_balances')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->value('perlu_hitung_ulang');

        $saldoAwal = ['qty' => Money::round((string) ($awal->qty_saldo ?? '0'))];
        if ($withCost) {
            $saldoAwal['nilai'] = Money::round((string) ($awal->nilai_saldo ?? '0'));
        }

        return [
            'saldo_awal' => $saldoAwal,
            'rows' => $rows->map(function ($r) use ($withCost) {
                $o = [
                    'id' => (int) $r->id, 'tanggal' => $r->tanggal, 'source_type' => $r->source_type, 'source_id' => (int) $r->source_id,
                    'pembalik' => $r->reversed_of_id !== null, 'qty_masuk' => Money::round($r->qty_masuk), 'qty_keluar' => Money::round($r->qty_keluar),
                    'qty_saldo' => Money::round($r->qty_saldo),
                ];

                return $withCost ? $o + ['harga_satuan' => Money::round($r->harga_satuan), 'nilai' => Money::round($r->nilai), 'nilai_saldo' => Money::round($r->nilai_saldo)] : $o;
            })->values()->all(),
            'next_after_id' => $more ? (int) $rows->last()->id : null,
            'perlu_hitung_ulang' => $flag,
        ];
    }
}
