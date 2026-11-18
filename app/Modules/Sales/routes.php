<?php

use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    // UC-28 Pelanggan
    Route::get('customers', [CustomerController::class, 'index'])->middleware('feature:pelanggan,view');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('feature:pelanggan,create');
    Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id')->middleware('feature:pelanggan,view');
    Route::put('customers/{id}', [CustomerController::class, 'update'])->whereNumber('id')->middleware('feature:pelanggan,update');

    // UC-33 Faktur Penjualan (draft). Posting/Void: POST documents/sales_invoice/{id}/post|void
    Route::get('sales-invoices', [SalesInvoiceController::class, 'index'])->middleware('feature:faktur_penjualan,view');
    Route::post('sales-invoices', [SalesInvoiceController::class, 'store'])->middleware('feature:faktur_penjualan,create');
    Route::get('sales-invoices/{id}', [SalesInvoiceController::class, 'show'])->whereNumber('id')->middleware('feature:faktur_penjualan,view');
    Route::put('sales-invoices/{id}', [SalesInvoiceController::class, 'update'])->whereNumber('id')->middleware('feature:faktur_penjualan,update');
    Route::delete('sales-invoices/{id}', [SalesInvoiceController::class, 'destroy'])->whereNumber('id')->middleware('feature:faktur_penjualan,delete');
});
