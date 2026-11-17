<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\FiscalYear;
use App\Modules\Accounting\Periods\PeriodService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function index(): JsonResponse
    {
        $years = FiscalYear::query()->with('periods')->orderBy('tahun')->get()->map(fn ($fy) => [
            'id' => $fy->id,
            'tahun' => $fy->tahun,
            'mulai' => $fy->tanggal_mulai->toDateString(),
            'selesai' => $fy->tanggal_selesai->toDateString(),
            'status' => $fy->status,
            'periode' => $fy->periods->map(fn ($p) => [
                'id' => $p->id,
                'bulan' => $p->bulan,
                'mulai' => $p->tanggal_mulai->toDateString(),
                'selesai' => $p->tanggal_selesai->toDateString(),
                'status' => $p->status,
            ])->values(),
        ]);

        return response()->json(['data' => $years]);
    }

    public function store(Request $request, PeriodService $periods): JsonResponse
    {
        $data = $request->validate(['tahun' => ['required', 'integer', 'between:2000,2100']]);

        try {
            $fy = $periods->createFiscalYear((int) $data['tahun']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'fiscal_year_invalid'], 422);
        }

        return response()->json(['data' => ['id' => $fy->id, 'tahun' => $fy->tahun]], 201);
    }

    /** UC-14: buka kunci periode (hanya Owner lewat hak khusus). */
    public function reopen(Request $request, int $id, PeriodService $periods): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        $period = AccountingPeriod::query()->findOrFail($id); // otomatis terfilter perusahaan aktif

        try {
            $periods->reopen($period, $request->user(), $data['alasan']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'reopen_rejected'], 422);
        }

        return response()->json(['data' => ['id' => $period->id, 'status' => 'open']]);
    }
}
