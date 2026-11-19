<?php

namespace App\Modules\Accounting\Approval;

use App\Models\User;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\ApprovalRequest;
use App\Modules\Platform\Models\ApprovalRule;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approval ringan satu level (Tahap 7). Tidak menyentuh ALG-02: setelah disetujui, posting/void tetap dijalankan
 * PostingOrchestrator (post(..., approved: true) / void()), dalam transaksi yang sama dengan keputusan approval.
 * Posting gagal => keputusan ikut batal, pengajuan tetap pending.
 *
 * Aturan inti: pembuat tidak boleh memutuskan pengajuannya sendiri (juga dijaga CHECK di database);
 * Owner selalu boleh memutuskan; pengaju yang rolenya sama dengan approver aturan, atau Owner, tidak perlu approval (DECISIONS #4).
 * Dokumen yang tidak punya kolom `nominal`/`total` dianggap bernominal 0.
 */
class ApprovalService
{
    public function __construct(
        private PostingHandlerRegistry $handlers,
        private PermissionChecker $permissions,
        private PeriodService $periods,
        private PostingOrchestrator $orchestrator,
        private ApprovalRuleEvaluator $evaluator,
        private AuditLogger $audit,
        private CurrentCompany $current,
    ) {
    }

    /** Aturan yang mewajibkan approval untuk $user pada dokumen ini, atau null bila boleh langsung. */
    public function requirementFor(string $docType, Model $doc, User $user, string $aksi): ?ApprovalRule
    {
        $rules = ApprovalRule::query()
            ->where('is_aktif', true)->where('aksi', $aksi)
            ->whereIn('doc_type', [$docType, ApprovalRule::ANY_DOC])
            ->get()
            ->sortBy(fn (ApprovalRule $r) => $r->doc_type === ApprovalRule::ANY_DOC ? 1 : 0) // aturan spesifik dulu
            ->values();

        $amount = $this->amountOf($doc);
        $creatorRole = $this->roleOf((int) $doc->getAttribute('created_by'))?->nama;

        foreach ($rules as $rule) {
            if (! $this->evaluator->matches($rule->kondisi_json, $amount, $creatorRole)) {
                continue;
            }

            return $this->isExempt($user, $rule) ? null : $rule;
        }

        return null;
    }

    public function submit(string $docType, int $docId, User $user, string $aksi = ApprovalRule::AKSI_POST, ?string $reason = null): ApprovalRequest
    {
        $h = $this->handlers->get($docType);
        $preview = $h->find($docId);

        if (! $this->permissions->can($user, $h->feature(), $aksi, $preview)) {
            throw PostingException::forbidden($h->feature(), $aksi);
        }

        $reason = trim((string) $reason);
        if ($aksi === ApprovalRule::AKSI_VOID && $reason === '') {
            throw PostingException::reasonRequired();
        }

        return DB::transaction(function () use ($h, $docType, $docId, $user, $aksi, $reason) {
            $doc = $h->find($docId, true);

            $expected = $aksi === ApprovalRule::AKSI_POST ? 'draft' : 'posted';
            $status = $h->status($doc);
            if ($status !== $expected) {
                throw PostingException::wrongStatus($status, $expected);
            }
            if ($aksi === ApprovalRule::AKSI_POST) {
                $this->periods->assertPostable($h->tanggal($doc));
            }

            $rule = $this->requirementFor($docType, $doc, $user, $aksi) ?? throw ApprovalException::notRequired($docType, $aksi);

            $key = ApprovalRequest::activeKey($this->current->id(), $docType, $docId, $aksi);
            if (ApprovalRequest::query()->where('aktif_key', $key)->lockForUpdate()->exists()) {
                throw ApprovalException::alreadyPending();
            }

            try {
                $req = ApprovalRequest::create([
                    'rule_id' => $rule->getKey(), 'doc_type' => $docType, 'doc_id' => $docId, 'aksi' => $aksi,
                    'pengaju' => $user->getKey(), 'approver_role_id' => $rule->approver_role_id,
                    'status' => ApprovalRequest::PENDING, 'alasan_void' => $aksi === ApprovalRule::AKSI_VOID ? $reason : null,
                    'aktif_key' => $key, 'diajukan_pada' => now(),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) { // balapan: backstop UNIQUE aktif_key
                    throw ApprovalException::alreadyPending();
                }
                throw $e;
            }

            if ($aksi === ApprovalRule::AKSI_POST) {
                $this->setDocStatus($doc, 'pending_approval');
            }

            $this->audit->log($docType, $docId, 'approval_submit', ['status' => $status], [
                'request_id' => $req->getKey(), 'aksi' => $aksi,
            ], $aksi === ApprovalRule::AKSI_VOID ? $reason : null, $this->current->id(), (int) $user->getKey());

            return $req;
        });
    }

    /** Setujui lalu jalankan posting/void lewat orkestrator (ALG-02/ALG-07) dalam satu transaksi. */
    public function approve(int $requestId, User $user): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $user) {
            $req = $this->lockPending($requestId);
            $this->assertCanDecide($req, $user);

            if ($req->aksi === ApprovalRule::AKSI_POST) {
                $this->orchestrator->post($req->doc_type, (int) $req->doc_id, $user, true);
            } else {
                $this->orchestrator->void($req->doc_type, (int) $req->doc_id, (string) $req->alasan_void, $user);
            }

            $this->close($req, ApprovalRequest::APPROVED, $user);
            $this->audit->log($req->doc_type, (int) $req->doc_id, 'approval_approve', null, ['request_id' => $req->getKey(), 'aksi' => $req->aksi], null, $this->current->id(), (int) $user->getKey());

            return $req;
        });
    }

    public function reject(int $requestId, User $user, string $reason): ApprovalRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ApprovalException::reasonRequired();
        }

        return DB::transaction(function () use ($requestId, $user, $reason) {
            $req = $this->lockPending($requestId);
            $this->assertCanDecide($req, $user);

            $this->revertDocument($req);
            $this->close($req, ApprovalRequest::REJECTED, $user, $reason);
            $this->audit->log($req->doc_type, (int) $req->doc_id, 'approval_reject', null, ['request_id' => $req->getKey(), 'aksi' => $req->aksi], $reason, $this->current->id(), (int) $user->getKey());

            return $req;
        });
    }

    /** Pengaju (atau Owner) menarik kembali pengajuannya. */
    public function cancel(int $requestId, User $user): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $user) {
            $req = $this->lockPending($requestId);
            $role = $this->roleOf((int) $user->getKey());
            if ((int) $req->pengaju !== (int) $user->getKey() && $role?->nama !== 'Owner') {
                throw ApprovalException::notApprover();
            }

            $this->revertDocument($req);
            $this->close($req, ApprovalRequest::CANCELLED, null, 'Dibatalkan oleh pengaju.');
            $this->audit->log($req->doc_type, (int) $req->doc_id, 'approval_cancel', null, ['request_id' => $req->getKey()], null, $this->current->id(), (int) $user->getKey());

            return $req;
        });
    }

    /**
     * Dipanggil service dokumen saat dokumen pending diubah (6A.4): pengajuan otomatis batal.
     * Status dokumen dikembalikan ke draft di sini. Aman dipanggil bila tidak ada pengajuan pending.
     */
    public function cancelPendingFor(string $docType, int $docId, string $why = 'Dokumen diubah saat menunggu persetujuan.'): int
    {
        return DB::transaction(function () use ($docType, $docId, $why) {
            $count = 0;
            $pending = ApprovalRequest::query()->where('doc_type', $docType)->where('doc_id', $docId)
                ->where('status', ApprovalRequest::PENDING)->lockForUpdate()->get();

            foreach ($pending as $req) {
                $this->revertDocument($req);
                $this->close($req, ApprovalRequest::CANCELLED, null, $why);
                $this->audit->log($docType, $docId, 'approval_cancel', null, ['request_id' => $req->getKey()], $why, $this->current->id());
                $count++;
            }

            return $count;
        });
    }

    /**
     * Dipakai service dokumen di awal update(): bila dokumen berstatus pending_approval, pengajuan dibatalkan dan
     * dokumen dikembalikan ke draft (lalu update boleh lanjut). Bukan pending: dikembalikan apa adanya.
     */
    public function releaseIfPending(Model $doc, string $docType): Model
    {
        if ($doc->getAttribute('status') !== 'pending_approval') {
            return $doc;
        }
        $this->cancelPendingFor($docType, (int) $doc->getKey());

        return $doc->refresh();
    }

    /** @param string $tab waiting (menunggu saya) | mine (diajukan saya) | history (sudah diputuskan) */
    public function inbox(User $user, string $tab, ?int $beforeId = null, int $limit = 50): Collection
    {
        $q = ApprovalRequest::query()->orderByDesc('id')->limit(min(max($limit, 1), 100));
        if ($beforeId) {
            $q->where('id', '<', $beforeId);
        }

        match ($tab) {
            'waiting' => $this->scopeWaiting($q, $user),
            'mine' => $q->where('pengaju', $user->getKey()),
            'history' => $this->scopeHistory($q, $user),
            default => throw ApprovalException::invalidTab($tab),
        };

        return $q->get();
    }

    /** Riwayat: Owner melihat semua; lainnya hanya yang diajukan/diputuskannya atau ditujukan ke rolenya. */
    private function scopeHistory($q, User $user): void
    {
        $role = $this->roleOf((int) $user->getKey());
        $q->where('status', '!=', ApprovalRequest::PENDING);

        if ($role?->nama === 'Owner') {
            return;
        }
        $q->where(function ($w) use ($user, $role) {
            $w->where('pengaju', $user->getKey())->orWhere('approver_id', $user->getKey());
            if ($role) {
                $w->orWhere('approver_role_id', $role->role_id);
            }
        });
    }

    private function scopeWaiting($q, User $user): void
    {
        $role = $this->roleOf((int) $user->getKey());
        $q->where('status', ApprovalRequest::PENDING)->where('pengaju', '!=', $user->getKey());

        if (! $role) {
            $q->whereRaw('1 = 0');
        } elseif ($role->nama !== 'Owner') {
            $q->where('approver_role_id', $role->role_id);
        }
    }

    private function lockPending(int $requestId): ApprovalRequest
    {
        $req = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);
        if (! $req->isPending()) {
            throw ApprovalException::notPending($req->status);
        }

        return $req;
    }

    private function assertCanDecide(ApprovalRequest $req, User $user): void
    {
        if ((int) $req->pengaju === (int) $user->getKey()) {
            throw ApprovalException::selfApproval();
        }
        $role = $this->roleOf((int) $user->getKey());
        if (! $role || ($role->nama !== 'Owner' && (int) $role->role_id !== (int) $req->approver_role_id)) {
            throw ApprovalException::notApprover();
        }
    }

    private function close(ApprovalRequest $req, string $status, ?User $decider, ?string $alasanTolak = null): void
    {
        $req->forceFill([
            'status' => $status, 'aktif_key' => null, 'diputuskan_pada' => now(),
            'approver_id' => $decider?->getKey(), 'alasan_tolak' => $alasanTolak,
        ])->save();
    }

    /** Posting yang batal/ditolak: dokumen kembali draft. Void: dokumen tetap posted (tidak berubah). */
    private function revertDocument(ApprovalRequest $req): void
    {
        if ($req->aksi !== ApprovalRule::AKSI_POST) {
            return;
        }
        $h = $this->handlers->get($req->doc_type);
        $doc = $h->find((int) $req->doc_id, true);
        if ($h->status($doc) === 'pending_approval') {
            $this->setDocStatus($doc, 'draft');
        }
    }

    private function setDocStatus(Model $doc, string $status): void
    {
        $doc->newQuery()->whereKey($doc->getKey())->update(['status' => $status]);
    }

    public function amountOf(Model $doc): float
    {
        foreach (['nominal', 'total'] as $col) {
            if ($doc->getAttribute($col) !== null) {
                return (float) $doc->getAttribute($col);
            }
        }

        return 0.0;
    }

    private function isExempt(User $user, ApprovalRule $rule): bool
    {
        $role = $this->roleOf((int) $user->getKey());

        return $role !== null && ($role->nama === 'Owner' || (int) $role->role_id === (int) $rule->approver_role_id);
    }

    /** @return object{role_id:int, nama:string}|null role aktif user di perusahaan aktif */
    private function roleOf(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        return DB::table('company_users as cu')->join('roles as r', 'r.id', '=', 'cu.role_id')
            ->where('cu.company_id', $this->current->id())->where('cu.user_id', $userId)->where('cu.status', 'active')
            ->first(['cu.role_id', 'r.nama']);
    }
}
