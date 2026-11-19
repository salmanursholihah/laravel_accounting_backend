<?php

namespace App\Modules\CashBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Models\BankReconciliation;
use App\Modules\CashBank\Reconciliation\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rekonsiliasi Bank (UC-27): sesi, pencocokan otomatis/manual, transaksi baru dari baris koran, selesai, buka kembali. */
class BankReconciliationController extends Controller
{
    public function index(Request $request, BankReconciliationService $svc): JsonResponse
    {
        $request->validate(['bank_account_id' => ['sometimes', 'integer'], 'status' => ['sometimes', 'in:berjalan,selesai,dibuka_kembali'], 'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $limit = (int) $request->input('limit', 50);

        $q = BankReconciliation::query()->orderByDesc('id');
        foreach (['bank_account_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->integer($f));
            }
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('before_id')) {
            $q->where('id', '<', $request->integer('before_id'));
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json(['data' => $rows->map(fn ($r) => $svc->figures($r))->values(), 'next_before_id' => $more ? $rows->last()->id : null]);
    }

    public function store(Request $request, BankReconciliationService $svc): JsonResponse
    {
        $data = $request->validate(['bank_statement_id' => ['required', 'integer']]);
        $rec = $svc->create((int) $data['bank_statement_id'], $request->user());

        return response()->json(['data' => $svc->figures($rec)], 201);
    }

    public function show(int $id, BankReconciliationService $svc): JsonResponse
    {
        $rec = BankReconciliation::query()->findOrFail($id);

        return response()->json(['data' => $svc->figures($rec) + $svc->worklist($id)]);
    }

    public function destroy(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $svc->delete($id, $request->user());

        return response()->json(null, 204);
    }

    public function suggestions(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $d = $request->validate(['toleransi_hari' => ['sometimes', 'integer', 'between:0,30']]);

        return response()->json(['data' => $svc->suggest($id, isset($d['toleransi_hari']) ? (int) $d['toleransi_hari'] : null)]);
    }

    public function autoMatch(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $d = $request->validate(['toleransi_hari' => ['sometimes', 'integer', 'between:0,30']]);

        return response()->json(['data' => $svc->autoMatch($id, isset($d['toleransi_hari']) ? (int) $d['toleransi_hari'] : null, $request->user())]);
    }

    public function match(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $d = $request->validate(['bank_statement_line_id' => ['required', 'integer'], 'journal_line_id' => ['required', 'integer']]);

        return response()->json(['data' => $svc->matchManual($id, (int) $d['bank_statement_line_id'], (int) $d['journal_line_id'], $request->user())], 201);
    }

    public function unmatch(Request $request, int $id, int $itemId, BankReconciliationService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->unmatch($id, $itemId, $request->user())]);
    }

    public function transaction(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $d = $request->validate(['bank_statement_line_id' => ['required', 'integer'], 'account_id' => ['required', 'integer'], 'memo' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $svc->createTransaction($id, (int) $d['bank_statement_line_id'], (int) $d['account_id'], $d['memo'] ?? null, $request->user())], 201);
    }

    public function complete(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->figures($svc->complete($id, $request->user()))]);
    }

    public function reopen(Request $request, int $id, BankReconciliationService $svc): JsonResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $svc->figures($svc->reopen($id, $d['alasan'], $request->user()))]);
    }
}
