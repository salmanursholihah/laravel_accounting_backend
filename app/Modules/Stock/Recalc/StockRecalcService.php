<?php

namespace App\Modules\Stock\Recalc;

use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\StockException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hitung ulang HPP transaksi mundur (ALG-05 onBackdated, Tahap 4 bagian 5, Tahap 8 bagian 8).
 *
 * Pemicu: StockLedgerService menandai stock_balances.perlu_hitung_ulang + hitung_ulang_dari saat ada gerakan bertanggal
 * lebih awal dari gerakan terakhir (barang, gudang). Job ini mengurai ulang stock_ledger dari tanggal itu, berurutan
 * (tanggal, id), dan memperbaiki nilai gerakan yang mengikuti HPP rata-rata (harga_tetap = 0).
 *
 * Jurnal posted tidak pernah diubah (trigger menolak). Selisih nilai tiap gerakan dijurnal sebagai JURNAL PENYESUAIAN
 * baru (source_type = stock_recalc, tanggal = tanggal gerakan asal):
 *   perubahan persediaan > 0 : Dr Persediaan . Cr akun lawan   |   < 0 : Dr akun lawan . Cr Persediaan
 *   akun lawan: HPP (dokumen penjualan, akun HPP barang -> kategori -> default) atau Penyesuaian Persediaan (lainnya).
 *
 * Aturan: satu transaksi per (barang, gudang) dengan baris saldo terkunci (hanya satu job aktif); ditolak bila rentang
 * [dari, gerakan terakhir] menyentuh periode Closed; stok minus pada replay ditolak kecuali izinkan_stok_minus.
 * Gagal = semua dibatalkan, penanda tetap menyala, percobaan tercatat di stock_recalc_runs (status gagal).
 * Idempoten: tanpa penanda, atau tanpa selisih nilai, tidak ada jurnal baru.
 */
class StockRecalcService
{
    /** source_type yang akun lawannya HPP; selain itu Penyesuaian Persediaan. */
    private const HPP_SOURCES = ['sales_invoice', 'sales_delivery', 'sales_return'];

    public function __construct(
        private CurrentCompany $current,
        private JournalService $journals,
        private SystemAccountService $system,
    ) {
    }

    /** @return list<array{item_id:int, warehouse_id:int, item_kode:string, gudang_kode:string, hitung_ulang_dari:?string}> */
    public function pending(): array
    {
        $cid = $this->current->id();

        return DB::table('stock_balances as b')
            ->join('items as i', fn ($j) => $j->on('i.id', '=', 'b.item_id')->on('i.company_id', '=', 'b.company_id'))
            ->join('warehouses as w', fn ($j) => $j->on('w.id', '=', 'b.warehouse_id')->on('w.company_id', '=', 'b.company_id'))
            ->where('b.company_id', $cid)->where('b.perlu_hitung_ulang', true)->orderBy('b.hitung_ulang_dari')->orderBy('b.id')
            ->get(['b.item_id', 'b.warehouse_id', 'i.kode as item_kode', 'w.kode as gudang_kode', 'b.hitung_ulang_dari'])
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'warehouse_id' => (int) $r->warehouse_id, 'item_kode' => $r->item_kode,
                'gudang_kode' => $r->gudang_kode, 'hitung_ulang_dari' => $r->hitung_ulang_dari ? (string) $r->hitung_ulang_dari : null])
            ->all();
    }

    /** Jumlah (barang, gudang) yang masih menunggu hitung ulang; dipakai pemeriksaan Akhir Bulan (ALG-11). */
    public function pendingCount(): int
    {
        return (int) DB::table('stock_balances')->where('company_id', $this->current->id())->where('perlu_hitung_ulang', true)->count();
    }

    /**
     * Jumlah (barang, gudang) yang menunggu hitung ulang dengan titik awal <= $tanggal (atau titik awal belum tercatat).
     * Dipakai checklist tutup bulan: hitung ulang yang baru mulai SESUDAH akhir periode tidak menghalangi.
     */
    public function pendingCountUntil(string $tanggal): int
    {
        return (int) DB::table('stock_balances')
            ->where('company_id', $this->current->id())->where('perlu_hitung_ulang', true)
            ->where(fn ($q) => $q->whereNull('hitung_ulang_dari')->orWhere('hitung_ulang_dari', '<=', $tanggal))
            ->count();
    }

    /**
     * Hitung ulang satu (barang, gudang). Melempar exception bila gagal (setelah mencatat percobaan gagal).
     *
     * @return array{status:string, run_id:?int, baris_berubah:int, jurnal:list<int>}
     */
    public function run(int $itemId, int $warehouseId, ?int $userId = null): array
    {
        $userId ??= Auth::id() ?? $this->ownerId();

        try {
            return DB::transaction(fn () => $this->replay($itemId, $warehouseId, $userId));
        } catch (Throwable $e) {
            $this->logFailure($itemId, $warehouseId, $userId, $e->getMessage());
            throw $e;
        }
    }

    /** Semua yang tertunda; kegagalan satu barang tidak menghentikan yang lain. @return array{selesai:int, gagal:list<array>} */
    public function runPending(?int $userId = null): array
    {
        $ok = 0;
        $gagal = [];
        foreach ($this->pending() as $p) {
            try {
                $this->run($p['item_id'], $p['warehouse_id'], $userId);
                $ok++;
            } catch (Throwable $e) {
                $gagal[] = ['item_id' => $p['item_id'], 'warehouse_id' => $p['warehouse_id'], 'pesan' => $e->getMessage()];
            }
        }

        return ['selesai' => $ok, 'gagal' => $gagal];
    }

    // ------------------------------------------------------------------------------------------- inti

    private function replay(int $itemId, int $whId, ?int $userId): array
    {
        $cid = $this->current->id();

        $bal = DB::table('stock_balances')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $whId)->lockForUpdate()->first();
        if (! $bal) {
            throw StockException::invalid('Saldo barang di gudang itu tidak ditemukan.', 'balance_not_found');
        }
        if (! $bal->perlu_hitung_ulang) {
            return ['status' => 'tanpa_perubahan', 'run_id' => null, 'baris_berubah' => 0, 'jurnal' => []];
        }

        $ledger = fn () => DB::table('stock_ledger')->where('company_id', $cid)->where('item_id', $itemId)->where('warehouse_id', $whId);
        $first = $ledger()->min('tanggal');
        $last = $ledger()->max('tanggal');
        $dari = $bal->hitung_ulang_dari !== null ? (string) $bal->hitung_ulang_dari : (string) $first;

        if ($first === null) { // tidak ada riwayat: tidak ada yang dihitung
            DB::table('stock_balances')->where('id', $bal->id)->update(['perlu_hitung_ulang' => 0, 'hitung_ulang_dari' => null]);

            return ['status' => 'tanpa_perubahan', 'run_id' => null, 'baris_berubah' => 0, 'jurnal' => []];
        }

        $closed = AccountingPeriod::query()->where('status', 'closed')->where('tanggal_selesai', '>=', $dari)->where('tanggal_mulai', '<=', $last)->exists();
        if ($closed) {
            throw StockException::closedRange($dari, (string) $last);
        }

        $allowNegative = (bool) DB::table('company_settings')->where('company_id', $cid)->value('izinkan_stok_minus');

        // keadaan awal = saldo baris terakhir sebelum $dari (baris sebelum titik itu konsisten)
        $prev = $ledger()->where('tanggal', '<', $dari)->orderByDesc('tanggal')->orderByDesc('id')->first();
        $state = $prev
            ? ['qty' => (string) $prev->qty_saldo, 'nilai' => (string) $prev->nilai_saldo, 'hpp' => $this->avg((string) $prev->qty_saldo, (string) $prev->nilai_saldo, '0')]
            : ['qty' => '0', 'nilai' => '0', 'hpp' => '0'];

        $rows = $ledger()->where('tanggal', '>=', $dari)->orderBy('tanggal')->orderBy('id')->get();
        $new = [];     // id => ['harga','nilai'] hasil hitung ulang
        $updates = []; // id => kolom baru
        $deltas = [];  // perubahan nilai baris yang berubah

        foreach ($rows as $r) {
            $wasIn = Money::cmp((string) $r->qty_masuk, '0') > 0;
            $qty = $wasIn ? (string) $r->qty_masuk : (string) $r->qty_keluar;

            if ($r->reversed_of_id !== null) {
                // pembalik: nilai mengikuti baris asal (yang mungkin baru dihitung ulang)
                $orig = $new[$r->reversed_of_id] ?? ['harga' => (string) DB::table('stock_ledger')->where('id', $r->reversed_of_id)->value('harga_satuan'),
                    'nilai' => (string) DB::table('stock_ledger')->where('id', $r->reversed_of_id)->value('nilai')];
                $harga = $orig['harga'];
                $nilai = $orig['nilai'];
            } elseif ($r->harga_tetap) {
                $harga = (string) $r->harga_satuan;
                $nilai = Money::round((string) $r->nilai);
            } elseif ($wasIn) {
                $harga = Money::cmp($state['hpp'], '0') > 0 ? $state['hpp'] : Money::round((string) $r->harga_satuan);
                $nilai = Money::round(Money::mul($qty, $harga));
            } else {
                if (Money::cmp($qty, $state['qty']) === 0 && Money::cmp($state['qty'], '0') > 0) {
                    $nilai = Money::round($state['nilai']);
                    $harga = Money::round(Money::div($nilai, $qty));
                } else {
                    $harga = $state['hpp'];
                    $nilai = Money::round(Money::mul($qty, $harga));
                }
            }

            if (! $wasIn && ! $allowNegative && Money::cmp($state['qty'], $qty) < 0) {
                $kode = (string) DB::table('items')->where('company_id', $cid)->where('id', $itemId)->value('kode');
                $gud = (string) DB::table('warehouses')->where('company_id', $cid)->where('id', $whId)->value('kode');
                throw StockException::insufficient($kode, $gud, Money::round($state['qty']), Money::round($qty));
            }

            $newQty = $wasIn ? Money::add($state['qty'], $qty) : Money::sub($state['qty'], $qty);
            $newNilai = $wasIn ? Money::add($state['nilai'], $nilai) : Money::sub($state['nilai'], $nilai);
            $state = ['qty' => Money::round($newQty), 'nilai' => Money::round($newNilai), 'hpp' => $this->avg($newQty, $newNilai, $state['hpp'])];
            $new[$r->id] = ['harga' => $harga, 'nilai' => $nilai];

            $changed = Money::cmp((string) $r->nilai, $nilai) !== 0 || Money::cmp((string) $r->harga_satuan, $harga) !== 0
                || Money::cmp((string) $r->qty_saldo, $state['qty']) !== 0 || Money::cmp((string) $r->nilai_saldo, $state['nilai']) !== 0;
            if ($changed) {
                $updates[$r->id] = ['harga_satuan' => $harga, 'nilai' => $nilai, 'qty_saldo' => $state['qty'], 'nilai_saldo' => $state['nilai']];
            }
            if (Money::cmp((string) $r->nilai, $nilai) !== 0) {
                $deltas[] = ['source_type' => (string) $r->source_type, 'source_id' => (int) $r->source_id, 'tanggal' => (string) $r->tanggal,
                    'perubahan_persediaan' => $wasIn ? Money::sub($nilai, (string) $r->nilai) : Money::sub((string) $r->nilai, $nilai)];
            }
        }

        $runId = (int) DB::table('stock_recalc_runs')->insertGetId([
            'company_id' => $cid, 'item_id' => $itemId, 'warehouse_id' => $whId, 'dari' => $dari, 'status' => 'selesai',
            'baris_berubah' => count($updates), 'jurnal_dibuat' => 0, 'dijalankan_oleh' => $userId, 'created_at' => now(),
        ]);

        foreach ($updates as $id => $cols) {
            DB::table('stock_ledger')->where('company_id', $cid)->where('id', $id)->update($cols);
        }
        DB::table('stock_balances')->where('id', $bal->id)->update([
            'qty_saldo' => $state['qty'], 'nilai_saldo' => $state['nilai'], 'hpp_rata_rata' => $state['hpp'],
            'perlu_hitung_ulang' => 0, 'hitung_ulang_dari' => null, 'updated_at' => now(),
        ]);

        $journalIds = $this->postAdjustments($deltas, $itemId, $runId, $userId);
        DB::table('stock_recalc_runs')->where('id', $runId)->update([
            'status' => $updates === [] ? 'tanpa_perubahan' : 'selesai', 'jurnal_dibuat' => count($journalIds),
        ]);

        return ['status' => $updates === [] ? 'tanpa_perubahan' : 'selesai', 'run_id' => $runId, 'baris_berubah' => count($updates), 'jurnal' => $journalIds];
    }

    /**
     * Satu jurnal penyesuaian per (dokumen sumber, tanggal gerakan). Selisih beberapa baris di kelompok yang sama dijumlah.
     *
     * @param list<array{source_type:string, source_id:int, tanggal:string, perubahan_persediaan:string}> $deltas
     * @return list<int>
     */
    private function postAdjustments(array $deltas, int $itemId, int $runId, ?int $userId): array
    {
        $groups = [];
        foreach ($deltas as $d) {
            $k = $d['source_type'] . '|' . $d['source_id'] . '|' . $d['tanggal'];
            $groups[$k] ??= ['source_type' => $d['source_type'], 'source_id' => $d['source_id'], 'tanggal' => $d['tanggal'], 'nilai' => '0'];
            $groups[$k]['nilai'] = Money::add($groups[$k]['nilai'], $d['perubahan_persediaan']);
        }

        $cid = $this->current->id();
        $item = DB::table('items')->where('company_id', $cid)->where('id', $itemId)->first();
        $persediaan = $this->itemAccount($item, 'akun_persediaan_id', 'persediaan');
        $ids = [];

        ksort($groups);
        foreach ($groups as $g) {
            $nilai = Money::round($g['nilai']);
            if (Money::isZero($nilai)) {
                continue; // pasangan asal + pembalik saling meniadakan
            }

            $hpp = in_array($g['source_type'], self::HPP_SOURCES, true);
            $lawan = $hpp ? $this->itemAccount($item, 'akun_hpp_id', 'hpp') : (int) $this->system->resolve('penyesuaian_persediaan')->getKey();
            $abs = Money::cmp($nilai, '0') < 0 ? Money::sub('0', $nilai) : $nilai;
            $naik = Money::cmp($nilai, '0') > 0;
            $memo = "Hitung ulang HPP {$item->kode}";

            $lines = [
                ['account_id' => $naik ? $persediaan : $lawan, 'debit' => $abs, 'kredit' => '0', 'memo' => $memo, 'contact_id' => null],
                ['account_id' => $naik ? $lawan : $persediaan, 'debit' => '0', 'kredit' => $abs, 'memo' => $memo, 'contact_id' => null],
            ];
            $j = $this->journals->post(new JournalDraft(
                tanggal: $g['tanggal'], tipe: 'auto', lines: $lines,
                memo: "Penyesuaian HPP hitung ulang ({$g['source_type']} #{$g['source_id']})",
                sourceType: 'stock_recalc', sourceId: $runId, userId: $userId,
            ));
            $ids[] = (int) $j->getKey();
        }

        return $ids;
    }

    /** Urutan akun: barang -> kategori (naik ke induk) -> default perusahaan. */
    private function itemAccount(object $item, string $field, string $systemKey): int
    {
        if ($item->{$field}) {
            return (int) $item->{$field};
        }
        $cid = $this->current->id();
        $catId = $item->category_id;
        for ($i = 0; $catId !== null && $i < 20; $i++) {
            $cat = DB::table('item_categories')->where('company_id', $cid)->where('id', $catId)->first();
            if (! $cat) {
                break;
            }
            if ($cat->{$field}) {
                return (int) $cat->{$field};
            }
            $catId = $cat->parent_id;
        }

        return (int) $this->system->resolve($systemKey)->getKey();
    }

    /** Sama dengan StockLedgerService::avg: tidak berubah bila qty atau nilai tidak positif. */
    private function avg(string $qty, string $nilai, string $old): string
    {
        if (Money::cmp($qty, '0') > 0 && Money::cmp($nilai, '0') > 0) {
            return Money::round(Money::div($nilai, $qty));
        }

        return Money::round($old);
    }

    private function logFailure(int $itemId, int $whId, ?int $userId, string $pesan): void
    {
        try {
            DB::table('stock_recalc_runs')->insert([
                'company_id' => $this->current->id(), 'item_id' => $itemId, 'warehouse_id' => $whId, 'status' => 'gagal',
                'pesan' => mb_substr($pesan, 0, 500), 'dijalankan_oleh' => $userId, 'created_at' => now(),
            ]);
        } catch (Throwable) {
            // pencatatan gagal tidak boleh menutupi galat aslinya
        }
    }

    /** Pelaku job terjadwal: Owner perusahaan (jurnal wajib punya pembuat). */
    private function ownerId(): ?int
    {
        $id = DB::table('company_users as cu')->join('roles as r', 'r.id', '=', 'cu.role_id')
            ->where('cu.company_id', $this->current->id())->where('cu.status', 'active')->where('r.nama', 'Owner')->orderBy('cu.id')->value('cu.user_id');

        return $id === null ? null : (int) $id;
    }
}
