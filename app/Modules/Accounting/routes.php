<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AgingReportController;
use App\Modules\Accounting\Http\Controllers\ApprovalController;
use App\Modules\Accounting\Http\Controllers\ApprovalRuleController;
use App\Modules\Accounting\Http\Controllers\DashboardController;
use App\Modules\Accounting\Http\Controllers\DocumentPostingController;
use App\Modules\Accounting\Http\Controllers\FinancialReportController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\LedgerReportController;
use App\Modules\Accounting\Http\Controllers\NumberingController;
use App\Modules\Accounting\Http\Controllers\OpeningBalanceController;
use App\Modules\Accounting\Http\Controllers\PaymentTermController;
use App\Modules\Accounting\Http\Controllers\PeriodController;
use App\Modules\Accounting\Http\Controllers\PeriodCloseController;
use App\Modules\Accounting\Http\Controllers\SystemAccountController;
use App\Modules\Accounting\Http\Controllers\TaxCodeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'company'])->group(function () {
    // UC-07 Penomoran
    Route::get('numbering', [NumberingController::class, 'index'])->middleware('feature:penomoran,view');
    Route::put('numbering/{docType}', [NumberingController::class, 'update'])->middleware('feature:penomoran,update');

    // Tahun buku dan periode
    Route::get('fiscal-years', [PeriodController::class, 'index'])->middleware('feature:proses_akhir_bulan,view');
    Route::post('fiscal-years', [PeriodController::class, 'store'])->middleware('feature:proses_akhir_bulan,create');
    Route::post('periods/{id}/reopen', [PeriodController::class, 'reopen'])->middleware('feature:khusus,buka_kunci_periode'); // UC-14

    // Proses Akhir Bulan dan Tutup Tahun (ALG-11)
    Route::get('periods/{id}/close-check', [PeriodCloseController::class, 'check'])->whereNumber('id')->middleware('feature:proses_akhir_bulan,view');
    Route::get('periods/{id}/close-logs', [PeriodCloseController::class, 'logs'])->whereNumber('id')->middleware('feature:proses_akhir_bulan,view');
    Route::post('periods/{id}/close', [PeriodCloseController::class, 'close'])->whereNumber('id')->middleware('feature:khusus,tutup_periode');
    Route::get('fiscal-years/{id}/close-preview', [PeriodCloseController::class, 'yearPreview'])->whereNumber('id')->middleware('feature:proses_akhir_bulan,view');
    Route::post('fiscal-years/{id}/close', [PeriodCloseController::class, 'yearClose'])->whereNumber('id')->middleware('feature:khusus,tutup_periode');
    Route::post('fiscal-years/{id}/reopen', [PeriodCloseController::class, 'yearReopen'])->whereNumber('id')->middleware('feature:khusus,buka_kunci_periode');

    // UC-15 Akun Perkiraan
    Route::get('accounts', [AccountController::class, 'index'])->middleware('feature:akun_perkiraan,view');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('feature:akun_perkiraan,create');
    Route::put('accounts/{id}', [AccountController::class, 'update'])->middleware('feature:akun_perkiraan,update');
    Route::delete('accounts/{id}', [AccountController::class, 'destroy'])->middleware('feature:akun_perkiraan,delete');

    // Akun default (Preferensi)
    Route::get('system-accounts', [SystemAccountController::class, 'index'])->middleware('feature:preferensi,view');
    Route::put('system-accounts/{key}', [SystemAccountController::class, 'update'])->middleware('feature:khusus,ubah_preferensi');

    // UC-10 Pajak (tarif berversi per tanggal berlaku) dan Syarat Pembayaran
    Route::get('tax-codes', [TaxCodeController::class, 'index'])->middleware('feature:pajak,view');
    Route::post('tax-codes', [TaxCodeController::class, 'store'])->middleware('feature:pajak,create');
    Route::put('tax-codes/{id}', [TaxCodeController::class, 'update'])->middleware('feature:pajak,update');
    Route::delete('tax-codes/{id}', [TaxCodeController::class, 'destroy'])->middleware('feature:pajak,delete');

    Route::get('payment-terms', [PaymentTermController::class, 'index'])->middleware('feature:syarat_pembayaran,view');
    Route::post('payment-terms', [PaymentTermController::class, 'store'])->middleware('feature:syarat_pembayaran,create');
    Route::put('payment-terms/{id}', [PaymentTermController::class, 'update'])->middleware('feature:syarat_pembayaran,update');
    Route::delete('payment-terms/{id}', [PaymentTermController::class, 'destroy'])->middleware('feature:syarat_pembayaran,delete');

    // UC-17 Jurnal Umum, koreksi, void (jurnal otomatis dokumen hanya dibatalkan lewat Void dokumen sumbernya)
    Route::get('journals', [JournalController::class, 'index'])->middleware('feature:jurnal_umum,view');
    Route::get('journals/{id}', [JournalController::class, 'show'])->whereNumber('id')->middleware('feature:jurnal_umum,view');
    Route::post('journals', [JournalController::class, 'store'])->middleware('feature:jurnal_umum,post');
    Route::post('journal-corrections', [JournalController::class, 'correct'])->middleware('feature:khusus,jurnal_koreksi');
    Route::post('journals/{id}/void', [JournalController::class, 'void'])->whereNumber('id')->middleware('feature:jurnal_umum,void');

    // UC-62/63 Posting dan Void dokumen akuntansi apa pun (hak dicek per dokumen oleh orkestrator)
    Route::post('documents/{docType}/{id}/post', [DocumentPostingController::class, 'post'])->whereNumber('id');
    Route::post('documents/{docType}/{id}/void', [DocumentPostingController::class, 'void'])->whereNumber('id');

    // UC-16 Saldo awal
    Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->middleware('feature:jurnal_umum,view');
    Route::put('opening-balances', [OpeningBalanceController::class, 'update'])->middleware('feature:jurnal_umum,create');
    Route::post('opening-balances/post', [OpeningBalanceController::class, 'post'])->middleware('feature:jurnal_umum,post');
    Route::post('opening-balances/void', [OpeningBalanceController::class, 'void'])->middleware('feature:jurnal_umum,void');

    // UC-22 Histori Akun dan Neraca Saldo
    Route::get('reports/account-history', [LedgerReportController::class, 'accountHistory'])->middleware('feature:histori_akun,view');
    // 6B Laporan keuangan: hanya Owner/Admin/Akuntan (fitur laporan_keuangan, bukan daftar_laporan yang juga dimiliki Kasir/Gudang/Purchasing)
    Route::get('reports/trial-balance', [LedgerReportController::class, 'trialBalance'])->middleware('feature:laporan_keuangan,view');
    Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->middleware('feature:laporan_keuangan,view');
    Route::get('reports/income-statement', [FinancialReportController::class, 'incomeStatement'])->middleware('feature:laporan_keuangan,view');
    Route::get('reports/general-ledger', [FinancialReportController::class, 'generalLedger'])->middleware('feature:laporan_keuangan,view');
    // 6C Umur piutang/utang: Owner/Admin/Akuntan + Kasir (piutang) + Purchasing (utang)
    Route::get('reports/ar-aging', [AgingReportController::class, 'receivables'])->middleware('feature:umur_piutang,view');
    Route::get('reports/ap-aging', [AgingReportController::class, 'payables'])->middleware('feature:umur_utang,view');
    // 6D Dashboard: widget ditentukan izin user di DashboardService
    Route::get('dashboard', [DashboardController::class, 'show'])->middleware('feature:dashboard,view');

    // 6A.5 Kotak Persetujuan (hak memutuskan dicek di ApprovalService) dan aturan approval (Owner/Admin)
    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id');
    Route::post('approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id');
    Route::post('approvals/{id}/cancel', [ApprovalController::class, 'cancel'])->whereNumber('id');
    Route::get('approval-rules', [ApprovalRuleController::class, 'index'])->middleware('feature:khusus,ubah_preferensi');
    Route::post('approval-rules', [ApprovalRuleController::class, 'store'])->middleware('feature:khusus,ubah_preferensi');
    Route::put('approval-rules/{id}', [ApprovalRuleController::class, 'update'])->whereNumber('id')->middleware('feature:khusus,ubah_preferensi');
    Route::delete('approval-rules/{id}', [ApprovalRuleController::class, 'deactivate'])->whereNumber('id')->middleware('feature:khusus,ubah_preferensi');
});
