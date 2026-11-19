<?php

namespace App\Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Categories\AssetCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function index(Request $request, AssetCategoryService $svc): JsonResponse
    {
        $request->validate(['is_aktif' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $svc->list($request->has('is_aktif') ? $request->boolean('is_aktif') : null)]);
    }

    public function store(Request $request, AssetCategoryService $svc): JsonResponse
    {
        $d = $request->validate([
            'kode' => ['required', 'string', 'max:30'], 'nama' => ['required', 'string', 'max:150'],
            'akun_aset_id' => ['required', 'integer'], 'akun_akumulasi_id' => ['required', 'integer'], 'akun_beban_id' => ['required', 'integer'],
            'umur_default_bulan' => ['nullable', 'integer', 'between:1,600'],
        ]);

        return response()->json(['data' => $svc->create($d, $request->user())], 201);
    }

    public function show(int $id, AssetCategoryService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->find($id)]);
    }

    public function update(Request $request, int $id, AssetCategoryService $svc): JsonResponse
    {
        $d = $request->validate([
            'nama' => ['sometimes', 'string', 'max:150'], 'is_aktif' => ['sometimes', 'boolean'],
            'akun_aset_id' => ['sometimes', 'integer'], 'akun_akumulasi_id' => ['sometimes', 'integer'], 'akun_beban_id' => ['sometimes', 'integer'],
            'umur_default_bulan' => ['sometimes', 'nullable', 'integer', 'between:1,600'],
        ]);

        return response()->json(['data' => $svc->update($id, $d, $request->user())]);
    }

    public function destroy(Request $request, int $id, AssetCategoryService $svc): JsonResponse
    {
        $svc->delete($id, $request->user());

        return response()->json(null, 204);
    }
}
