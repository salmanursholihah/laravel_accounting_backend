<?php

namespace App\Modules\CashBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\History\BankHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Histori Bank per rekening: mutasi dari semua modul dengan saldo berjalan. */
class BankHistoryController extends Controller
{
    public function show(Request $request, int $id, BankHistoryService $service): JsonResponse
    {
        $q = $request->validate([
            'dari' => ['required', 'date'], 'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'cursor' => ['sometimes', 'string', 'max:60'], 'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);

        return response()->json(['data' => $service->history(
            $id,
            $request->date('dari')->toDateString(),
            $request->date('sampai')->toDateString(),
            $q['cursor'] ?? null,
            (int) ($q['limit'] ?? 100),
        )]);
    }
}
