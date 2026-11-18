<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stock\Masters\StockMasterService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRUD master stok. Jenis master datang dari default route ('res'); hak dicek middleware feature: per route. */
class StockMasterController extends Controller
{
    public function __construct(private StockMasterService $service)
    {
    }

    public function index(Request $request, string $res): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'max:50'], 'is_aktif' => ['sometimes', 'boolean'], 'tipe' => ['sometimes', 'in:barang,jasa'],
            'after_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $r = $this->service->list($res, $f);

        return response()->json(['data' => $r['rows']->map(fn (Model $m) => $this->present($res, $m))->values(), 'next_after_id' => $r['next_after_id']]);
    }

    public function show(string $res, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($res, $this->service->find($res, $id))]);
    }

    public function store(Request $request, string $res): JsonResponse
    {
        $data = $request->validate($this->service->rules($res));

        return response()->json(['data' => $this->present($res, $this->service->create($res, $data, $request->user()))], 201);
    }

    public function update(Request $request, string $res, int $id): JsonResponse
    {
        $data = $request->validate($this->service->rules($res));

        return response()->json(['data' => $this->present($res, $this->service->update($res, $id, $data, $request->user()))]);
    }

    public function destroy(Request $request, string $res, int $id): JsonResponse
    {
        $this->service->delete($res, $id, $request->user());

        return response()->json(null, 204);
    }

    private function present(string $res, Model $m): array
    {
        $a = $m->attributesToArray();
        unset($a['company_id'], $a['created_by'], $a['updated_by']);
        if ($res === 'items') {
            $a['conversions'] = $m->relationLoaded('conversions') ? $m->conversions->map(fn ($c) => ['unit_id' => (int) $c->unit_id, 'rasio_ke_dasar' => (string) $c->rasio_ke_dasar])->values() : [];
        }

        return $a;
    }
}
