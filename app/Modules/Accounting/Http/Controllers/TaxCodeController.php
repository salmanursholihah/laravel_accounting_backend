<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Accounting\Tax\TaxCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxCodeController extends Controller
{
    /** ?tanggal=YYYY-MM-DD hanya versi yang berlaku pada tanggal itu; ?aktif=1 hanya yang aktif. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['tanggal' => ['sometimes', 'date'], 'jenis' => ['sometimes', 'in:ppn,ppnbm,pph']]);

        $q = TaxCode::query()->orderBy('kode')->orderBy('berlaku_dari');
        if ($request->filled('tanggal')) {
            $t = $request->date('tanggal')->toDateString();
            $q->where('berlaku_dari', '<=', $t)->where(fn ($w) => $w->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $t));
        }
        if ($request->boolean('aktif')) {
            $q->where('is_aktif', true);
        }
        if ($request->filled('jenis')) {
            $q->where('jenis', $request->input('jenis'));
        }

        return response()->json(['data' => $q->get()->map(fn (TaxCode $t) => $this->present($t))->values()]);
    }

    public function store(Request $request, TaxCodeService $taxes): JsonResponse
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z\-._]+$/'],
            'nama' => ['required', 'string', 'max:120'],
            'jenis' => ['required', 'in:ppn,ppnbm,pph'],
            'tarif' => ['required', 'numeric', 'between:0,1'],
            'dpp_pembilang' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'dpp_penyebut' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'akun_penjualan_id' => ['nullable', 'integer'],
            'akun_pembelian_id' => ['nullable', 'integer'],
            'berlaku_dari' => ['required', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_dari'],
            'is_aktif' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->present($taxes->create($data, $request->user()))], 201);
    }

    public function update(Request $request, int $id, TaxCodeService $taxes): JsonResponse
    {
        $tax = TaxCode::query()->findOrFail($id);

        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:120'],
            'tarif' => ['sometimes', 'numeric', 'between:0,1'],
            'dpp_pembilang' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'dpp_penyebut' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'akun_penjualan_id' => ['sometimes', 'nullable', 'integer'],
            'akun_pembelian_id' => ['sometimes', 'nullable', 'integer'],
            'berlaku_dari' => ['sometimes', 'date'],
            'berlaku_sampai' => ['sometimes', 'nullable', 'date'],
            'is_aktif' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->present($taxes->update($tax, $data, $request->user()))]);
    }

    public function destroy(int $id, TaxCodeService $taxes): JsonResponse
    {
        $taxes->delete(TaxCode::query()->findOrFail($id));

        return response()->json(['message' => 'Kode pajak dihapus.']);
    }

    private function present(TaxCode $t): array
    {
        return [
            'id' => $t->id, 'kode' => $t->kode, 'nama' => $t->nama, 'jenis' => $t->jenis,
            'tarif' => (string) $t->tarif, 'dpp_pembilang' => $t->dpp_pembilang, 'dpp_penyebut' => $t->dpp_penyebut,
            'akun_penjualan_id' => $t->akun_penjualan_id, 'akun_pembelian_id' => $t->akun_pembelian_id,
            'berlaku_dari' => $t->berlaku_dari?->toDateString(), 'berlaku_sampai' => $t->berlaku_sampai?->toDateString(),
            'is_aktif' => $t->is_aktif,
        ];
    }
}
