<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Numbering\NumberingService;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NumberingController extends Controller
{
    public function index(CurrentCompany $current, NumberingService $numbering): JsonResponse
    {
        $numbering->seedDefaults();

        $rows = DB::table('number_sequences')
            ->where('company_id', $current->id())
            ->where('period_key', '-')
            ->get()
            ->keyBy('doc_type');

        $data = [];
        foreach (config('numbering.types') as $docType => $meta) {
            $row = $rows[$docType] ?? null;
            $data[] = [
                'doc_type' => $docType,
                'label' => $meta['label'],
                'prefix' => $row->prefix ?? $meta['prefix'],
                'format' => $row->format ?? config('numbering.default_format'),
                'reset' => $row->reset ?? config('numbering.default_reset'),
                'contoh_berikut' => $numbering->preview($docType),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $docType, NumberingService $numbering): JsonResponse
    {
        if (! array_key_exists($docType, config('numbering.types'))) {
            return response()->json(['message' => 'Jenis dokumen tidak dikenal.', 'code' => 'unknown_doc_type'], 404);
        }

        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'format' => ['required', 'string', 'max:100'],
            'reset' => ['required', 'in:none,yearly,monthly'],
        ]);

        if ($error = NumberingService::formatError($data['format'], $data['reset'])) {
            return response()->json(['message' => $error, 'code' => 'invalid_format'], 422);
        }

        $numbering->configure($docType, $data['prefix'], $data['format'], $data['reset']);

        return response()->json(['data' => $data + ['doc_type' => $docType, 'contoh_berikut' => $numbering->preview($docType)]]);
    }
}
