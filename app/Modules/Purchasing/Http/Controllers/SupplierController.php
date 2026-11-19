<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Suppliers\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pemasok (UC-38). Hak: fitur 'pemasok' (Purchasing mengelola; ekstensi 1:1 dari contacts). */
class SupplierController extends Controller
{
    public function index(Request $request, SupplierService $svc): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:50'], 'aktif' => ['sometimes', 'boolean'], 'after_id' => ['sometimes', 'integer'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);

        return response()->json($svc->list($request->input('q'), $request->has('aktif') ? $request->boolean('aktif') : null, (int) $request->input('limit', 50), $request->integer('after_id') ?: null));
    }

    public function show(int $id, SupplierService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->find($id)]);
    }

    public function store(Request $request, SupplierService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->create($request->validate($this->rules(true)), $request->user())], 201);
    }

    public function update(Request $request, int $id, SupplierService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->update($id, $request->validate($this->rules(false)), $request->user())]);
    }

    private function rules(bool $create): array
    {
        return [
            'contact_id' => $create ? ['nullable', 'integer'] : ['prohibited'],
            'kode' => ['sometimes', 'string', 'max:30'], 'nama' => ['sometimes', 'string', 'max:190'],
            'alamat' => ['nullable', 'string'], 'kota' => ['nullable', 'string', 'max:100'], 'telepon' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'], 'npwp' => ['nullable', 'string', 'max:25'], 'is_aktif' => ['sometimes', 'boolean'],
            'supplier_category_id' => ['nullable', 'integer'], 'payment_term_id' => ['nullable', 'integer'],
            'akun_utang_override_id' => ['nullable', 'integer'], 'no_rekening' => ['nullable', 'string', 'max:60'],
        ];
    }
}
