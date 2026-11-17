<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\PaymentTerms\PaymentTermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = PaymentTerm::query()->orderBy('jumlah_hari')->orderBy('nama');
        if ($request->boolean('aktif')) {
            $q->where('is_aktif', true);
        }

        return response()->json(['data' => $q->get()->map(fn (PaymentTerm $t) => $this->present($t))->values()]);
    }

    public function store(Request $request, PaymentTermService $terms): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:80'],
            'jumlah_hari' => ['required', 'integer', 'between:0,3650'],
            'diskon_persen' => ['sometimes', 'numeric', 'between:0,100'],
            'diskon_hari' => ['sometimes', 'integer', 'between:0,3650'],
        ]);

        return response()->json(['data' => $this->present($terms->create($data, $request->user()))], 201);
    }

    public function update(Request $request, int $id, PaymentTermService $terms): JsonResponse
    {
        $term = PaymentTerm::query()->findOrFail($id);

        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:80'],
            'jumlah_hari' => ['sometimes', 'integer', 'between:0,3650'],
            'diskon_persen' => ['sometimes', 'numeric', 'between:0,100'],
            'diskon_hari' => ['sometimes', 'integer', 'between:0,3650'],
            'is_aktif' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->present($terms->update($term, $data, $request->user()))]);
    }

    public function destroy(int $id, PaymentTermService $terms): JsonResponse
    {
        $terms->delete(PaymentTerm::query()->findOrFail($id));

        return response()->json(['message' => 'Syarat pembayaran dihapus.']);
    }

    private function present(PaymentTerm $t): array
    {
        return [
            'id' => $t->id, 'nama' => $t->nama, 'jumlah_hari' => $t->jumlah_hari,
            'diskon_persen' => (string) $t->diskon_persen, 'diskon_hari' => $t->diskon_hari, 'is_aktif' => $t->is_aktif,
        ];
    }
}
