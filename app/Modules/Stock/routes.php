<?php

use App\Modules\Stock\Http\Controllers\OpeningStockController;
use App\Modules\Stock\Http\Controllers\StockAdjustmentController;
use App\Modules\Stock\Http\Controllers\StockMasterController;
use App\Modules\Stock\Http\Controllers\StockReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    // Master stok: uri => fitur RBAC. Jenis master dikirim ke controller lewat default 'res'.
    $masters = [
        'units' => 'satuan_barang',
        'brands' => 'merek_barang',
        'item-categories' => 'barang_jasa',
        'warehouses' => 'gudang',
        'items' => 'barang_jasa',
    ];
    foreach ($masters as $uri => $fitur) {
        Route::get($uri, [StockMasterController::class, 'index'])->defaults('res', $uri)->middleware("feature:{$fitur},view");
        Route::post($uri, [StockMasterController::class, 'store'])->defaults('res', $uri)->middleware("feature:{$fitur},create");
        Route::get("{$uri}/{id}", [StockMasterController::class, 'show'])->defaults('res', $uri)->whereNumber('id')->middleware("feature:{$fitur},view");
        Route::put("{$uri}/{id}", [StockMasterController::class, 'update'])->defaults('res', $uri)->whereNumber('id')->middleware("feature:{$fitur},update");
        Route::delete("{$uri}/{id}", [StockMasterController::class, 'destroy'])->defaults('res', $uri)->whereNumber('id')->middleware("feature:{$fitur},delete");
    }

    // UC-50 Penyesuaian Persediaan (Draft). Posting/Void: /documents/stock_adjustment/{id}/post|void
    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('feature:penyesuaian_persediaan,view');
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('feature:penyesuaian_persediaan,create');
    Route::get('stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->whereNumber('id')->middleware('feature:penyesuaian_persediaan,view');
    Route::put('stock-adjustments/{id}', [StockAdjustmentController::class, 'update'])->whereNumber('id')->middleware('feature:penyesuaian_persediaan,update');
    Route::delete('stock-adjustments/{id}', [StockAdjustmentController::class, 'destroy'])->whereNumber('id')->middleware('feature:penyesuaian_persediaan,delete');

    // Saldo awal stok (draf). Posting/Void ikut Saldo Awal: POST /opening-balances/post dan /opening-balances/void
    Route::get('opening-stock', [OpeningStockController::class, 'index'])->middleware('feature:jurnal_umum,view');
    Route::put('opening-stock', [OpeningStockController::class, 'update'])->middleware('feature:jurnal_umum,create');

    // UC-53 Barang per Gudang dan Kartu Stok
    Route::get('reports/stock-by-warehouse', [StockReportController::class, 'perWarehouse'])->middleware('feature:barang_per_gudang,view');
    Route::get('reports/stock-card', [StockReportController::class, 'stockCard'])->middleware('feature:barang_per_gudang,view');
});
