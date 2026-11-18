<?php

namespace App\Modules\CashBank\Documents;

use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashPaymentLine;
use Illuminate\Database\Eloquent\Model;

/**
 * Pembayaran Kas/Bank di luar faktur (UC-24): Dr akun beban/utang (+ PPN Masukan) · Cr Kas/Bank.
 * PPN Masukan hanya dikreditkan bila dapat_dikreditkan (faktur pajak valid); selain itu masuk biaya perolehan baris.
 */
class CashPaymentService extends LineDocumentService
{
    public function docType(): string { return 'cash_payment'; }
    public function side(): string { return 'out'; }
    protected function modelClass(): string { return CashPayment::class; }
    protected function lineClass(): string { return CashPaymentLine::class; }
    protected function lineFk(): string { return 'cash_payment_id'; }
    protected function bankColumn(): string { return 'rekening_sumber_id'; }

    public function rules(): array
    {
        return parent::rules() + [
            'dapat_dikreditkan' => ['sometimes', 'boolean'],
            'no_faktur_pajak' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function extraHeader(array $data, ?Model $existing): array
    {
        return [
            'dapat_dikreditkan' => (bool) ($data['dapat_dikreditkan'] ?? true),
            'no_faktur_pajak' => $data['no_faktur_pajak'] ?? null,
        ];
    }

    protected function validateExtra(array $data, array &$header): void
    {
        if ($header['dapat_dikreditkan'] && ! empty($data['lines']) && collect($data['lines'])->whereNotNull('tax_code_id')->isNotEmpty() && empty($header['no_faktur_pajak'])) {
            throw new CashBankException('PPN Masukan hanya bisa dikreditkan dengan nomor faktur pajak. Isi no_faktur_pajak atau set dapat_dikreditkan = false.', 'faktur_pajak_required');
        }
    }
}
