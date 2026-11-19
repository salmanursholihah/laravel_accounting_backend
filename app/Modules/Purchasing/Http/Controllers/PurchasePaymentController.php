<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchasePayment;
use App\Modules\Purchasing\Payments\PurchasePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRUD Draft Pembayaran Pembelian + daftar faktur terbuka (P4). Posting/Void: POST /documents/purchase_payment/{id}/post|void. */
class PurchasePaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:draft,pending_approval,posted,void'], 'contact_id' => ['sometimes', 'integer'],
            'dari' => ['sometimes', 'date'], 'sampai' => ['sometimes', 'date', 'after_or_equal:dari'], 'q' => ['sometimes', 'string', 'max:50'],
            'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $limit = (int) $request->input('limit', 50);
        $q = PurchasePayment::query()->orderByDesc('id');
        foreach (['status', 'contact_id'] as $c) {
            if ($request->filled($c)) {
                $q->where($c, $request->input($c));
            }
        }
        if ($request->filled('dari')) {
            $q->where('tanggal', '>=', $request->date('dari')->toDateString());
        }
        if ($request->filled('sampai')) {
            $q->where('tanggal', '<=', $request->date('sampai')->toDateString());
        }
        if ($request->filled('q')) {
            $q->where('nomor', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('q')) . '%');
        }
        if ($request->filled('before_id')) {
            $q->where('id', '<', $request->integer('before_id'));
        }

        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json(['data' => $rows->map(fn ($d) => $this->present($d))->values(), 'next_before_id' => $more ? $rows->last()->id : null]);
    }

    public function show(int $id, PurchasePaymentService $s): JsonResponse
    {
        return response()->json(['data' => $this->present($s->find($id), true)]);
    }

    public function store(Request $request, PurchasePaymentService $s): JsonResponse
    {
        return response()->json(['data' => $this->present($s->create($request->validate($s->rules()), $request->user()), true)], 201);
    }

    public function update(Request $request, int $id, PurchasePaymentService $s): JsonResponse
    {
        return response()->json(['data' => $this->present($s->update($id, $request->validate($s->rules()), $request->user()), true)]);
    }

    public function destroy(int $id, PurchasePaymentService $s): JsonResponse
    {
        $s->delete($id);

        return response()->json(null, 204);
    }

    /** Faktur terbuka satu pemasok; dengan nominal, sekaligus saran alokasi otomatis (jatuh tempo terlama dulu). */
    public function openInvoices(Request $request, PurchasePaymentService $s): JsonResponse
    {
        $q = $request->validate([
            'contact_id' => ['required', 'integer'], 'nominal' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'], 'tanggal' => ['sometimes', 'date'],
        ]);

        return response()->json(['data' => $s->openInvoices((int) $q['contact_id'], $q['nominal'] ?? null, isset($q['tanggal']) ? $request->date('tanggal')->toDateString() : null)]);
    }

    private function present(PurchasePayment $d, bool $withAllocations = false): array
    {
        $out = [
            'id' => (int) $d->id, 'public_id' => $d->public_id, 'nomor' => $d->nomor, 'tanggal' => $d->tanggal->toDateString(), 'status' => $d->status,
            'contact_id' => $d->contact_id, 'rekening_id' => $d->rekening_pembayaran_id, 'nominal' => (string) $d->nominal, 'memo' => $d->memo,
            'journal_id' => $d->journal_id, 'version' => (int) $d->version, 'alasan_void' => $d->alasan_void,
        ];

        return $withAllocations ? $out + ['alokasi' => $d->allocations->map(fn ($a) => [
            'purchase_invoice_id' => (int) $a->purchase_invoice_id, 'nominal' => (string) $a->nominal_dialokasikan,
        ])->values()] : $out;
    }
}
