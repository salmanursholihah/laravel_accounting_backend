<?php

use App\Modules\FixedAssets\Http\Controllers\AssetCategoryController;
use App\Modules\FixedAssets\Http\Controllers\AssetDisposalController;
use App\Modules\FixedAssets\Http\Controllers\DepreciationController;
use App\Modules\FixedAssets\Http\Controllers\FixedAssetController;
use Illuminate\Support\Facades\Route;

// Aset Tetap (Tahap 7D). Menjalankan penyusutan = hak khusus tutup_periode (Owner, Akuntan), seperti Proses Akhir Bulan.
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    Route::get('asset-categories', [AssetCategoryController::class, 'index'])->middleware('feature:kategori_aset,view');
    Route::post('asset-categories', [AssetCategoryController::class, 'store'])->middleware('feature:kategori_aset,create');
    Route::get('asset-categories/{id}', [AssetCategoryController::class, 'show'])->whereNumber('id')->middleware('feature:kategori_aset,view');
    Route::put('asset-categories/{id}', [AssetCategoryController::class, 'update'])->whereNumber('id')->middleware('feature:kategori_aset,update');
    Route::delete('asset-categories/{id}', [AssetCategoryController::class, 'destroy'])->whereNumber('id')->middleware('feature:kategori_aset,delete');

    Route::get('fixed-assets', [FixedAssetController::class, 'index'])->middleware('feature:aset_tetap,view');
    Route::post('fixed-assets', [FixedAssetController::class, 'store'])->middleware('feature:aset_tetap,create');
    Route::get('fixed-assets/{id}', [FixedAssetController::class, 'show'])->whereNumber('id')->middleware('feature:aset_tetap,view');
    Route::put('fixed-assets/{id}', [FixedAssetController::class, 'update'])->whereNumber('id')->middleware('feature:aset_tetap,update');
    Route::delete('fixed-assets/{id}', [FixedAssetController::class, 'destroy'])->whereNumber('id')->middleware('feature:aset_tetap,delete');
    Route::get('fixed-assets/{id}/schedule', [FixedAssetController::class, 'schedule'])->whereNumber('id')->middleware('feature:aset_tetap,view');

    Route::get('periods/{id}/depreciation-preview', [DepreciationController::class, 'preview'])->whereNumber('id')->middleware('feature:aset_tetap,view');
    Route::post('periods/{id}/depreciation', [DepreciationController::class, 'run'])->whereNumber('id')->middleware('feature:khusus,tutup_periode');

    // Disposisi Aset Tetap (7D-2, UC-57). Posting/Void: POST documents/asset_disposal/{id}/post|void (hak post/void per dokumen).
    Route::get('asset-disposals', [AssetDisposalController::class, 'index'])->middleware('feature:disposisi_aset_tetap,view');
    Route::get('asset-disposals/preview', [AssetDisposalController::class, 'preview'])->middleware('feature:disposisi_aset_tetap,view');
    Route::post('asset-disposals', [AssetDisposalController::class, 'store'])->middleware('feature:disposisi_aset_tetap,create');
    Route::get('asset-disposals/{id}', [AssetDisposalController::class, 'show'])->whereNumber('id')->middleware('feature:disposisi_aset_tetap,view');
    Route::put('asset-disposals/{id}', [AssetDisposalController::class, 'update'])->whereNumber('id')->middleware('feature:disposisi_aset_tetap,update');
    Route::delete('asset-disposals/{id}', [AssetDisposalController::class, 'destroy'])->whereNumber('id')->middleware('feature:disposisi_aset_tetap,delete');
});
