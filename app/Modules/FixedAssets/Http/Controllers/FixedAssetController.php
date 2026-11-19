<?php

namespace App\Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Assets\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function index(Request $request, FixedAssetService $svc): JsonResponse
    {
        $f = $request->validate(['status' => ['sometimes', 'in:aktif,dilepas'], 'category_id' => ['sometimes', 'integer'], 'q' => ['sometimes', 'string', 'max:100'], 'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $r = $svc->list($f);

        return response()->json(['data' => $r['data'], 'next_before_id' => $r['next_before_id']]);
    }

    public function store(Request $request, FixedAssetService $svc): JsonResponse
    {
        $d = $request->validate([
            'kode' => ['required', 'string', 'max:30'], 'nama' => ['required', 'string', 'max:150'], 'category_id' => ['required', 'integer'],
            'tanggal_perolehan' => ['required', 'date'], 'harga_perolehan' => ['required'], 'nilai_residu' => ['sometimes'],
            'umur_bulan' => ['sometimes', 'integer', 'between:1,600'], 'keterangan' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $svc->create($d, $request->user())], 201);
    }

    public function show(int $id, FixedAssetService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->find($id)]);
    }

    public function update(Request $request, int $id, FixedAssetService $svc): JsonResponse
    {
        $d = $request->validate([
            'nama' => ['sometimes', 'string', 'max:150'], 'category_id' => ['sometimes', 'integer'], 'tanggal_perolehan' => ['sometimes', 'date'],
            'harga_perolehan' => ['sometimes'], 'nilai_residu' => ['sometimes'], 'umur_bulan' => ['sometimes', 'integer', 'between:1,600'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $svc->update($id, $d, $request->user())]);
    }

    public function destroy(Request $request, int $id, FixedAssetService $svc): JsonResponse
    {
        $svc->delete($id, $request->user());

        return response()->json(null, 204);
    }

    public function schedule(int $id, FixedAssetService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->schedule($id)]);
    }
}
