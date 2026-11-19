<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Invoices\PurchaseInvoiceService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRUD Draft Faktur Pembelian. Posting/Void: POST /documents/purchase_invoice/{id}/post|void (hak dicek orkestrator per dokumen). */
class PurchaseInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:draft,pending_approval,posted,void'], 'status_pembayaran' => ['sometimes', 'in:belum,sebagian,lunas'],
            'contact_id' => ['sometimes', 'integer'], 'dari' => ['sometimes', 'date'], 'sampai' => ['sometimes', 'date', 'after_or_equal:dari'],
            'q' => ['sometimes', 'string', 'max:50'], 'no_faktur_pemasok' => ['sometimes', 'string', 'max:80'], 'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $limit = (int) $request->input('limit', 50);
        $q = PurchaseInvoice::query()->orderByDesc('id');
        foreach (['status', 'status_pembayaran', 'contact_id'] as $c) {
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
        if ($request->filled('no_faktur_pemasok')) {
            $q->where('no_faktur_pemasok', $request->input('no_faktur_pemasok'));
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

        return response()->json(['data' => $rows->map(fn ($d) => $this->present($d, false))->values(), 'next_before_id' => $more ? $rows->last()->id : null]);
    }

    public function show(int $id, PurchaseInvoiceService $svc): JsonResponse
    {
        $d = $svc->find($id);

        return response()->json(['data' => $this->present($d, true), 'peringatan' => $svc->warnings($d)]);
    }

    public function store(Request $request, PurchaseInvoiceService $svc): JsonResponse
    {
        $d = $svc->create($request->validate($svc->rules()), $request->user());

        return response()->json(['data' => $this->present($d, true), 'peringatan' => $svc->warnings($d)], 201);
    }

    public function update(Request $request, int $id, PurchaseInvoiceService $svc): JsonResponse
    {
        $d = $svc->update($id, $request->validate($svc->rules()), $request->user());

        return response()->json(['data' => $this->present($d, true), 'peringatan' => $svc->warnings($d)]);
    }

    public function destroy(int $id, PurchaseInvoiceService $svc): JsonResponse
    {
        $svc->delete($id);

        return response()->json(null, 204);
    }

    private function present(PurchaseInvoice $d, bool $withLines): array
    {
        $o = [
            'id' => (int) $d->id, 'public_id' => $d->public_id, 'nomor' => $d->nomor, 'tanggal' => $d->tanggal->toDateString(), 'status' => $d->status,
            'contact_id' => (int) $d->contact_id, 'jatuh_tempo' => $d->jatuh_tempo?->toDateString(), 'payment_term_id' => $d->payment_term_id,
            'harga_termasuk_pajak' => (bool) $d->harga_termasuk_pajak, 'subtotal' => (string) $d->subtotal, 'diskon' => (string) $d->diskon,
            'dpp' => (string) $d->dpp, 'total_pajak' => (string) $d->total_pajak, 'no_faktur_pemasok' => $d->no_faktur_pemasok, 'no_faktur_pajak' => $d->no_faktur_pajak, 'dapat_dikreditkan' => (bool) $d->dapat_dikreditkan, 'total' => (string) $d->total,
            'total_dibayar' => (string) $d->total_dibayar, 'sisa_tagihan' => (string) $d->sisa_tagihan, 'status_pembayaran' => $d->status_pembayaran,
            'memo' => $d->memo, 'journal_id' => $d->journal_id, 'version' => (int) $d->version, 'alasan_void' => $d->alasan_void,
        ];
        if (! $withLines) {
            return $o;
        }

        return $o + ['lines' => $d->lines->map(fn ($l) => [
            'line_no' => (int) $l->line_no, 'item_id' => $l->item_id, 'account_id' => $l->account_id, 'deskripsi' => $l->deskripsi,
            'qty' => (string) $l->qty, 'unit_id' => $l->unit_id, 'harga' => (string) $l->harga, 'diskon_persen' => (string) $l->diskon_persen,
            'tax_code_id' => $l->tax_code_id, 'jumlah' => (string) $l->jumlah, 'warehouse_id' => $l->warehouse_id,
        ])->values()];
    }
}
