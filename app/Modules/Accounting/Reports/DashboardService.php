<?php

namespace App\Modules\Accounting\Reports;

use App\Models\User;
use App\Modules\Accounting\Approval\ApprovalService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard (6D). Widget ditentukan oleh IZIN user (bukan nama role), jadi role kustom ikut benar:
 *   kas_bank, laba_bulan_ini, penjualan_vs_pembelian ... laporan_keuangan
 *   piutang ........................ umur_piutang     utang ......................... umur_utang
 *   penjualan_hari_ini, faktur_belum_dibayar ... faktur_penjualan
 *   tagihan_utang .................. faktur_pembelian
 *   stok_menipis ................... barang_stok_minimum (tanpa nilai/HPP)
 *   periode_belum_ditutup, draft_tersisa ... hak khusus tutup_periode
 *   menunggu_persetujuan ........... selalu dicoba; isinya dari Kotak Persetujuan user itu
 * Blok data tingkat perusahaan di-cache (DashboardCache); blok milik user (persetujuan) tidak.
 * Belum ada karena modulnya belum ada: pengiriman tertunda, PO berjalan, rekonsiliasi tertunda, terlaris.
 */
class DashboardService
{
    private const DRAFT_TABLES = ['sales_invoices', 'purchase_invoices', 'purchase_payments', 'stock_adjustments', 'bank_transfers', 'cash_receipts', 'cash_payments', 'expenses'];

    public function __construct(
        private CurrentCompany $current,
        private PermissionChecker $perm,
        private AgingReportService $aging,
        private FinancialStatementService $fin,
        private PeriodService $periods,
        private ApprovalService $approvals,
    ) {
    }

    public function build(User $user): array
    {
        $cid = $this->current->id();
        $today = CarbonImmutable::now()->toDateString();
        $can = fn (string $fitur, string $aksi = 'view') => $this->perm->can($user, $fitur, $aksi);
        $w = [];

        if ($can('laporan_keuangan')) {
            $w['kas_bank'] = $this->cached('kas_bank', fn () => $this->kasBank($today), $today);
            $w['laba_bulan_ini'] = $this->cached('laba', fn () => $this->labaBulanIni($today), $today);
            $w['penjualan_vs_pembelian'] = $this->cached('chart12', fn () => $this->penjualanVsPembelian($today), substr($today, 0, 7));
        }
        if ($can('umur_piutang')) {
            $w['piutang'] = $this->cached('piutang', fn () => $this->agingTotals('piutang', $today), $today);
        }
        if ($can('umur_utang')) {
            $w['utang'] = $this->cached('utang', fn () => $this->agingTotals('utang', $today), $today);
        }
        if ($can('faktur_penjualan')) {
            $w['penjualan_hari_ini'] = $this->cached('jual_hari', fn () => $this->penjualanHariIni($today), $today);
            $w['faktur_belum_dibayar'] = $this->cached('jual_open', fn () => $this->fakturBelumDibayar($today), $today);
        }
        if ($can('faktur_pembelian')) {
            $w['tagihan_utang'] = $this->cached('tagihan_utang', fn () => $this->tagihanUtang($today), $today);
        }
        if ($can('barang_stok_minimum')) {
            $w['stok_menipis'] = $this->cached('stok_min', fn () => $this->stokMenipis(), 'x');
        }
        if ($can('khusus', 'tutup_periode')) {
            $w['periode_belum_ditutup'] = $this->cached('periode_open', fn () => $this->periodeBelumDitutup($today), $today);
            $w['draft_tersisa'] = $this->cached('draft', fn () => $this->draftTersisa(), 'x');
        }

        // Milik user, tidak di-cache: hasil mengikuti aturan siapa boleh menyetujui (ApprovalService::inbox).
        $w['menunggu_persetujuan'] = ['jumlah' => $this->approvals->inbox($user, 'waiting', null, 100)->count(), 'batas' => 100];

        return ['tanggal' => $today, 'widgets' => $w];
    }

    private function cached(string $block, \Closure $fn, string $suffix): mixed
    {
        return DashboardCache::remember($this->current->id(), $block, $fn, $suffix);
    }

    // ---------------------------------------------------------------- blok data

    private function kasBank(string $today): array
    {
        $r = DB::selectOne("
SELECT COALESCE(SUM(ab.debit - ab.kredit + ab.debit_penutup - ab.kredit_penutup), 0) AS saldo, COUNT(DISTINCT ba.id) AS rekening
FROM bank_accounts ba
LEFT JOIN account_balances ab ON ab.account_id = ba.account_id AND ab.company_id = ba.company_id
LEFT JOIN accounting_periods p ON p.id = ab.period_id AND p.company_id = ab.company_id AND p.tanggal_mulai <= ?
WHERE ba.company_id = ? AND (ab.account_id IS NULL OR p.id IS NOT NULL)", [$today, $this->current->id()]);

        return ['saldo' => Money::round((string) $r->saldo), 'jumlah_rekening' => (int) $r->rekening];
    }

    private function labaBulanIni(string $today): ?array
    {
        $period = $this->periods->periodFor($today);
        if (! $period) {
            return null;
        }
        $is = $this->fin->incomeStatement($period, $period, 'ringkas', 'sebelumnya');

        return ['periode' => $is['periode'], 'laba_bersih' => $is['laba_bersih']];
    }

    private function penjualanVsPembelian(string $today): array
    {
        $first = CarbonImmutable::parse($today)->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$first->addMonths($i)->format('Y-m')] = ['bulan' => $first->addMonths($i)->format('Y-m'), 'penjualan' => '0.0000', 'pembelian' => '0.0000'];
        }
        foreach (['penjualan' => 'sales_invoices', 'pembelian' => 'purchase_invoices'] as $key => $table) {
            $rows = DB::select("SELECT DATE_FORMAT(tanggal, '%Y-%m') AS bln, SUM(total) AS jml FROM {$table} WHERE company_id = ? AND status = 'posted' AND tanggal >= ? GROUP BY bln", [$this->current->id(), $first->toDateString()]);
            foreach ($rows as $r) {
                if (isset($months[$r->bln])) {
                    $months[$r->bln][$key] = Money::round((string) $r->jml);
                }
            }
        }

        return array_values($months);
    }

    private function agingTotals(string $sisi, string $today): array
    {
        $a = $this->aging->aging($sisi, $today, 'ringkas');

        return ['total' => $a['total']['total'], 'buckets' => $a['total']['buckets'], 'jumlah_faktur' => $a['total']['jumlah_faktur']];
    }

    private function penjualanHariIni(string $today): array
    {
        $r = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS jml FROM sales_invoices WHERE company_id = ? AND status = 'posted' AND tanggal = ?", [$this->current->id(), $today]);

        return ['jumlah_faktur' => (int) $r->n, 'total' => Money::round((string) $r->jml)];
    }

    private function fakturBelumDibayar(string $today): array
    {
        $r = DB::selectOne("
SELECT COUNT(*) AS n, COALESCE(SUM(sisa_tagihan), 0) AS jml,
       COALESCE(SUM(CASE WHEN jatuh_tempo < ? THEN 1 ELSE 0 END), 0) AS lewat
FROM sales_invoices WHERE company_id = ? AND status = 'posted' AND sisa_tagihan > 0", [$today, $this->current->id()]);

        return ['jumlah_faktur' => (int) $r->n, 'sisa' => Money::round((string) $r->jml), 'jumlah_lewat_jatuh_tempo' => (int) $r->lewat];
    }

    private function tagihanUtang(string $today): array
    {
        $r = DB::selectOne("
SELECT COALESCE(SUM(CASE WHEN jatuh_tempo < ? THEN 1 ELSE 0 END), 0) AS lewat_n,
       COALESCE(SUM(CASE WHEN jatuh_tempo < ? THEN sisa_tagihan ELSE 0 END), 0) AS lewat_jml,
       COALESCE(SUM(CASE WHEN jatuh_tempo >= ? AND jatuh_tempo <= ? THEN 1 ELSE 0 END), 0) AS soon_n,
       COALESCE(SUM(CASE WHEN jatuh_tempo >= ? AND jatuh_tempo <= ? THEN sisa_tagihan ELSE 0 END), 0) AS soon_jml
FROM purchase_invoices WHERE company_id = ? AND status = 'posted' AND sisa_tagihan > 0",
            [$today, $today, $today, $plus = CarbonImmutable::parse($today)->addDays(7)->toDateString(), $today, $plus, $this->current->id()]);

        return [
            'lewat_jatuh_tempo' => ['jumlah_faktur' => (int) $r->lewat_n, 'sisa' => Money::round((string) $r->lewat_jml)],
            'jatuh_tempo_7_hari' => ['jumlah_faktur' => (int) $r->soon_n, 'sisa' => Money::round((string) $r->soon_jml)],
        ];
    }

    /** Tanpa nilai persediaan/HPP: hanya qty. */
    private function stokMenipis(): array
    {
        $base = "FROM stock_balances sb
JOIN items i ON i.id = sb.item_id AND i.company_id = sb.company_id
JOIN warehouses w ON w.id = sb.warehouse_id AND w.company_id = sb.company_id
WHERE sb.company_id = ? AND i.tipe = 'barang' AND i.is_aktif = 1 AND sb.stok_minimum > 0 AND sb.qty_saldo <= sb.stok_minimum";
        $cid = $this->current->id();
        $n = (int) DB::selectOne("SELECT COUNT(*) AS n {$base}", [$cid])->n;
        $top = DB::select("SELECT i.kode, i.nama, w.nama AS gudang, sb.qty_saldo, sb.stok_minimum {$base} ORDER BY (sb.qty_saldo - sb.stok_minimum) ASC, i.kode LIMIT 5", [$cid]);

        return ['jumlah' => $n, 'teratas' => array_map(fn ($r) => [
            'kode' => $r->kode, 'nama' => $r->nama, 'gudang' => $r->gudang,
            'qty_saldo' => Money::round((string) $r->qty_saldo), 'stok_minimum' => Money::round((string) $r->stok_minimum),
        ], $top)];
    }

    private function periodeBelumDitutup(string $today): array
    {
        $rows = DB::select("SELECT bulan, tanggal_mulai, tanggal_selesai FROM accounting_periods WHERE company_id = ? AND status = 'open' AND tanggal_selesai < ? ORDER BY tanggal_mulai", [$this->current->id(), $today]);

        return ['jumlah' => count($rows), 'periode' => array_map(fn ($r) => ['mulai' => (string) $r->tanggal_mulai, 'selesai' => (string) $r->tanggal_selesai], array_slice($rows, 0, 5))];
    }

    private function draftTersisa(): array
    {
        $per = [];
        foreach (self::DRAFT_TABLES as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'status')) {
                $n = (int) DB::table($t)->where('company_id', $this->current->id())->where('status', 'draft')->count();
                if ($n > 0) {
                    $per[$t] = $n;
                }
            }
        }

        return ['jumlah' => array_sum($per), 'per_tabel' => $per];
    }
}
