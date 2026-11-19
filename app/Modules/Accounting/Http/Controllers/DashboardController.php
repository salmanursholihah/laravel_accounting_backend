<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dashboard per izin user (6D). Fitur dashboard (lihat) dimiliki semua role. */
class DashboardController extends Controller
{
    public function show(Request $request, DashboardService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->build($request->user())]);
    }
}
