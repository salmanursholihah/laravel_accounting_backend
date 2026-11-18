<?php

namespace App\Modules\Sales;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan modul Penjualan (pelanggan, draft faktur, validasi baris). */
class SalesException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'sales_error', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function notFound(string $apa): self
    {
        return new self("{$apa} tidak ditemukan.", 'not_found', 404);
    }

    public static function notDraft(string $status): self
    {
        return new self("Dokumen berstatus '{$status}' tidak bisa diubah atau dihapus. Gunakan Void atau Retur untuk mengoreksi dokumen terposting.", 'not_draft');
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
