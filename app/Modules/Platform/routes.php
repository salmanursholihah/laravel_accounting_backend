<?php

use App\Modules\Platform\Http\Controllers\CompanyController;
use App\Modules\Platform\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function () {
    // Tanpa konteks perusahaan
    Route::get('me/companies', [CompanyController::class, 'mine']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::post('session/company', [CompanyController::class, 'select']);

    // Wajib ada perusahaan aktif (header X-Company-Id atau session)
    Route::middleware('company')->group(function () {
        Route::get('company', [CompanyController::class, 'show']);
        Route::get('company/permissions', [CompanyController::class, 'permissions']);

        Route::get('settings', [SettingsController::class, 'show'])->middleware('feature:preferensi,view');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('feature:khusus,ubah_preferensi');
    });
});
