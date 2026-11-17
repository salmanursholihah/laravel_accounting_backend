<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Ledger\AccountBalanceService;
use App\Modules\Accounting\Ledger\AccountHistoryService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Histori Akun (UC-22) dan Neraca Saldo. */
class LedgerReportController extends Controller
{
    public function accountHistory(Request $request, AccountHistoryService $svc): JsonResponse
    {
        $d = $request->validate([
            'account_id' => ['required', 'integer'],
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'cursor' => ['sometimes', 'string', 'max:40'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
            'termasuk_penutup' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $svc->history(
            (int) $d['account_id'],
            $request->date('dari')->toDateString(),
            $request->date('sampai')->toDateString(),
            $d['cursor'] ?? null,
            (int) ($d['limit'] ?? 100),
            $request->boolean('termasuk_penutup'),
        )]);
    }

    public function trialBalance(Request $request, AccountBalanceService $svc): JsonResponse
    {
        $d = $request->validate([
            'period_id' => ['required', 'integer'],
            'termasuk_penutup' => ['sometimes', 'boolean'],
        ]);
        $period = AccountingPeriod::query()->findOrFail((int) $d['period_id']); // terfilter perusahaan aktif

        return response()->json(['data' => $svc->trialBalance($period, $request->boolean('termasuk_penutup'))]);
    }
}
