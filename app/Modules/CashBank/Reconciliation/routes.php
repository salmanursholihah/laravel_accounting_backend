<?php

use App\Modules\CashBank\Http\Controllers\BankReconciliationController;
use App\Modules\CashBank\Http\Controllers\BankStatementController;
use Illuminate\Support\Facades\Route;

// Rekonsiliasi Bank (UC-26 dan UC-27). Hak: fitur rekening_koran dan rekonsiliasi_bank (Akuntan penuh; Owner penuh; Admin lihat).
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    Route::get('bank-statements', [BankStatementController::class, 'index'])->middleware('feature:rekening_koran,view');
    Route::post('bank-statements', [BankStatementController::class, 'store'])->middleware('feature:rekening_koran,create');
    Route::post('bank-statements/import', [BankStatementController::class, 'import'])->middleware('feature:rekening_koran,create');
    Route::get('bank-statements/{id}', [BankStatementController::class, 'show'])->whereNumber('id')->middleware('feature:rekening_koran,view');
    Route::delete('bank-statements/{id}', [BankStatementController::class, 'destroy'])->whereNumber('id')->middleware('feature:rekening_koran,delete');

    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index'])->middleware('feature:rekonsiliasi_bank,view');
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store'])->middleware('feature:rekonsiliasi_bank,create');
    Route::get('bank-reconciliations/{id}', [BankReconciliationController::class, 'show'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,view');
    Route::delete('bank-reconciliations/{id}', [BankReconciliationController::class, 'destroy'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,delete');
    Route::get('bank-reconciliations/{id}/suggestions', [BankReconciliationController::class, 'suggestions'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,view');
    Route::post('bank-reconciliations/{id}/auto-match', [BankReconciliationController::class, 'autoMatch'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,update');
    Route::post('bank-reconciliations/{id}/matches', [BankReconciliationController::class, 'match'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,update');
    Route::delete('bank-reconciliations/{id}/matches/{itemId}', [BankReconciliationController::class, 'unmatch'])->whereNumber('id')->whereNumber('itemId')->middleware('feature:rekonsiliasi_bank,update');
    Route::post('bank-reconciliations/{id}/transactions', [BankReconciliationController::class, 'transaction'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,create');
    Route::post('bank-reconciliations/{id}/complete', [BankReconciliationController::class, 'complete'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,update');
    Route::post('bank-reconciliations/{id}/reopen', [BankReconciliationController::class, 'reopen'])->whereNumber('id')->middleware('feature:rekonsiliasi_bank,update');
});
