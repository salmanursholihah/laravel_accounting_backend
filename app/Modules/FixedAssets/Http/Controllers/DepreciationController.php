<?php

namespace App\Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Depreciation\DepreciationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepreciationController extends Controller
{
    public function preview(int $id, DepreciationService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->preview($id)]);
    }

    public function run(Request $request, int $id, DepreciationService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->run($id, $request->user())], 201);
    }
}
