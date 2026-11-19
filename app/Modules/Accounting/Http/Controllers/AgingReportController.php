<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Reports\AgingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Umur Piutang (fitur umur_piutang) dan Umur Utang (fitur umur_utang), 6C. */
class AgingReportController extends Controller
{
    public function receivables(Request $request, AgingReportService $svc): JsonResponse
    {
        return $this->run($request, $svc, 'piutang');
    }

    public function payables(Request $request, AgingReportService $svc): JsonResponse
    {
        return $this->run($request, $svc, 'utang');
    }

    private function run(Request $request, AgingReportService $svc, string $sisi): JsonResponse
    {
        $d = $request->validate([
            'per_tanggal' => ['sometimes', 'date'],
            'mode' => ['sometimes', 'in:ringkas,rinci'],
            'kontak_kode' => ['sometimes', 'string', 'max:30'],
        ]);

        $perTanggal = isset($d['per_tanggal']) ? $request->date('per_tanggal')->toDateString() : now()->toDateString();

        return response()->json(['data' => $svc->aging($sisi, $perTanggal, $d['mode'] ?? 'ringkas', $d['kontak_kode'] ?? null)]);
    }
}
