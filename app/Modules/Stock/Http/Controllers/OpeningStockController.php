<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Stock\OpeningStock\OpeningStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saldo awal stok. Posting dan Void lewat /opening-balances/post dan /opening-balances/void (satu jurnal saldo awal). */
class OpeningStockController extends Controller
{
    public function index(OpeningStockService $svc): JsonResponse
    {
        $rows = $svc->rows();

        return response()->json(['data' => [
            'status' => $svc->isPosted() ? 'posted' : 'draft',
            'total_nilai' => Money::round(Money::sum($rows->pluck('nilai')->all())),
            'rows' => $rows,
        ]]);
    }

    public function update(Request $request, OpeningStockService $svc): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:5000'],
            'rows.*.item_id' => ['required', 'integer'],
            'rows.*.warehouse_id' => ['required', 'integer'],
            'rows.*.qty' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'rows.*.harga_satuan' => ['required', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
        ]);

        return response()->json(['data' => ['baris' => $svc->save($data['rows'], $request->user())]]);
    }
}
