<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stock\Adjustments\StockAdjustmentService;
use App\Modules\Stock\Models\StockAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRUD Draft Penyesuaian Persediaan. Posting/Void lewat /documents/stock_adjustment/{id}/post|void. */
class StockAdjustmentController extends Controller
{
    public function __construct(private StockAdjustmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:draft,pending_approval,posted,void'], 'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $limit = (int) $request->input('limit', 50);
        $q = StockAdjustment::query()->withCount('lines')->orderByDesc('id');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('before_id')) {
            $q->where('id', '<', $request->integer('before_id'));
        }
        $rows = $q->limit($limit + 1)->get();

        return response()->json(['data' => $rows->take($limit)->map(fn ($d) => $this->present($d, false))->values(), 'next_before_id' => $rows->count() > $limit ? $rows->take($limit)->last()->id : null]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->service->find($id), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->service->create($request->validate($this->service->rules()), $request->user()), true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->service->update($id, $request->validate($this->service->rules()), $request->user()), true)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($id, $request->user());

        return response()->json(null, 204);
    }

    private function present(StockAdjustment $d, bool $lines): array
    {
        $o = [
            'id' => (int) $d->id, 'public_id' => $d->public_id, 'nomor' => $d->nomor, 'tanggal' => $d->tanggal->toDateString(), 'status' => $d->status,
            'warehouse_id' => (int) $d->warehouse_id, 'alasan' => $d->alasan, 'memo' => $d->memo, 'journal_id' => $d->journal_id, 'version' => (int) $d->version,
            'diposting_pada' => $d->diposting_pada?->toIso8601String(), 'void_pada' => $d->void_pada?->toIso8601String(), 'alasan_void' => $d->alasan_void,
        ];

        return $lines
            ? $o + ['lines' => $d->lines->map(fn ($l) => ['line_no' => (int) $l->line_no, 'item_id' => (int) $l->item_id, 'deskripsi' => $l->deskripsi, 'qty_selisih' => (string) $l->qty_selisih, 'harga_satuan' => $l->harga_satuan === null ? null : (string) $l->harga_satuan, 'nilai' => (string) $l->nilai])->values()]
            : $o + ['jumlah_baris' => (int) ($d->lines_count ?? 0)];
    }
}
