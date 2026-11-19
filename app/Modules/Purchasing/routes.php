<?php

use App\Modules\Purchasing\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Purchasing\Http\Controllers\PurchasePaymentController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    // UC-38 Pemasok
    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('feature:pemasok,view');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('feature:pemasok,create');
    Route::get('suppliers/{id}', [SupplierController::class, 'show'])->whereNumber('id')->middleware('feature:pemasok,view');
    Route::put('suppliers/{id}', [SupplierController::class, 'update'])->whereNumber('id')->middleware('feature:pemasok,update');

    // UC-42 Faktur Pembelian (draft). Posting/Void: POST documents/purchase_invoice/{id}/post|void
    Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('feature:faktur_pembelian,view');
    Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('feature:faktur_pembelian,create');
    Route::get('purchase-invoices/{id}', [PurchaseInvoiceController::class, 'show'])->whereNumber('id')->middleware('feature:faktur_pembelian,view');
    Route::put('purchase-invoices/{id}', [PurchaseInvoiceController::class, 'update'])->whereNumber('id')->middleware('feature:faktur_pembelian,update');
    Route::delete('purchase-invoices/{id}', [PurchaseInvoiceController::class, 'destroy'])->whereNumber('id')->middleware('feature:faktur_pembelian,delete');

    // UC-44 Pembayaran Pembelian (draft + alokasi). Posting/Void: POST documents/purchase_payment/{id}/post|void
    Route::get('purchase-payments/open-invoices', [PurchasePaymentController::class, 'openInvoices'])->middleware('feature:pembayaran_pembelian,view');
    Route::get('purchase-payments', [PurchasePaymentController::class, 'index'])->middleware('feature:pembayaran_pembelian,view');
    Route::post('purchase-payments', [PurchasePaymentController::class, 'store'])->middleware('feature:pembayaran_pembelian,create');
    Route::get('purchase-payments/{id}', [PurchasePaymentController::class, 'show'])->whereNumber('id')->middleware('feature:pembayaran_pembelian,view');
    Route::put('purchase-payments/{id}', [PurchasePaymentController::class, 'update'])->whereNumber('id')->middleware('feature:pembayaran_pembelian,update');
    Route::delete('purchase-payments/{id}', [PurchasePaymentController::class, 'destroy'])->whereNumber('id')->middleware('feature:pembayaran_pembelian,delete');
});
