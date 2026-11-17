<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Ledger\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** UC-16 Saldo awal. */
class OpeningBalanceController extends Controller
{
    public function index(OpeningBalanceService $svc): JsonResponse
    {
        $active = $svc->activeJournal();

        return response()->json(['data' => [
            'status' => $active ? 'posted' : 'draft',
            'journal_id' => $active?->id,
            'nomor' => $active?->nomor,
            'rows' => $svc->rows(),
        ]]);
    }

    public function update(Request $request, OpeningBalanceService $svc): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['present', 'array', 'max:2000'],
            'rows.*.account_id' => ['required', 'integer'],
            'rows.*.contact_id' => ['nullable', 'integer'],
            'rows.*.jumlah' => ['required', 'regex:/^-?\d+(\.\d{1,4})?$/'],
        ]);

        return response()->json(['data' => ['baris' => $svc->save($data['rows'], $request->user())]]);
    }

    public function post(Request $request, OpeningBalanceService $svc): JsonResponse
    {
        $j = $svc->post($request->user());

        return response()->json(['data' => ['journal_id' => $j->id, 'nomor' => $j->nomor]], 201);
    }

    public function void(Request $request, OpeningBalanceService $svc): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:400']]);
        $rev = $svc->void($data['alasan'], $request->user());

        return response()->json(['data' => ['journal_id' => $rev->id, 'nomor' => $rev->nomor]]);
    }
}
