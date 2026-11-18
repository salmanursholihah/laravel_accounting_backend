<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Customers\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pelanggan (UC-28). Hak: fitur 'pelanggan' (Kasir boleh membuat cepat dari form faktur). */
class CustomerController extends Controller
{
    public function index(Request $request, CustomerService $svc): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:50'], 'aktif' => ['sometimes', 'boolean'], 'after_id' => ['sometimes', 'integer'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);

        return response()->json($svc->list($request->input('q'), $request->has('aktif') ? $request->boolean('aktif') : null, (int) $request->input('limit', 50), $request->integer('after_id') ?: null));
    }

    public function show(int $id, CustomerService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->find($id)]);
    }

    public function store(Request $request, CustomerService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->create($request->validate($this->rules(true)), $request->user())], 201);
    }

    public function update(Request $request, int $id, CustomerService $svc): JsonResponse
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
            'customer_category_id' => ['nullable', 'integer'], 'limit_kredit' => ['sometimes', 'numeric', 'min:0'],
            'payment_term_id' => ['nullable', 'integer'], 'level_harga' => ['sometimes', 'integer', 'between:1,10'],
            'salesperson_id' => ['nullable', 'integer'], 'akun_piutang_override_id' => ['nullable', 'integer'],
        ];
    }
}
