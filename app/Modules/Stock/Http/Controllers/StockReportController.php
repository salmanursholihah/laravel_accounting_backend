<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\Reports\StockReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockReportController extends Controller
{
    public function __construct(private StockReportService $reports, private PermissionChecker $permissions)
    {
    }

    /** HPP dan nilai persediaan hanya untuk pemegang hak khusus lihat_hpp (Tahap 2 bagian 4). */
    private function withCost(Request $request): bool
    {
        return $this->permissions->can($request->user(), 'khusus', 'lihat_hpp');
    }

    public function perWarehouse(Request $request): JsonResponse
    {
        $f = $request->validate([
            'warehouse_id' => ['sometimes', 'integer'], 'item_id' => ['sometimes', 'integer'], 'hanya_ada_stok' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:50'], 'after_item_id' => ['sometimes', 'integer'], 'after_warehouse_id' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->reports->perWarehouse($f, $this->withCost($request)));
    }

    public function stockCard(Request $request): JsonResponse
    {
        $f = $request->validate([
            'item_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'],
            'dari' => ['required', 'date'], 'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'after_id' => ['sometimes', 'integer'], 'limit' => ['sometimes', 'integer', 'between:1,200'],
        ]);
        // barang/gudang harus milik perusahaan aktif (CompanyScope): 404 bila tidak
        Item::query()->findOrFail((int) $f['item_id']);
        Warehouse::query()->findOrFail((int) $f['warehouse_id']);

        return response()->json($this->reports->stockCard(
            (int) $f['item_id'], (int) $f['warehouse_id'], $request->date('dari')->toDateString(), $request->date('sampai')->toDateString(),
            $this->withCost($request), isset($f['after_id']) ? (int) $f['after_id'] : null, (int) ($f['limit'] ?? 100),
        ));
    }
}
