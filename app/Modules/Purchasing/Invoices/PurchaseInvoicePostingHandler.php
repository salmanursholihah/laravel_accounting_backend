<?php

namespace App\Modules\Purchasing\Invoices;

use App\Models\User;
use App\Modules\Accounting\DocumentLinks\DocumentLinkService;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posting Faktur Pembelian (langsung, tanpa Penerimaan Barang). SATU jurnal (Tahap 4 bagian 3):
 *   Dr Persediaan (barang, pada nilai buku stok) . Dr Beban/HPP/Aset Tetap (baris non-barang) . Dr PPN Masukan . Cr Utang Usaha (total, subledger pemasok) . +- Pembulatan
 *
 * PPN Masukan hanya dikreditkan bila dapat_dikreditkan dan no_faktur_pajak terisi; bila tidak, PPN masuk biaya perolehan baris itu
 * (harga stok masuk ikut naik). PPnBM pembelian tidak pernah dikreditkan (selalu biaya perolehan).
 * Stok masuk memakai biaya perolehan per baris / qty satuan dasar (HPP rata-rata bergerak, ALG-05).
 * Selisih pembulatan rupiah (dan sisa pembulatan nilai stok 4 desimal) masuk akun `pembulatan`, sehingga jurnal selalu seimbang.
 */
class PurchaseInvoicePostingHandler extends AbstractPostingHandler
{
    public const DOC = 'purchase_invoice';

    public function journalRules(): array
    {
        return [
            new JournalRule(self::DOC, 'D', 'persediaan', '0', each: 'debit', group: 'main'),
            new JournalRule(self::DOC, 'K', 'utang_usaha', '0', each: 'kredit', group: 'main'),
        ];
    }

    public function docType(): string
    {
        return self::DOC;
    }

    public function feature(): string
    {
        return 'faktur_pembelian';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = PurchaseInvoice::query();
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
        if (! Supplier::query()->where('contact_id', $doc->contact_id)->exists()) {
            throw PostingException::rule('Pemasok faktur tidak ditemukan.');
        }
        PurchaseInvoiceService::assertSupplierInvoiceUnique((int) $doc->contact_id, (string) $doc->no_faktur_pemasok, (int) $doc->getKey());
    }

    public function taxInput(Model $doc): ?array
    {
        return [
            'tanggal' => $this->tanggal($doc), 'harga_termasuk_pajak' => (bool) $doc->harga_termasuk_pajak,
            'diskon_header' => (string) $doc->diskon, 'ongkir' => '0',
            'lines' => $doc->lines->values()->map(fn ($l) => [
                'qty' => (string) $l->qty, 'harga' => (string) $l->harga, 'diskon_persen' => (string) $l->diskon_persen, 'tax_code_id' => $l->tax_code_id,
            ])->all(),
        ];
    }

    public function stockMovements(Model $doc): array
    {
        $calc = app(TaxCalculator::class)->calculate($this->taxInput($doc)); // sama persis dengan yang dihitung orkestrator
        $costs = $this->lineCosts($doc, $calc);
        $items = $this->items($doc);

        $out = [];
        foreach ($doc->lines->values() as $i => $l) {
            $item = $l->item_id ? $items[$l->item_id] : null;
            if ($item && $item->isBarang()) {
                $qtyBase = PurchaseInvoiceService::toBaseQty($item, $l->unit_id ? (int) $l->unit_id : null, (string) $l->qty);
                $out[] = [
                    'item_id' => (int) $item->getKey(), 'warehouse_id' => (int) $l->warehouse_id, 'arah' => 'in', 'qty' => $qtyBase,
                    'harga_satuan' => Money::round(Money::div($costs[$i]['cost'], $qtyBase)),
                ];
            }
        }

        return $out;
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $items = $this->items($doc);
        $cats = ItemCategory::query()->whereIn('id', $items->pluck('category_id')->filter()->all())->get()->keyBy('id');
        $akun = fn (?Item $it, string $f) => $it?->{$f} ?: ($it?->category_id ? ($cats[$it->category_id]->{$f} ?? null) : null); // barang -> kategori -> default mesin

        $costs = $this->lineCosts($doc, $taxes);
        $creditable = $this->creditable($doc);

        $debit = [];
        $stockIdx = 0; // gerakan stok dibuat berurutan untuk baris barang
        foreach ($doc->lines->values() as $i => $l) {
            $it = $l->item_id ? $items[$l->item_id] : null;
            if ($it && $it->isBarang()) {
                $s = $stock->lines[$stockIdx++];
                $accId = $akun($it, 'akun_persediaan_id');
                $debit[] = ['amount' => $s['nilai'], 'memo' => $it->kode] + ($accId ? ['account_id' => (int) $accId] : ['account_key' => 'persediaan']);
            } else {
                $accId = $l->account_id ?: $akun($it, 'akun_hpp_id');
                $debit[] = ['amount' => $costs[$i]['cost'], 'memo' => $l->deskripsi] + ($accId ? ['account_id' => (int) $accId] : ['account_key' => 'hpp']);
            }
        }

        if ($creditable) {
            foreach ($taxes['pajak'] as $kode => $p) {
                if ($p['jenis'] === 'ppn' && Money::cmp($p['pajak'], '0') > 0) {
                    $debit[] = $p['akun_pembelian_id']
                        ? ['amount' => $p['pajak'], 'account_id' => (int) $p['akun_pembelian_id'], 'memo' => $kode]
                        : ['amount' => $p['pajak'], 'account_key' => 'ppn_masukan', 'memo' => $kode];
                }
            }
        }

        $sup = Supplier::query()->where('contact_id', $doc->contact_id)->first();
        $kredit = [[
            'amount' => $taxes['total'], 'account_id' => $sup?->akun_utang_override_id, 'account_key' => 'utang_usaha',
            'contact_id' => (int) $doc->contact_id, 'memo' => $doc->memo,
        ]];
        if (! empty($kredit[0]['account_id'])) {
            unset($kredit[0]['account_key']);
        } else {
            unset($kredit[0]['account_id']);
        }

        // pembulatan rupiah + sisa pembulatan nilai stok: penyeimbang jurnal
        $sumD = Money::sum(array_column($debit, 'amount'));
        $residual = Money::round(Money::sub($taxes['total'], $sumD));
        if (Money::cmp($residual, '0') > 0) {
            $debit[] = ['amount' => $residual, 'account_key' => 'pembulatan', 'memo' => 'Pembulatan'];
        } elseif (Money::cmp($residual, '0') < 0) {
            $kredit[] = ['amount' => Money::sub('0', $residual), 'account_key' => 'pembulatan', 'memo' => 'Pembulatan'];
        }

        return ['lists' => ['debit' => $debit, 'kredit' => $kredit], 'contact_id' => (int) $doc->contact_id];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        $t = $result->taxes;
        foreach ($doc->lines->values() as $i => $l) {
            $l->forceFill(['jumlah' => $t['lines'][$i]['netto'], 'qty_terpenuhi' => $l->qty, 'qty_sisa' => '0'])->save();
        }

        $doc->forceFill([
            'nomor' => $result->nomor, 'status' => 'posted', 'subtotal' => $t['subtotal'], 'diskon' => $t['diskon_header'],
            'dpp' => $t['dpp'], 'total_pajak' => $t['total_pajak'], 'total' => $t['total'],
            'total_dibayar' => '0', 'sisa_tagihan' => $t['total'], 'status_pembayaran' => 'belum',
            'journal_id' => $result->journalIds[0] ?? null, 'diposting_oleh' => $user->getKey(), 'diposting_pada' => now(), 'updated_by' => $user->getKey(),
        ])->save();

        $rows = [];
        foreach ($doc->lines->values() as $i => $l) {
            foreach ($t['lines'][$i]['pajak'] as $p) {
                $rows[] = [
                    'company_id' => $doc->company_id, 'doc_type' => self::DOC, 'doc_id' => $doc->getKey(), 'line_id' => $l->getKey(),
                    'tax_code_id' => $p['tax_code_id'], 'dpp' => $p['dpp'], 'tarif' => $p['tarif'], 'nominal' => $p['pajak'],
                    'created_by' => $user->getKey(), 'created_at' => now(), 'updated_by' => $user->getKey(), 'updated_at' => now(),
                ];
            }
        }
        if ($rows) {
            DB::table('document_taxes')->insert($rows);
        }
    }

    /** Penghalang void (ALG-07): pembayaran aktif yang mengalokasi faktur, retur pembelian aktif, dan turunan lain di document_links. */
    public function blockers(Model $doc): array
    {
        $cid = (int) $doc->company_id;
        $out = [];

        $py = DB::table('purchase_payment_allocations as a')
            ->join('purchase_payments as p', fn ($j) => $j->on('p.id', '=', 'a.purchase_payment_id')->on('p.company_id', '=', 'a.company_id'))
            ->where('a.company_id', $cid)->where('a.purchase_invoice_id', $doc->getKey())->where('p.status', '!=', 'void')
            ->distinct()->get(['p.id', 'p.nomor']);
        foreach ($py as $r) {
            $out[$r->id . 'py'] = ['doc_type' => 'purchase_payment', 'doc_id' => (int) $r->id, 'nomor' => $r->nomor];
        }

        foreach (DB::table('purchase_returns')->where('company_id', $cid)->where('purchase_invoice_id', $doc->getKey())->where('status', '!=', 'void')->get(['id', 'nomor']) as $r) {
            $out[$r->id . 'pr'] = ['doc_type' => 'purchase_return', 'doc_id' => (int) $r->id, 'nomor' => $r->nomor];
        }

        foreach (app(DocumentLinkService::class)->activeDescendants(self::DOC, (int) $doc->getKey()) as $d) {
            $out[$d['doc_id'] . $d['doc_type']] = $d;
        }

        return array_values($out);
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill([
            'status' => 'void', 'sisa_tagihan' => '0', 'void_oleh' => $user->getKey(), 'void_pada' => now(),
            'alasan_void' => $reason, 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return ['contact_id' => (int) $doc->contact_id, 'total' => (string) $doc->total, 'no_faktur_pemasok' => $doc->no_faktur_pemasok];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo;
    }

    private function creditable(Model $doc): bool
    {
        return (bool) $doc->dapat_dikreditkan && trim((string) $doc->no_faktur_pajak) !== '';
    }

    /** Biaya perolehan per baris = dasar + pajak yang tidak dikreditkan (PPnBM selalu; PPN bila tidak dikreditkan). */
    private function lineCosts(Model $doc, array $calc): array
    {
        $cred = $this->creditable($doc);
        $out = [];
        foreach ($doc->lines->values() as $i => $l) {
            $non = '0';
            foreach ($calc['lines'][$i]['pajak'] as $p) {
                $jenis = $calc['pajak'][$p['kode']]['jenis'];
                if ($jenis !== 'ppn' || ! $cred) {
                    $non = Money::add($non, $p['pajak']);
                }
            }
            $out[$i] = ['dasar' => $calc['lines'][$i]['dasar'], 'non' => Money::round($non), 'cost' => Money::round(Money::add($calc['lines'][$i]['dasar'], $non))];
        }

        return $out;
    }

    private function items(Model $doc)
    {
        return Item::query()->whereIn('id', $doc->lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
    }
}
