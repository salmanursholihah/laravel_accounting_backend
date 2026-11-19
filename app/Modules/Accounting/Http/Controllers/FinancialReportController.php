<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Reports\FinancialStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Neraca, Laba Rugi, Buku Besar (6B). Izin: fitur laporan_keuangan (Owner, Admin, Akuntan). */
class FinancialReportController extends Controller
{
    public function balanceSheet(Request $request, FinancialStatementService $svc): JsonResponse
    {
        $d = $request->validate([
            'period_id' => ['required', 'integer'],
            'mode' => ['sometimes', 'in:ringkas,rinci'],
            'bandingkan' => ['sometimes', 'in:sebelumnya,tahun_lalu'],
        ]);
        $period = AccountingPeriod::query()->findOrFail((int) $d['period_id']); // terfilter perusahaan aktif

        return response()->json(['data' => $svc->balanceSheet($period, $d['mode'] ?? 'rinci', $d['bandingkan'] ?? null)]);
    }

    public function incomeStatement(Request $request, FinancialStatementService $svc): JsonResponse
    {
        $d = $request->validate([
            'dari_period_id' => ['required', 'integer'],
            'sampai_period_id' => ['sometimes', 'integer'],
            'mode' => ['sometimes', 'in:ringkas,rinci'],
            'bandingkan' => ['sometimes', 'in:sebelumnya,tahun_lalu'],
        ]);
        $dari = AccountingPeriod::query()->findOrFail((int) $d['dari_period_id']);
        $sampai = isset($d['sampai_period_id']) ? AccountingPeriod::query()->findOrFail((int) $d['sampai_period_id']) : $dari;

        if ($sampai->tanggal_selesai->lt($dari->tanggal_mulai)) {
            throw ValidationException::withMessages(['sampai_period_id' => 'Periode akhir tidak boleh lebih awal dari periode awal.']);
        }

        return response()->json(['data' => $svc->incomeStatement($dari, $sampai, $d['mode'] ?? 'rinci', $d['bandingkan'] ?? null)]);
    }

    public function generalLedger(Request $request, FinancialStatementService $svc): JsonResponse
    {
        $d = $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'cursor' => ['sometimes', 'string', 'max:20'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
            'termasuk_penutup' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $svc->generalLedger(
            $request->date('dari')->toDateString(),
            $request->date('sampai')->toDateString(),
            $d['cursor'] ?? null,
            (int) ($d['limit'] ?? 50),
            $request->boolean('termasuk_penutup'),
        )]);
    }
}
