<?php

namespace App\Modules\CashBank\Documents;

use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\CashBank\Models\CashReceiptLine;

/** Penerimaan Kas/Bank di luar faktur (UC-23): Dr Kas/Bank · Cr akun pendapatan/lainnya (+ PPN Keluaran). */
class CashReceiptService extends LineDocumentService
{
    public function docType(): string { return 'cash_receipt'; }
    public function side(): string { return 'in'; }
    protected function modelClass(): string { return CashReceipt::class; }
    protected function lineClass(): string { return CashReceiptLine::class; }
    protected function lineFk(): string { return 'cash_receipt_id'; }
    protected function bankColumn(): string { return 'rekening_tujuan_id'; }
}
