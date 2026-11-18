<?php

namespace App\Modules\CashBank\Documents;

use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Models\Expense;
use App\Modules\CashBank\Models\ExpenseLine;
use Illuminate\Database\Eloquent\Model;

/**
 * Pencatatan Beban (UC-18): Dr Akun Beban (+ PPN Masukan) · Cr Kas/Bank atau Utang Usaha.
 * jenis_pembayaran 'kas_bank' butuh rekening; 'utang' butuh kontak (pemasok) dan tanpa rekening.
 */
class ExpenseService extends LineDocumentService
{
    public function docType(): string { return 'expense'; }
    public function side(): string { return 'out'; }
    protected function modelClass(): string { return Expense::class; }
    protected function lineClass(): string { return ExpenseLine::class; }
    protected function lineFk(): string { return 'expense_id'; }
    protected function bankColumn(): string { return 'rekening_pembayaran_id'; }

    public function rules(): array
    {
        $r = parent::rules();
        $r['rekening_id'] = ['nullable', 'integer'];

        return $r + [
            'jenis_pembayaran' => ['sometimes', 'in:kas_bank,utang'],
            'dapat_dikreditkan' => ['sometimes', 'boolean'],
            'no_faktur_pajak' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function bankOptional(array $data): bool
    {
        return ($data['jenis_pembayaran'] ?? 'kas_bank') === 'utang';
    }

    protected function extraHeader(array $data, ?Model $existing): array
    {
        return [
            'jenis_pembayaran' => $data['jenis_pembayaran'] ?? 'kas_bank',
            'dapat_dikreditkan' => (bool) ($data['dapat_dikreditkan'] ?? true),
            'no_faktur_pajak' => $data['no_faktur_pajak'] ?? null,
        ];
    }

    protected function validateExtra(array $data, array &$header): void
    {
        if ($header['jenis_pembayaran'] === 'utang') {
            if ($header['contact_id'] === null) {
                throw new CashBankException('Beban yang dicatat sebagai utang wajib punya kontak (pemasok).', 'contact_required');
            }
            $header['rekening_pembayaran_id'] = null; // utang tidak menyentuh kas/bank
        }
        if ($header['dapat_dikreditkan'] && collect($data['lines'] ?? [])->whereNotNull('tax_code_id')->isNotEmpty() && empty($header['no_faktur_pajak'])) {
            throw new CashBankException('PPN Masukan hanya bisa dikreditkan dengan nomor faktur pajak. Isi no_faktur_pajak atau set dapat_dikreditkan = false.', 'faktur_pajak_required');
        }
    }
}
