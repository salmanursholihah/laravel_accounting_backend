<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Approval\ApprovalService;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use App\Modules\Platform\Models\ApprovalRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint generik posting/void untuk SEMUA dokumen akuntansi (UC-62, UC-63). Hak akses dicek orkestrator
 * per dokumen (fitur dokumen x aksi post/void, termasuk "hanya miliknya"), bukan oleh middleware fitur.
 *
 * Step 6A.4: bila aturan approval berlaku untuk pengguna ini, aksi tidak dieksekusi melainkan diajukan (HTTP 202,
 * status pending_approval). Dokumen pending tidak bisa diposting langsung (orkestrator hanya menerima draft).
 */
class DocumentPostingController extends Controller
{
    public function post(Request $request, string $docType, int $id, PostingOrchestrator $orchestrator, ApprovalService $approvals, PostingHandlerRegistry $handlers): JsonResponse
    {
        $h = $handlers->get($docType);
        $doc = $h->find($id);

        if ($h->status($doc) === 'draft' && $approvals->requirementFor($docType, $doc, $request->user(), ApprovalRule::AKSI_POST)) {
            $req = $approvals->submit($docType, $id, $request->user(), ApprovalRule::AKSI_POST);

            return $this->pending($docType, $id, $req->getKey(), ApprovalRule::AKSI_POST);
        }

        $r = $orchestrator->post($docType, $id, $request->user());

        return response()->json(['data' => [
            'doc_type' => $docType, 'doc_id' => $id, 'nomor' => $r->nomor, 'status' => 'posted', 'journal_ids' => $r->journalIds,
        ]]);
    }

    public function void(Request $request, string $docType, int $id, PostingOrchestrator $orchestrator, ApprovalService $approvals, PostingHandlerRegistry $handlers): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        $h = $handlers->get($docType);
        $doc = $h->find($id);

        if ($h->status($doc) === 'posted' && $approvals->requirementFor($docType, $doc, $request->user(), ApprovalRule::AKSI_VOID)) {
            $req = $approvals->submit($docType, $id, $request->user(), ApprovalRule::AKSI_VOID, $data['alasan']);

            return $this->pending($docType, $id, $req->getKey(), ApprovalRule::AKSI_VOID);
        }

        $orchestrator->void($docType, $id, $data['alasan'], $request->user());

        return response()->json(['data' => ['doc_type' => $docType, 'doc_id' => $id, 'status' => 'void']]);
    }

    private function pending(string $docType, int $id, int|string $requestId, string $aksi): JsonResponse
    {
        return response()->json(['data' => [
            'doc_type' => $docType, 'doc_id' => $id, 'aksi' => $aksi, 'status' => 'pending_approval', 'approval_request_id' => $requestId,
        ], 'message' => 'Diajukan, menunggu persetujuan.'], 202);
    }
}
