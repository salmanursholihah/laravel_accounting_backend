<?php

namespace App\Modules\Accounting\Approval;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan approval. Dirender sendiri seperti PostingException. */
class ApprovalException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'approval_error',
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notRequired(string $docType, string $aksi): self
    {
        return new self("Dokumen '{$docType}' tidak memerlukan persetujuan untuk aksi {$aksi}; lakukan langsung.", 'approval_not_required', ['doc_type' => $docType, 'aksi' => $aksi]);
    }

    public static function alreadyPending(): self
    {
        return new self('Dokumen ini sudah punya pengajuan yang menunggu persetujuan.', 'approval_already_pending', [], 409);
    }

    public static function notPending(string $status): self
    {
        return new self("Pengajuan sudah berstatus '{$status}' dan tidak bisa diproses lagi.", 'approval_not_pending', ['status' => $status], 409);
    }

    public static function selfApproval(): self
    {
        return new self('Anda tidak boleh menyetujui atau menolak pengajuan Anda sendiri.', 'approval_self', [], 403);
    }

    public static function notApprover(): self
    {
        return new self('Anda bukan penyetuju yang ditetapkan untuk pengajuan ini.', 'approval_not_approver', [], 403);
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan penolakan wajib diisi.', 'approval_reason_required');
    }

    public static function invalidTab(string $tab): self
    {
        return new self("Tab '{$tab}' tidak dikenal (waiting, mine, history).", 'approval_invalid_tab');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + ($this->context ? ['context' => $this->context] : []), $this->status);
    }
}
