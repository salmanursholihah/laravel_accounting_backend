<?php

namespace App\Modules\CashBank;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan modul Kas & Bank (rekening, dokumen draft, validasi akun). */
class CashBankException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'cash_bank_error', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notDraft(string $status): self
    {
        return new self("Dokumen berstatus '{$status}' tidak bisa diubah atau dihapus. Gunakan Void untuk membatalkan dokumen terposting.", 'not_draft');
    }

    public static function conflict(): self
    {
        return new self('Dokumen sudah diubah orang lain. Muat ulang lalu ulangi perubahan Anda.', 'edit_conflict', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
