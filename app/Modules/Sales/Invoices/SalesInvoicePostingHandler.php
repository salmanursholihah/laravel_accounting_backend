<?php

namespace App\Modules\Sales\Invoices;

use App\Models\User;
use App\Modules\Accounting\DocumentLinks\DocumentLinkService;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\ItemCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posting Faktur Penjualan (langsung, tanpa Pengiriman). Satu transaksi menghasilkan dua jurnal (Tahap 4 bagian 2):
 *  main : Dr Piutang Usaha (total, subledger pelanggan) . Cr Penjualan per baris (DPP bersih diskon header) . Cr PPN Keluaran/PPnBM . Cr ongkir (pendapatan lain) . +- Pembulatan
 *  cogs : Dr HPP . Cr Persediaan per barang, nilai = HPP rata-rata saat keluar dari modul stok (ALG-05)
 * Keputusan: diskon header dinetralkan ke pendapatan tiap baris (tidak ada baris Dr Diskon Penjualan terpisah).
 */
class SalesInvoicePostingHandler extends AbstractPostingHandler
{
    public const DOC = 'sales_invoice';

    public function journalRules(): array
    {
        return [
            new JournalRule(self::DOC, 'D', 'piutang_usaha', '0', each: 'debit', group: 'main'),
            new JournalRule(self::DOC, 'K', 'penjualan', '0', each: 'kredit', group: 'main'),
            new JournalRule(self::DOC, 'D', 'hpp', '0', each: 'hpp_debit', group: 'cogs'),
            new JournalRule(self::DOC, 'K', 'persediaan', '0', each: 'hpp_kredit', group: 'cogs'),
        ];
    }

    public function docType(): string
    {
        return self::DOC;
    }

    public function feature(): string
    {
        return 'faktur_penjualan';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = SalesInvoice::query();
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
        if (! Customer::query()->where('contact_id', $doc->contact_id)->exists()) {
            throw PostingException::rule('Pelanggan faktur tidak ditemukan.');
        }
    }

    public function taxInput(Model $doc): ?array
    {
        return [
            'tanggal' => $this->tanggal($doc), 'harga_termasuk_pajak' => (bool) $doc->harga_termasuk_pajak,
            'diskon_header' => (string) $doc->diskon, 'ongkir' => (string) $doc->biaya_kirim,
            'lines' => $doc->lines->values()->map(fn ($l) => [
                'qty' => (string) $l->qty, 'harga' => (string) $l->harga, 'diskon_persen' => (string) $l->diskon_persen, 'tax_code_id' => $l->tax_code_id,
            ])->all(),
        ];
    }

    public function stockMovements(Model $doc): array
    {
        $items = Item::query()->whereIn('id', $doc->lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
        $out = [];
        foreach ($doc->lines as $l) {
            $item = $l->item_id ? $items[$l->item_id] : null;
            if ($item && $item->isBarang()) {
                $out[] = [
                    'item_id' => (int) $item->getKey(), 'warehouse_id' => (int) $l->warehouse_id, 'arah' => 'out',
                    'qty' => SalesInvoiceService::toBaseQty($item, $l->unit_id ? (int) $l->unit_id : null, (string) $l->qty),
                ];
            }
        }

        return $out;
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $items = Item::query()->whereIn('id', $doc->lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
        $cats = ItemCategory::query()->whereIn('id', $items->pluck('category_id')->filter()->all())->get()->keyBy('id');
        $akun = fn (?Item $it, string $f) => $it?->{$f} ?? ($it?->category_id ? $cats[$it->category_id]->{$f} : null); // barang -> kategori -> default mesin

        $cust = Customer::query()->where('contact_id', $doc->contact_id)->first();
        $debit = [['amount' => $taxes['total'], 'account_id' => $cust?->akun_piutang_override_id, 'contact_id' => (int) $doc->contact_id, 'memo' => $doc->memo]];
        $kredit = [];

        foreach ($doc->lines->values() as $i => $l) {
            $it = $l->item_id ? $items[$l->item_id] : null;
            $kredit[] = [
                'amount' => $taxes['lines'][$i]['dasar'],
                'account_id' => $l->account_id ?: $akun($it, 'akun_penjualan_id'),
                'memo' => $l->deskripsi,
            ];
        }
        foreach ($taxes['pajak'] as $kode => $p) {
            $kredit[] = $p['akun_penjualan_id']
                ? ['amount' => $p['pajak'], 'account_id' => (int) $p['akun_penjualan_id'], 'memo' => $kode]
                : ['amount' => $p['pajak'], 'account_key' => $p['jenis'] === 'ppnbm' ? 'utang_ppnbm' : 'ppn_keluaran', 'memo' => $kode];
        }
        if (Money::cmp($taxes['ongkir'], '0') > 0) {
            $kredit[] = ['amount' => $taxes['ongkir'], 'account_key' => 'pendapatan_lain', 'memo' => 'Biaya kirim'];
        }
        if (Money::cmp($taxes['pembulatan'], '0') > 0) {
            $kredit[] = ['amount' => $taxes['pembulatan'], 'account_key' => 'pembulatan', 'memo' => 'Pembulatan'];
        } elseif (Money::cmp($taxes['pembulatan'], '0') < 0) {
            $debit[] = ['amount' => Money::sub('0', $taxes['pembulatan']), 'account_key' => 'pembulatan', 'memo' => 'Pembulatan'];
        }

        // HPP per barang (agregat nilai keluar)
        $hpp = [];
        foreach ($stock->lines as $s) {
            $hpp[$s['item_id']] = Money::add($hpp[$s['item_id']] ?? '0', $s['nilai']);
        }
        $hppD = [];
        $hppK = [];
        foreach ($hpp as $itemId => $nilai) {
            $it = $items[$itemId];
            $hppD[] = ['amount' => $nilai, 'account_id' => $akun($it, 'akun_hpp_id'), 'memo' => $it->kode];
            $hppK[] = ['amount' => $nilai, 'account_id' => $akun($it, 'akun_persediaan_id'), 'memo' => $it->kode];
        }

        return ['lists' => ['debit' => $debit, 'kredit' => $kredit, 'hpp_debit' => $hppD, 'hpp_kredit' => $hppK], 'contact_id' => (int) $doc->contact_id];
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

    /** Penghalang void (ALG-07): penerimaan aktif yang mengalokasi faktur, retur aktif, dan turunan lain di document_links. */
    public function blockers(Model $doc): array
    {
        $cid = (int) $doc->company_id;
        $out = [];

        $rc = DB::table('sales_receipt_allocations as a')
            ->join('sales_receipts as r', fn ($j) => $j->on('r.id', '=', 'a.sales_receipt_id')->on('r.company_id', '=', 'a.company_id'))
            ->where('a.company_id', $cid)->where('a.sales_invoice_id', $doc->getKey())->where('r.status', '!=', 'void')
            ->distinct()->get(['r.id', 'r.nomor']);
        foreach ($rc as $r) {
            $out[$r->id . 'rc'] = ['doc_type' => 'sales_receipt', 'doc_id' => (int) $r->id, 'nomor' => $r->nomor];
        }

        foreach (DB::table('sales_returns')->where('company_id', $cid)->where('sales_invoice_id', $doc->getKey())->where('status', '!=', 'void')->get(['id', 'nomor']) as $r) {
            $out[$r->id . 'sr'] = ['doc_type' => 'sales_return', 'doc_id' => (int) $r->id, 'nomor' => $r->nomor];
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
        return ['contact_id' => (int) $doc->contact_id, 'total' => (string) $doc->total];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo;
    }
}
