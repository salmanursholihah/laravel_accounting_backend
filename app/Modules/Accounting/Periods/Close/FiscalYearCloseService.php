<?php

namespace App\Modules\Accounting\Periods\Close;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountMissing;
use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Periods\FiscalYear;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Tutup Tahun Buku (ALG-11, UC-13) dan buka kembali (UC-14 tingkat tahun).
 *
 * Tutup: checklist periode ke-12 + syarat tahun, lalu DALAM SATU TRANSAKSI:
 *   jurnal penutup (periode ke-12 masih Open, sehingga JournalService tidak dilemahkan) -> tutup periode ke-12 ->
 *   tahun buku Closed -> tahun buku berikutnya dibuat bila belum ada.
 * Jurnal penutup (tipe closing, source = fiscal_year): untuk tiap akun laba rugi (pendapatan, HPP, beban, lain-lain)
 *   saldo tahun itu ditutup ke nol, selisihnya langsung ke Laba Ditahan (keputusan: tanpa Ikhtisar Laba Rugi).
 *   Saldo dihitung dari kolom operasional account_balances (tanpa kolom penutup).
 *
 * Buka kembali: hanya hak buka_kunci_periode (Owner), alasan wajib, tahun yang lebih baru tidak boleh sudah Closed.
 *   Tahun buku dan periode ke-12 dibuka, jurnal penutup aktif dibalik (reverseClosing). Periode 1-11 tetap Closed;
 *   buka satu per satu lewat POST periods/{id}/reopen bila perlu.
 */
class FiscalYearCloseService
{
    /** Tipe akun yang ditutup. Akun neraca tidak pernah disentuh. */
    private const PL_TYPES = ['revenue', 'cogs', 'expense', 'other_income', 'other_expense'];

    public function __construct(
        private CurrentCompany $current,
        private PeriodService $periods,
        private PeriodCloseService $close,
        private JournalService $journals,
        private SystemAccountService $system,
        private AuditLogger $audit,
    ) {
    }

    /** Pratinjau: checklist + ringkasan jurnal penutup. Tidak menulis apa pun. */
    public function preview(int $fiscalYearId): array
    {
        $fy = FiscalYear::query()->findOrFail($fiscalYearId);
        $p12 = $this->lastPeriod($fy);
        $closing = $this->closingLines($fy);

        return $this->checklist($fy, $p12, $closing) + [
            'tahun' => (int) $fy->tahun,
            'status_tahun' => $fy->status,
            'penutup' => [
                'jumlah_akun' => $closing['jumlah_akun'],
                'laba_bersih' => $closing['laba_bersih'], // positif = laba, negatif = rugi
                'jumlah_baris_jurnal' => count($closing['lines']),
            ],
        ];
    }

    /** @return array{tahun:int, jurnal_penutup:?array, hasil:array, tahun_berikutnya_dibuat:bool} */
    public function close(int $fiscalYearId, User $user, bool $konfirmasi = false): array
    {
        $p12Ref = null;
        $hasilGagal = null;

        try {
            return DB::transaction(function () use ($fiscalYearId, $user, $konfirmasi, &$p12Ref) {
                $fy = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYearId);
                if ($fy->status !== 'open') {
                    throw PeriodCloseException::yearNotOpen();
                }
                $p12 = AccountingPeriod::query()->lockForUpdate()->findOrFail($this->lastPeriod($fy)->getKey());
                $p12Ref = $p12;

                if (Journal::query()->where(['source_type' => 'fiscal_year', 'source_id' => $fy->getKey(), 'tipe' => 'closing'])->active()->exists()) {
                    throw PeriodCloseException::closingExists();
                }

                $closing = $this->closingLines($fy);
                $hasil = $this->checklist($fy, $p12, $closing);
                $this->close->assertDecision($hasil, $konfirmasi);

                $journal = null;
                if ($closing['lines'] !== []) {
                    $journal = $this->journals->post(new JournalDraft(
                        tanggal: $fy->tanggal_selesai->toDateString(),
                        tipe: 'closing',
                        lines: $closing['lines'],
                        memo: "Penutupan tahun buku {$fy->tahun}",
                        sourceType: 'fiscal_year',
                        sourceId: (int) $fy->getKey(),
                        userId: (int) $user->getKey(),
                    ));
                }

                $this->periods->close($p12, $user);
                $fy->update(['status' => 'closed', 'ditutup_oleh' => $user->getKey(), 'ditutup_pada' => now()]);

                $dibuat = false;
                if (! FiscalYear::query()->where('tahun', $fy->tahun + 1)->exists()) {
                    $this->periods->createFiscalYear((int) $fy->tahun + 1); // tanpa tahun berikutnya semua posting terhenti
                    $dibuat = true;
                }

                $info = $journal ? ['id' => (int) $journal->getKey(), 'nomor' => $journal->nomor, 'laba_bersih' => $closing['laba_bersih']] : null;
                $this->close->log($p12, $user, 'closed', $hasil + ['konfirmasi_peringatan' => $konfirmasi, 'jurnal_penutup' => $info, 'tahun_berikutnya_dibuat' => $dibuat]);
                $this->audit->log('fiscal_year', (int) $fy->getKey(), 'close', ['status' => 'open'], [
                    'status' => 'closed', 'jurnal_penutup' => $info, 'tahun_berikutnya_dibuat' => $dibuat,
                ], null, (int) $this->current->id(), (int) $user->getKey());

                return ['tahun' => (int) $fy->tahun, 'jurnal_penutup' => $info, 'hasil' => $hasil, 'tahun_berikutnya_dibuat' => $dibuat];
            });
        } catch (PeriodCloseException $e) {
            if ($e->hasil !== null && $p12Ref !== null) {
                $this->close->log($p12Ref, $user, $e->errorCode === 'confirm_required' ? 'warning' : 'failed', $e->hasil);
            }
            throw $e;
        }
    }

    /** @return array{tahun:int, jurnal_dibalik:list<array>} */
    public function reopen(int $fiscalYearId, User $user, string $alasan): array
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw PeriodCloseException::reasonRequired();
        }

        return DB::transaction(function () use ($fiscalYearId, $user, $alasan) {
            $fy = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYearId);
            if ($fy->status !== 'closed') {
                throw PeriodCloseException::yearNotClosed();
            }

            // dari tahun terbaru ke belakang: tahun yang lebih baru tidak boleh sudah Closed
            $lebihBaru = FiscalYear::query()->where('tahun', '>', $fy->tahun)->where('status', 'closed')->orderBy('tahun')->first();
            if ($lebihBaru) {
                throw PeriodCloseException::laterYearClosed((int) $lebihBaru->tahun);
            }

            $p12 = AccountingPeriod::query()->lockForUpdate()->findOrFail($this->lastPeriod($fy)->getKey());
            $penutup = Journal::query()
                ->where(['source_type' => 'fiscal_year', 'source_id' => $fy->getKey(), 'tipe' => 'closing'])
                ->active()->orderBy('journals.id')->get();

            // urutan: tahun dibuka dulu (PeriodService::reopen menolak bila tahunnya Closed), lalu periode ke-12, lalu jurnal dibalik
            $fy->update(['status' => 'open', 'ditutup_oleh' => null, 'ditutup_pada' => null]);
            $this->periods->reopen($p12->fresh(), $user, $alasan);

            $dibalik = [];
            foreach ($penutup as $j) {
                $rev = $this->journals->reverseClosing($j, $alasan, (int) $user->getKey());
                $dibalik[] = ['asli' => $j->nomor, 'pembalik' => $rev->nomor];
            }

            $this->audit->log('fiscal_year', (int) $fy->getKey(), 'reopen', ['status' => 'closed'], [
                'status' => 'open', 'jurnal_dibalik' => $dibalik,
            ], $alasan, (int) $this->current->id(), (int) $user->getKey());

            return ['tahun' => (int) $fy->tahun, 'jurnal_dibalik' => $dibalik];
        });
    }

    // ------------------------------------------------------------------------------------------ internal

    private function lastPeriod(FiscalYear $fy): AccountingPeriod
    {
        return AccountingPeriod::query()->where('fiscal_year_id', $fy->getKey())->orderByDesc('bulan')->firstOrFail();
    }

    /** Checklist periode terakhir ditambah syarat khusus tahun buku. */
    private function checklist(FiscalYear $fy, AccountingPeriod $p12, array $closing): array
    {
        $items = $this->close->check($p12)['items'];

        $items[] = $fy->status === 'open'
            ? $this->close->item('tahun_terbuka', 'Tahun buku masih terbuka', 'ok', 'Tahun buku belum ditutup.')
            : $this->close->item('tahun_terbuka', 'Tahun buku masih terbuka', 'failed', 'Tahun buku sudah ditutup.');

        $items[] = $p12->isOpen()
            ? $this->close->item('periode_akhir_terbuka', 'Periode terakhir masih terbuka', 'ok', 'Jurnal penutup akan diterbitkan di periode terakhir.')
            : $this->close->item('periode_akhir_terbuka', 'Periode terakhir masih terbuka', 'failed', 'Periode terakhir sudah dikunci; jurnal penutup tidak bisa diterbitkan.');

        try {
            $ld = $this->system->resolve('laba_ditahan');
            $items[] = $ld->is_aktif
                ? $this->close->item('akun_laba_ditahan', 'Akun Laba Ditahan', 'ok', "Selisih laba rugi ditutup ke {$ld->kode} {$ld->nama}.")
                : $this->close->item('akun_laba_ditahan', 'Akun Laba Ditahan', 'failed', "Akun {$ld->kode} tidak aktif.");
        } catch (SystemAccountMissing) {
            $items[] = $this->close->item('akun_laba_ditahan', 'Akun Laba Ditahan', 'failed', "Akun sistem 'laba_ditahan' belum dipetakan di Preferensi.");
        }

        $items[] = $closing['akun_nonaktif'] === []
            ? $this->close->item('akun_laba_rugi_aktif', 'Akun laba rugi aktif', 'ok', 'Semua akun laba rugi bersaldo masih aktif.')
            : $this->close->item('akun_laba_rugi_aktif', 'Akun laba rugi aktif', 'failed', 'Ada akun laba rugi bersaldo yang tidak aktif. Aktifkan dulu agar bisa ditutup.', ['akun' => $closing['akun_nonaktif']]);

        return $this->close->assemble($p12, $items);
    }

    /**
     * Baris jurnal penutup dari account_balances tahun buku ini (kolom operasional saja).
     * Saldo debit -> dikredit; saldo kredit -> didebit; selisih ke Laba Ditahan.
     *
     * @return array{lines:list<array>, laba_bersih:string, jumlah_akun:int, akun_nonaktif:list<string>}
     */
    private function closingLines(FiscalYear $fy): array
    {
        $rows = DB::table('account_balances as ab')
            ->join('accounts as a', fn ($j) => $j->on('a.id', '=', 'ab.account_id')->on('a.company_id', '=', 'ab.company_id'))
            ->join('accounting_periods as p', fn ($j) => $j->on('p.id', '=', 'ab.period_id')->on('p.company_id', '=', 'ab.company_id'))
            ->where('ab.company_id', $this->current->id())
            ->where('p.fiscal_year_id', $fy->getKey())
            ->whereIn('a.tipe', self::PL_TYPES)
            ->groupBy('a.id', 'a.kode', 'a.is_aktif')
            ->orderBy('a.kode')
            ->selectRaw('a.id, a.kode, a.is_aktif, SUM(ab.debit) AS d, SUM(ab.kredit) AS k')
            ->get();

        $lines = [];
        $sumD = '0';
        $sumK = '0';
        $nonaktif = [];

        foreach ($rows as $r) {
            $net = Money::sub((string) $r->d, (string) $r->k); // > 0: saldo debit
            if (Money::isZero($net)) {
                continue;
            }
            if (! $r->is_aktif) {
                $nonaktif[] = (string) $r->kode;
            }

            if (Money::cmp($net, '0') > 0) {
                $lines[] = ['account_id' => (int) $r->id, 'debit' => '0', 'kredit' => $net, 'memo' => 'Penutupan tahun buku ' . $fy->tahun];
                $sumK = Money::add($sumK, $net);
            } else {
                $abs = Money::sub('0', $net);
                $lines[] = ['account_id' => (int) $r->id, 'debit' => $abs, 'kredit' => '0', 'memo' => 'Penutupan tahun buku ' . $fy->tahun];
                $sumD = Money::add($sumD, $abs);
            }
        }

        // debit > kredit pada baris penutup berarti pendapatan > beban: laba, plug dikredit ke Laba Ditahan
        $laba = Money::sub($sumD, $sumK);
        if ($lines !== [] && ! Money::isZero($laba)) {
            try {
                $ldId = (int) $this->system->resolve('laba_ditahan')->getKey();
            } catch (SystemAccountMissing) {
                $ldId = 0; // dilaporkan checklist; jurnal tidak akan diterbitkan karena ditolak sebelumnya
            }
            $lines[] = Money::cmp($laba, '0') > 0
                ? ['account_id' => $ldId, 'debit' => '0', 'kredit' => $laba, 'memo' => 'Laba tahun ' . $fy->tahun . ' ke Laba Ditahan']
                : ['account_id' => $ldId, 'debit' => Money::sub('0', $laba), 'kredit' => '0', 'memo' => 'Rugi tahun ' . $fy->tahun . ' ke Laba Ditahan'];
        }

        return [
            'lines' => $lines,
            'laba_bersih' => Money::round($laba),
            'jumlah_akun' => count($rows),
            'akun_nonaktif' => $nonaktif,
        ];
    }
}
