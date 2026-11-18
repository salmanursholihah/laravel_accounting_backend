<?php

namespace App\Modules\Accounting\Posting;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan orkestrasi posting/void dokumen (ALG-02, ALG-07). */
class PostingException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'posting_error',
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function unknownDocType(string $docType): self
    {
        return new self("Jenis dokumen '{$docType}' tidak dikenal untuk posting.", 'unknown_doc_type', ['doc_type' => $docType], 404);
    }

    public static function forbidden(string $fitur, string $aksi): self
    {
        return new self('Anda tidak punya hak untuk aksi ini pada dokumen tersebut.', 'forbidden', ['fitur' => $fitur, 'aksi' => $aksi], 403);
    }

    public static function wrongStatus(string $actual, string $expected): self
    {
        return new self("Status dokumen '{$actual}' tidak bisa diproses; dibutuhkan status {$expected}.", 'wrong_status', ['status' => $actual]);
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan pembatalan wajib diisi.', 'reason_required');
    }

    public static function reconciled(): self
    {
        return new self('Dokumen sudah direkonsiliasi bank. Buka rekonsiliasi terlebih dahulu.', 'reconciled');
    }

    /** @param list<array{doc_type:string, nomor:string}|string> $blockers */
    public static function hasActiveChildren(array $blockers): self
    {
        $names = array_map(fn ($b) => is_array($b) ? trim(($b['nomor'] ?? '') . ' ' . ($b['doc_type'] ?? '')) : (string) $b, $blockers);

        return new self(
            'Dokumen tidak bisa dibatalkan karena masih punya dokumen turunan aktif: ' . implode(', ', $names) . '.',
            'has_active_children',
            ['penghalang' => $blockers],
        );
    }

    public static function rule(string $message): self
    {
        return new self($message, 'journal_rule_error');
    }

    public static function stockModuleMissing(): self
    {
        return new self('Dokumen ini menggerakkan stok, tetapi modul persediaan belum terpasang.', 'stock_module_missing', [], 500);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + ($this->context ? ['context' => $this->context] : []), $this->status);
    }
}
