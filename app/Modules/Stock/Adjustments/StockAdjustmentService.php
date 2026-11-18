<?php

namespace App\Modules\Stock\Adjustments;

use App\Models\User;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\StockAdjustment;
use App\Modules\Stock\Models\StockAdjustmentLine;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Stock\StockException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Siklus Draft Penyesuaian Persediaan (UC-50). Hanya Draft yang boleh diubah/dihapus. Nomor, mutasi stok, dan jurnal
 * baru terjadi saat Posting lewat orkestrator (ALG-02). Selisih + = stok masuk, selisih - = stok keluar (satuan dasar).
 */
class StockAdjustmentService
{
    public const DOC = 'stock_adjustment';

    public function __construct(private CurrentCompany $current, private AuditLogger $audit, private PeriodService $periods)
    {
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'warehouse_id' => ['required', 'integer'],
            'alasan' => ['required', 'string', 'max:500'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty_selisih' => ['required', 'regex:/^-?\d{1,14}(\.\d{1,4})?$/'],
            'lines.*.harga_satuan' => ['nullable', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'lines.*.deskripsi' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function find(int $id): StockAdjustment
    {
        return StockAdjustment::query()->findOrFail($id)->load('lines');
    }

    public function create(array $data, User $user): StockAdjustment
    {
        [$header, $lines] = $this->prepare($data);

        return DB::transaction(function () use ($header, $lines, $user) {
            $doc = StockAdjustment::create($header + ['status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey()]);
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'create', null, $header + ['lines' => $lines], null, null, (int) $user->getKey());

            return $doc->load('lines');
        });
    }

    public function update(int $id, array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = StockAdjustment::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($doc);
            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw StockException::conflict();
            }

            [$header, $lines] = $this->prepare($data);
            $before = $doc->only(['tanggal', 'warehouse_id', 'alasan', 'memo']);
            $doc->forceFill($header + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            StockAdjustmentLine::query()->where('stock_adjustment_id', $doc->getKey())->delete();
            $this->writeLines($doc, $lines);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'update', $before, $header + ['lines' => $lines], null, null, (int) $user->getKey());

            return $doc->load('lines');
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $doc = StockAdjustment::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($doc);
            $this->audit->log(self::DOC, (int) $doc->getKey(), 'delete', $doc->getAttributes(), null, null, null, (int) $user->getKey());
            StockAdjustmentLine::query()->where('stock_adjustment_id', $doc->getKey())->delete();
            $doc->delete();
        });
    }

    private function assertDraft(StockAdjustment $doc): void
    {
        if ($doc->status !== 'draft') {
            throw StockException::invalid('Dokumen yang sudah diposting atau dibatalkan tidak bisa diubah atau dihapus. Gunakan Void.', 'not_draft');
        }
    }

    /** @return array{0: array, 1: list<array>} */
    private function prepare(array $d): array
    {
        $tanggal = Carbon::parse($d['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);

        $wh = Warehouse::query()->find((int) $d['warehouse_id']);
        if (! $wh) {
            throw StockException::invalid('Gudang tidak ditemukan.', 'warehouse_not_found');
        }
        if (! $wh->is_aktif) {
            throw StockException::inactive('Gudang', $wh->kode);
        }

        $lines = [];
        foreach (array_values($d['lines'] ?? []) as $i => $l) {
            $n = $i + 1;
            $qty = bcadd((string) $l['qty_selisih'], '0', 4);
            if (bccomp($qty, '0', 4) === 0) {
                throw StockException::invalid("Baris {$n}: qty selisih tidak boleh 0.", 'line_qty');
            }
            $item = Item::query()->find((int) $l['item_id']);
            if (! $item || ! $item->isBarang()) {
                throw StockException::invalid("Baris {$n}: barang tidak ditemukan atau bertipe jasa.", 'not_stock_item');
            }
            if (! $item->is_aktif) {
                throw StockException::inactive('Barang', $item->kode);
            }
            $harga = $l['harga_satuan'] ?? null;
            if ($harga !== null && bccomp($qty, '0', 4) < 0) {
                throw StockException::invalid("Baris {$n}: harga satuan hanya untuk selisih plus; selisih minus memakai HPP rata-rata.", 'line_price');
            }
            $lines[] = ['item_id' => (int) $item->getKey(), 'deskripsi' => $l['deskripsi'] ?? null, 'qty_selisih' => $qty, 'harga_satuan' => $harga === null ? null : bcadd((string) $harga, '0', 4)];
        }
        if ($lines === []) {
            throw StockException::invalid('Dokumen harus punya minimal satu baris.', 'no_lines');
        }

        return [['tanggal' => $tanggal, 'warehouse_id' => (int) $wh->getKey(), 'alasan' => $d['alasan'], 'memo' => $d['memo'] ?? null], $lines];
    }

    private function writeLines(StockAdjustment $doc, array $lines): void
    {
        foreach ($lines as $i => $l) {
            StockAdjustmentLine::create(['stock_adjustment_id' => $doc->getKey(), 'line_no' => $i + 1] + $l);
        }
    }
}
