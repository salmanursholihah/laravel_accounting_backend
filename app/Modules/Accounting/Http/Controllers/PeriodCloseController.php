<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\Close\FiscalYearCloseService;
use App\Modules\Accounting\Periods\Close\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proses Akhir Bulan dan Tutup Tahun (ALG-11, UC-11 s.d. UC-14).
 * Hak: lihat checklist = proses_akhir_bulan:view; tutup = hak khusus tutup_periode (Owner, Akuntan);
 * buka kembali tahun = hak khusus buka_kunci_periode (Owner). Aturannya dijaga service, bukan hanya route.
 */
class PeriodCloseController extends Controller
{
    public function check(int $id, PeriodCloseService $service): JsonResponse
    {
        return response()->json(['data' => $service->check(AccountingPeriod::query()->findOrFail($id))]);
    }

    public function logs(int $id, PeriodCloseService $service): JsonResponse
    {
        return response()->json(['data' => $service->logs(AccountingPeriod::query()->findOrFail($id))]);
    }

    public function close(Request $request, int $id, PeriodCloseService $service): JsonResponse
    {
        $data = $request->validate(['konfirmasi' => ['sometimes', 'boolean']]);
        $hasil = $service->close($id, $request->user(), (bool) ($data['konfirmasi'] ?? false));

        return response()->json(['data' => ['id' => $id, 'status' => 'closed', 'hasil' => $hasil]]);
    }

    public function yearPreview(int $id, FiscalYearCloseService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($id)]);
    }

    public function yearClose(Request $request, int $id, FiscalYearCloseService $service): JsonResponse
    {
        $data = $request->validate(['konfirmasi' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $service->close($id, $request->user(), (bool) ($data['konfirmasi'] ?? false))]);
    }

    public function yearReopen(Request $request, int $id, FiscalYearCloseService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $service->reopen($id, $request->user(), $data['alasan'])]);
    }
}
