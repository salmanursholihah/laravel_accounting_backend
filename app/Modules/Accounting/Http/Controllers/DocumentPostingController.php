<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Posting\PostingOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint generik posting/void untuk SEMUA dokumen akuntansi (UC-62, UC-63). Hak akses dicek orkestrator
 * per dokumen (fitur dokumen x aksi post/void, termasuk "hanya miliknya"), bukan oleh middleware fitur.
 */
class DocumentPostingController extends Controller
{
    public function post(Request $request, string $docType, int $id, PostingOrchestrator $orchestrator): JsonResponse
    {
        $r = $orchestrator->post($docType, $id, $request->user());

        return response()->json(['data' => [
            'doc_type' => $docType, 'doc_id' => $id, 'nomor' => $r->nomor, 'status' => 'posted', 'journal_ids' => $r->journalIds,
        ]]);
    }

    public function void(Request $request, string $docType, int $id, PostingOrchestrator $orchestrator): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        $orchestrator->void($docType, $id, $data['alasan'], $request->user());

        return response()->json(['data' => ['doc_type' => $docType, 'doc_id' => $id, 'status' => 'void']]);
    }
}
