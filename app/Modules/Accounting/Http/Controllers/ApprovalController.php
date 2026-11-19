<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Approval\ApprovalService;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Platform\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kotak Persetujuan. Tanpa middleware fitur: siapa pun yang login di perusahaan aktif boleh membuka kotaknya,
 * isinya dibatasi per role oleh ApprovalService; hak memutuskan dicek di service (bukan pembuat, role approver/Owner).
 */
class ApprovalController extends Controller
{
    public function index(Request $request, ApprovalService $svc, PostingHandlerRegistry $handlers): JsonResponse
    {
        $v = $request->validate([
            'tab' => ['sometimes', 'in:waiting,mine,history'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $limit = (int) ($v['limit'] ?? 50);
        $rows = $svc->inbox($request->user(), $v['tab'] ?? 'waiting', $v['before_id'] ?? null, $limit);

        return response()->json([
            'data' => $rows->map(fn (ApprovalRequest $r) => $this->present($r, $svc, $handlers))->values(),
            'next_before_id' => $rows->count() === $limit ? $rows->last()->id : null,
        ]);
    }

    public function approve(Request $request, int $id, ApprovalService $svc, PostingHandlerRegistry $handlers): JsonResponse
    {
        return response()->json(['data' => $this->present($svc->approve($id, $request->user()), $svc, $handlers)]);
    }

    public function reject(Request $request, int $id, ApprovalService $svc, PostingHandlerRegistry $handlers): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->present($svc->reject($id, $request->user(), $data['alasan']), $svc, $handlers)]);
    }

    public function cancel(Request $request, int $id, ApprovalService $svc, PostingHandlerRegistry $handlers): JsonResponse
    {
        return response()->json(['data' => $this->present($svc->cancel($id, $request->user()), $svc, $handlers)]);
    }

    /** Catatan: satu query dokumen per baris (maks 100 per halaman); cukup untuk kotak persetujuan. */
    private function present(ApprovalRequest $r, ApprovalService $svc, PostingHandlerRegistry $handlers): array
    {
        $doc = ['nomor' => null, 'tanggal' => null, 'nominal' => null];
        try {
            $h = $handlers->get($r->doc_type);
            $m = $h->find((int) $r->doc_id);
            $doc = ['nomor' => $m->getAttribute('nomor'), 'tanggal' => $h->tanggal($m), 'nominal' => $svc->amountOf($m)];
        } catch (PostingException|ModelNotFoundException) {
            // dokumen sudah tidak terbaca: tampilkan pengajuan apa adanya
        }

        return [
            'id' => $r->id, 'doc_type' => $r->doc_type, 'doc_id' => $r->doc_id, 'aksi' => $r->aksi, 'status' => $r->status,
            'pengaju' => $r->pengaju, 'approver_role_id' => $r->approver_role_id, 'approver_id' => $r->approver_id,
            'alasan_void' => $r->alasan_void, 'alasan_tolak' => $r->alasan_tolak,
            'diajukan_pada' => $r->diajukan_pada?->toIso8601String(), 'diputuskan_pada' => $r->diputuskan_pada?->toIso8601String(),
            'dokumen' => $doc,
        ];
    }
}
