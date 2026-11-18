<?php

namespace App\Modules\Stock\Adjustments;

use App\Models\User;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemCategory;
use App\Modules\Stock\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Model;

/**
 * Posting Penyesuaian Persediaan: selisih + = Dr Persediaan / Cr Penyesuaian Persediaan; selisih - = kebalikannya.
 * Nilai berasal dari mutasi stok (ALG-05), bukan dihitung ulang di sini. Akun persediaan per barang mengikuti
 * urutan override: barang -> kategori -> default perusahaan (mesin jurnal mengisi default).
 */
class StockAdjustmentPostingHandler extends AbstractPostingHandler
{
    public function journalRules(): array
    {
        $d = StockAdjustmentService::DOC;

        return [
            new JournalRule($d, 'D', 'persediaan', '0', each: 'masuk_persediaan'),
            new JournalRule($d, 'K', 'penyesuaian_persediaan', '0', each: 'masuk_lawan'),
            new JournalRule($d, 'D', 'penyesuaian_persediaan', '0', each: 'keluar_lawan'),
            new JournalRule($d, 'K', 'persediaan', '0', each: 'keluar_persediaan'),
        ];
    }

    public function docType(): string
    {
        return StockAdjustmentService::DOC;
    }

    public function feature(): string
    {
        return 'penyesuaian_persediaan';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = StockAdjustment::query();
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id)->load('lines');
    }

    public function tanggal(Model $doc): string
    {
        return $doc->tanggal->toDateString();
    }

    public function status(Model $doc): string
    {
        return (string) $doc->status;
    }

    public function validate(Model $doc, User $user): void
    {
        if ($doc->lines->isEmpty()) {
            throw PostingException::rule('Dokumen tidak punya baris.');
        }
    }

    public function stockMovements(Model $doc): array
    {
        return $doc->lines->map(fn ($l) => bccomp((string) $l->qty_selisih, '0', 4) > 0
            ? ['item_id' => (int) $l->item_id, 'warehouse_id' => (int) $doc->warehouse_id, 'arah' => 'in', 'qty' => (string) $l->qty_selisih, 'harga_satuan' => $l->harga_satuan === null ? null : (string) $l->harga_satuan]
            : ['item_id' => (int) $l->item_id, 'warehouse_id' => (int) $doc->warehouse_id, 'arah' => 'out', 'qty' => ltrim((string) $l->qty_selisih, '-')]
        )->all();
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $items = Item::query()->whereIn('id', array_column($stock->lines, 'item_id'))->get()->keyBy('id');
        $cats = ItemCategory::query()->whereIn('id', $items->pluck('category_id')->filter()->all())->get()->keyBy('id');

        $lists = ['masuk_persediaan' => [], 'masuk_lawan' => [], 'keluar_lawan' => [], 'keluar_persediaan' => []];
        foreach ($stock->lines as $s) {
            $item = $items[$s['item_id']];
            $akun = $item->akun_persediaan_id ?? ($item->category_id ? $cats[$item->category_id]->akun_persediaan_id : null);
            $row = ['amount' => $s['nilai'], 'account_id' => $akun, 'memo' => $item->kode . ' ' . $item->nama];

            if ($s['arah'] === 'in') {
                $lists['masuk_persediaan'][] = $row;
                $lists['masuk_lawan'][] = ['account_id' => null] + $row;
            } else {
                $lists['keluar_persediaan'][] = $row;
                $lists['keluar_lawan'][] = ['account_id' => null] + $row;
            }
        }

        return ['lists' => $lists];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        // nilai tiap baris dari hasil mutasi; baris barang yang sama dipasangkan menurut urutan dokumen
        $queue = [];
        foreach ($result->stock->lines as $s) {
            $queue[$s['item_id']][] = $s['nilai'];
        }
        foreach ($doc->lines as $l) {
            $l->forceFill(['nilai' => array_shift($queue[$l->item_id]) ?? '0'])->save();
        }

        $doc->forceFill([
            'nomor' => $result->nomor, 'status' => 'posted', 'journal_id' => $result->journalIds[0] ?? null,
            'diposting_oleh' => $user->getKey(), 'diposting_pada' => now(), 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill(['status' => 'void', 'void_oleh' => $user->getKey(), 'void_pada' => now(), 'alasan_void' => $reason, 'updated_by' => $user->getKey()])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return ['warehouse_id' => (int) $doc->warehouse_id];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->alasan;
    }
}
