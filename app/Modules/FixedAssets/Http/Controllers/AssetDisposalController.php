<?php

namespace App\Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Disposal\AssetDisposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Disposisi Aset Tetap (draf). Posting dan Void lewat endpoint generik documents/asset_disposal/{id}/post|void. */
class AssetDisposalController extends Controller
{
    public function index(Request $request, AssetDisposalService $svc): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:draft,pending_approval,posted,void'], 'asset_id' => ['sometimes', 'integer'], 'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $r = $svc->list($request->only(['status', 'asset_id', 'before_id', 'limit']));

        return response()->json(['data' => $r['data']->map(fn ($d) => $svc->present($d))->values(), 'next_before_id' => $r['next_before_id']]);
    }

    public function preview(Request $request, AssetDisposalService $svc): JsonResponse
    {
        $d = $request->validate([
            'asset_id' => ['required', 'integer'], 'tanggal' => ['required', 'date'],
            'nilai_pelepasan' => ['sometimes', 'regex:/^\d{1,15}(\.\d{1,4})?$/'], 'akun_penerimaan_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $svc->preview((int) $d['asset_id'], $d['tanggal'], $d['nilai_pelepasan'] ?? '0', isset($d['akun_penerimaan_id']) ? (int) $d['akun_penerimaan_id'] : null)]);
    }

    public function show(int $id, AssetDisposalService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->present($svc->find($id))]);
    }

    public function store(Request $request, AssetDisposalService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->present($svc->create($request->validate($svc->rules()), $request->user()))], 201);
    }

    public function update(Request $request, int $id, AssetDisposalService $svc): JsonResponse
    {
        $rules = array_map(fn ($r) => array_merge(['sometimes'], array_values(array_filter($r, fn ($x) => $x !== 'required' && $x !== 'sometimes'))), $svc->rules());

        return response()->json(['data' => $svc->present($svc->update($id, $request->validate($rules), $request->user()))]);
    }

    public function destroy(Request $request, int $id, AssetDisposalService $svc): JsonResponse
    {
        $svc->delete($id, $request->user());

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }
}
