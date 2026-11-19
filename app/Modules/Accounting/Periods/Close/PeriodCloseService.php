<?php

namespace App\Modules\Accounting\Periods\Close;

use App\Models\User;
use App\Modules\Accounting\Ledger\AccountBalanceService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Models\CompanySetting;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Recalc\StockRecalcService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proses Akhir Bulan (ALG-11, UC-11). check() murni baca; close() menjalankan checklist ulang di dalam transaksi
 * dengan baris periode terkunci, lalu menutup lewat PeriodService::close() dan mencatat period_close_logs.
 *
 * Status item: ok | warning | failed | na (belum ada modulnya). Status keseluruhan: failed bila ada failed,
 * warning bila ada warning, selain itu passed. failed memblokir; warning butuh konfirmasi eksplisit.
 *
 * Batas yang diketahui: posting yang sudah lolos PeriodService::assertPostable() sebelum commit penutupan tetap bisa
 * masuk ke periode yang baru dikunci (assertPostable tidak mengunci baris periode). Risikonya kecil karena checklist
 * dan penutupan berjalan sekejap; lihat DECISIONS.md.
 */
class PeriodCloseService
{
    public function __construct(
        private CurrentCompany $current,
        private PeriodService $periods,
        private AccountBalanceService $balances,
        private StockRecalcService $stockRecalc,
    ) {
    }

    /** @return array{periode:array, status:string, items:list<array>, bisa_ditutup:bool, butuh_konfirmasi:bool} */
    public function check(AccountingPeriod $period): array
    {
        return $this->assemble($period, [
            $this->checkOrder($period),
            $this->checkDrafts($period),
            $this->checkPendingHpp($period),
            $this->checkTrialBalance($period),
            $this->checkBalancesMatch(),
            $this->item('transaksi_berulang', 'Transaksi berulang', 'na', 'Modul transaksi berulang belum tersedia.'),
            $this->checkDepreciation($period),
            $this->checkBankReconciliation($period),
        ]);
    }

    /** Menyusun hasil dari daftar item (dipakai juga oleh Tutup Tahun yang menambah item sendiri). */
    public function assemble(AccountingPeriod $period, array $items): array
    {
        $statuses = array_column($items, 'status');
        $overall = in_array('failed', $statuses, true) ? 'failed' : (in_array('warning', $statuses, true) ? 'warning' : 'passed');

        return [
            'periode' => [
                'id' => (int) $period->getKey(), 'bulan' => (int) $period->bulan,
                'mulai' => $period->tanggal_mulai->toDateString(), 'selesai' => $period->tanggal_selesai->toDateString(),
                'status' => $period->status,
            ],
            'status' => $overall,
            'items' => $items,
            'bisa_ditutup' => $overall !== 'failed',
            'butuh_konfirmasi' => $overall === 'warning',
        ];
    }

    /**
     * Tutup satu periode. Periode terakhir tahun buku (bulan 12) ditolak: ia hanya ditutup lewat Tutup Tahun Buku.
     *
     * @return array hasil checklist yang dipakai saat menutup
     */
    public function close(int $periodId, User $user, bool $konfirmasi = false): array
    {
        $period = AccountingPeriod::query()->findOrFail($periodId); // terfilter perusahaan aktif
        if ((int) $period->bulan === 12) {
            throw PeriodCloseException::useFiscalYearClose();
        }

        try {
            return DB::transaction(function () use ($periodId, $user, $konfirmasi) {
                $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($periodId);
                if (! $locked->isOpen()) {
                    throw PeriodCloseException::alreadyClosed();
                }

                $hasil = $this->check($locked);
                $this->assertDecision($hasil, $konfirmasi);

                $this->periods->close($locked, $user);
                $this->log($locked, $user, 'closed', $hasil + ['konfirmasi_peringatan' => $konfirmasi]);

                return $hasil;
            });
        } catch (PeriodCloseException $e) {
            // dicatat di luar transaksi yang dibatalkan; hanya untuk penolakan berbasis checklist
            if ($e->hasil !== null) {
                $this->log($period, $user, $e->errorCode === 'confirm_required' ? 'warning' : 'failed', $e->hasil);
            }
            throw $e;
        }
    }

    /** Putusan umum: failed memblokir, warning butuh konfirmasi. */
    public function assertDecision(array $hasil, bool $konfirmasi): void
    {
        if (! $hasil['bisa_ditutup']) {
            throw PeriodCloseException::blocked($hasil);
        }
        if ($hasil['butuh_konfirmasi'] && ! $konfirmasi) {
            throw PeriodCloseException::confirmRequired($hasil);
        }
    }

    public function log(AccountingPeriod $period, User $user, string $status, array $hasil): void
    {
        DB::table('period_close_logs')->insert([
            'company_id' => $this->current->id(),
            'period_id' => $period->getKey(),
            'hasil_cek_json' => json_encode($hasil, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'dijalankan_oleh' => $user->getKey(),
            'created_at' => now(),
        ]);
    }

    /** Riwayat penutupan satu periode, terbaru dulu. */
    public function logs(AccountingPeriod $period, int $limit = 20): array
    {
        return DB::table('period_close_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.dijalankan_oleh')
            ->where('l.company_id', $this->current->id())->where('l.period_id', $period->getKey())
            ->orderByDesc('l.id')->limit($limit)
            ->get(['l.id', 'l.status', 'l.hasil_cek_json', 'l.created_at', 'u.name as dijalankan_oleh'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'status' => $r->status, 'dijalankan_oleh' => $r->dijalankan_oleh,
                'waktu' => (string) $r->created_at, 'hasil' => json_decode((string) $r->hasil_cek_json, true),
            ])->all();
    }

    // ------------------------------------------------------------------------------------------ item checklist

    /** Periode sebelumnya (termasuk tahun buku lama) harus sudah Closed; periode sebelum mulai pembukuan dikecualikan. */
    private function checkOrder(AccountingPeriod $period): array
    {
        $mulaiBuku = $this->current->company()->tanggal_mulai_pembukuan->toDateString();

        $open = AccountingPeriod::query()
            ->where('status', 'open')
            ->where('tanggal_selesai', '<', $period->tanggal_mulai->toDateString())
            ->where('tanggal_selesai', '>=', $mulaiBuku)
            ->orderBy('tanggal_mulai')->get();

        if ($open->isEmpty()) {
            return $this->item('urutan', 'Periode sebelumnya sudah ditutup', 'ok', 'Semua periode sebelumnya sudah dikunci.');
        }

        return $this->item('urutan', 'Periode sebelumnya sudah ditutup', 'failed',
            'Tutup berurutan: masih ada periode sebelumnya yang terbuka.',
            ['periode_terbuka' => $open->map(fn ($p) => $p->tanggal_mulai->format('m/Y'))->all()]);
    }

    /** Dokumen Draft dan Pending Approval bertanggal di periode ini. Kebijakan: company_settings.kebijakan_draft_akhir_bulan. */
    private function checkDrafts(AccountingPeriod $period): array
    {
        $cid = $this->current->id();
        $mulai = $period->tanggal_mulai->toDateString();
        $selesai = $period->tanggal_selesai->toDateString();

        $perJenis = [];
        $total = 0;
        foreach ((array) config('period_close.draft_tables', []) as $docType => [$table, $label]) {
            // tabel/kolom yang belum ada (modul belum dibangun) dilewati, bukan dianggap nol palsu
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, ['company_id', 'status', 'tanggal'])) {
                continue;
            }
            $n = (int) DB::table($table)->where('company_id', $cid)
                ->whereIn('status', ['draft', 'pending_approval'])
                ->whereBetween('tanggal', [$mulai, $selesai])->count();
            if ($n > 0) {
                $perJenis[] = ['doc_type' => $docType, 'label' => $label, 'jumlah' => $n];
                $total += $n;
            }
        }

        if ($total === 0) {
            return $this->item('draft', 'Dokumen Draft', 'ok', 'Tidak ada dokumen Draft atau menunggu persetujuan di periode ini.');
        }

        $blokir = CompanySetting::current()->kebijakan_draft_akhir_bulan === 'block';

        return $this->item('draft', 'Dokumen Draft', $blokir ? 'failed' : 'warning',
            "Ada {$total} dokumen belum diposting di periode ini" . ($blokir ? ' (kebijakan perusahaan: blokir).' : '.'),
            ['total' => $total, 'per_jenis' => $perJenis, 'kebijakan' => $blokir ? 'block' : 'warn']);
    }

    private function checkPendingHpp(AccountingPeriod $period): array
    {
        $n = $this->stockRecalc->pendingCountUntil($period->tanggal_selesai->toDateString());

        return $n === 0
            ? $this->item('hpp_tertunda', 'Hitung ulang HPP', 'ok', 'Tidak ada hitung ulang HPP yang tertunda.')
            : $this->item('hpp_tertunda', 'Hitung ulang HPP', 'failed', "Ada {$n} barang/gudang yang menunggu hitung ulang HPP sampai akhir periode ini. Jalankan hitung ulang lebih dulu.", ['jumlah' => $n]);
    }

    private function checkTrialBalance(AccountingPeriod $period): array
    {
        $tb = $this->balances->trialBalance($period);

        return $tb['seimbang']
            ? $this->item('neraca_saldo', 'Neraca saldo seimbang', 'ok', 'Total debit sama dengan total kredit sampai akhir periode.')
            : $this->item('neraca_saldo', 'Neraca saldo seimbang', 'failed', "Neraca saldo tidak seimbang, selisih {$tb['selisih']}.", ['selisih' => $tb['selisih']]);
    }

    /** account_balances harus cocok dengan journal_lines (rebuild tersedia di AccountBalanceService::rebuild). */
    private function checkBalancesMatch(): array
    {
        $diff = $this->balances->discrepancies();

        return $diff === []
            ? $this->item('saldo_cocok', 'Saldo ringkas cocok dengan jurnal', 'ok', 'account_balances sama dengan hasil penjumlahan journal_lines.')
            : $this->item('saldo_cocok', 'Saldo ringkas cocok dengan jurnal', 'failed', 'Saldo ringkas tidak cocok dengan jurnal (' . count($diff) . ' kombinasi akun-periode). Bangun ulang saldo.', ['jumlah' => count($diff), 'contoh' => array_slice($diff, 0, 5)]);
    }

    /**
     * Penyusutan aset (7D, ALG-11): 'na' bila perusahaan belum punya aset aktif; 'failed' bila ada aset yang jatuh tempo disusutkan
     * di periode ini tetapi belum diproses (memblokir: jadwal harus berurutan, jadi tidak bisa dikejar setelah periode terkunci).
     */
    private function checkDepreciation(AccountingPeriod $period): array
    {
        $cid = $this->current->id();
        $start = $period->tanggal_mulai->toDateString();
        $base = DB::table('fixed_assets')->where('company_id', $cid)->where('status', 'aktif');

        if (! (clone $base)->exists()) {
            return $this->item('penyusutan_aset', 'Penyusutan aset tetap', 'na', 'Belum ada aset tetap aktif.');
        }

        $due = (clone $base)->where('tanggal_mulai_susut', '<=', $start)
            ->whereRaw('TIMESTAMPDIFF(MONTH, tanggal_mulai_susut, ?) < umur_bulan', [$start])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('depreciation_schedules as s')
                ->whereColumn('s.asset_id', 'fixed_assets.id')->whereColumn('s.company_id', 'fixed_assets.company_id')
                ->where('s.jenis', 'komersial')->where('s.period_id', $period->getKey()))
            ->count();

        if ($due === 0) {
            return $this->item('penyusutan_aset', 'Penyusutan aset tetap', 'ok', 'Penyusutan periode ini sudah dijalankan atau tidak ada aset yang perlu disusutkan.');
        }

        return $this->item('penyusutan_aset', 'Penyusutan aset tetap', 'failed', "{$due} aset belum disusutkan untuk periode ini. Jalankan penyusutan periode ini dulu.", ['jumlah' => $due]);
    }

    /**
     * Rekonsiliasi bank (7C-3): setiap rekening koran yang beririsan dengan periode harus punya rekonsiliasi 'selesai'
     * untuk rekening dan periode koran yang sama persis. Belum selesai = peringatan (ALG-11), bukan blokir.
     * Rekening tanpa rekening koran di periode ini tidak diperiksa.
     */
    private function checkBankReconciliation(AccountingPeriod $period): array
    {
        $cid = $this->current->id();
        $rows = DB::table('bank_statements as s')
            ->join('bank_accounts as b', fn ($j) => $j->on('b.id', '=', 's.bank_account_id')->on('b.company_id', '=', 's.company_id'))
            ->where('s.company_id', $cid)
            ->where('s.periode_mulai', '<=', $period->tanggal_selesai->toDateString())
            ->where('s.periode_selesai', '>=', $period->tanggal_mulai->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bank_reconciliations as r')
                ->whereColumn('r.company_id', 's.company_id')->whereColumn('r.bank_account_id', 's.bank_account_id')
                ->whereColumn('r.periode_mulai', 's.periode_mulai')->whereColumn('r.periode_selesai', 's.periode_selesai')
                ->where('r.status', 'selesai'))
            ->orderBy('s.periode_mulai')->orderBy('s.id')
            ->get(['s.id', 's.bank_account_id', 'b.nama_bank', 's.periode_mulai', 's.periode_selesai']);

        if ($rows->isEmpty()) {
            return $this->item('rekonsiliasi_bank', 'Rekonsiliasi bank', 'ok', 'Tidak ada rekening koran yang belum direkonsiliasi di periode ini.');
        }

        return $this->item('rekonsiliasi_bank', 'Rekonsiliasi bank', 'warning', "Ada {$rows->count()} rekening koran di periode ini yang belum direkonsiliasi sampai selesai.", [
            'jumlah' => $rows->count(),
            'rekening_koran' => $rows->map(fn ($r) => ['bank_statement_id' => (int) $r->id, 'bank_account_id' => (int) $r->bank_account_id, 'nama_bank' => $r->nama_bank, 'periode' => $r->periode_mulai . ' s/d ' . $r->periode_selesai])->all(),
        ]);
    }

    public function item(string $kode, string $label, string $status, string $pesan, array $detail = []): array
    {
        return ['kode' => $kode, 'label' => $label, 'status' => $status, 'pesan' => $pesan] + ($detail ? ['detail' => $detail] : []);
    }
}
