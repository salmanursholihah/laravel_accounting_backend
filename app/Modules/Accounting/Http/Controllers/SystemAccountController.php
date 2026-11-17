<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemAccountController extends Controller
{
    public function index(SystemAccountService $service): JsonResponse
    {
        return response()->json(['data' => $service->all()]);
    }

    public function update(Request $request, string $key, SystemAccountService $service): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $service->map($key, (int) $data['account_id'], (int) $request->user()->getKey());

        return response()->json(['data' => $service->all()]);
    }
}
