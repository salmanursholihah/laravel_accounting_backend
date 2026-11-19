<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Stock\Recalc\StockRecalcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hitung ulang HPP: daftar tertunda (lihat) dan jalankan sekarang (hak khusus jurnal_koreksi: Owner dan Akuntan). */
class StockRecalcController extends Controller
{
    public function pending(StockRecalcService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->pending()]);
    }

    public function run(Request $request, StockRecalcService $svc, PermissionChecker $perm): JsonResponse
    {
        if (! $perm->can($request->user(), 'khusus', 'jurnal_koreksi')) {
            return response()->json(['message' => 'Anda tidak berhak menjalankan hitung ulang HPP.', 'code' => 'forbidden'], 403);
        }
        $d = $request->validate(['item_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer']]);

        return response()->json(['data' => $svc->run((int) $d['item_id'], (int) $d['warehouse_id'], (int) $request->user()->getKey())]);
    }
}
