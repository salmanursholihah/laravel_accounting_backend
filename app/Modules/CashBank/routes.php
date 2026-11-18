<?php

use App\Modules\CashBank\Http\Controllers\BankAccountController;
use App\Modules\CashBank\Http\Controllers\BankHistoryController;
use App\Modules\CashBank\Http\Controllers\CashDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    // Master Rekening Kas/Bank (hak: Akun Perkiraan; daftar boleh dibaca pembuat dokumen Kas & Bank)
    Route::get('bank-accounts', [BankAccountController::class, 'index']);
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('feature:akun_perkiraan,create');
    Route::put('bank-accounts/{id}', [BankAccountController::class, 'update'])->whereNumber('id')->middleware('feature:akun_perkiraan,update');
    Route::delete('bank-accounts/{id}', [BankAccountController::class, 'destroy'])->whereNumber('id')->middleware('feature:akun_perkiraan,delete');

    // Dokumen Kas & Bank: uri => [jenis dokumen, fitur RBAC]. Posting/Void lewat /documents/{docType}/{id}/post|void.
    $docs = [
        'cash-receipts' => ['cash_receipt', 'penerimaan'],         // UC-23
        'cash-payments' => ['cash_payment', 'pembayaran'],         // UC-24
        'bank-transfers' => ['bank_transfer', 'transfer_bank'],    // UC-25
        'expenses' => ['expense', 'pencatatan_beban'],             // UC-18
    ];

    foreach ($docs as $uri => [$doc, $fitur]) {
        Route::get($uri, [CashDocumentController::class, 'index'])->defaults('doc', $doc)->middleware("feature:{$fitur},view");
        Route::post($uri, [CashDocumentController::class, 'store'])->defaults('doc', $doc)->middleware("feature:{$fitur},create");
        Route::get("{$uri}/{id}", [CashDocumentController::class, 'show'])->defaults('doc', $doc)->whereNumber('id')->middleware("feature:{$fitur},view");
        Route::put("{$uri}/{id}", [CashDocumentController::class, 'update'])->defaults('doc', $doc)->whereNumber('id')->middleware("feature:{$fitur},update");
        Route::delete("{$uri}/{id}", [CashDocumentController::class, 'destroy'])->defaults('doc', $doc)->whereNumber('id')->middleware("feature:{$fitur},delete");
    }

    // Histori Bank
    Route::get('reports/bank-history/{id}', [BankHistoryController::class, 'show'])->whereNumber('id')->middleware('feature:histori_bank,view');
});
