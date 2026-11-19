<?php

namespace App\Modules\Accounting\Reports;

use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan keuangan (6B): Neraca, Laba Rugi, Buku Besar. Dibaca dari account_balances (Neraca, Laba Rugi) dan
 * journal_lines (Buku Besar); tidak menulis apa pun. Semua query terfilter company_id perusahaan aktif.
 *
 * Aturan (docs/STEP6B_NOTES.md):
 * - Akun neraca: saldo kumulatif s.d. akhir periode, TERMASUK jurnal penutup.
 * - Akun laba rugi: Laba Rugi mengabaikan jurnal penutup; Buku Besar mereset saldo awal tiap tahun buku.
 * - Neraca: Aset = Kewajiban + Ekuitas + Laba Berjalan. Laba Berjalan = saldo bersih seluruh akun laba rugi
 *   (termasuk kolom penutup), sehingga tahun lama yang belum ditutup tetap seimbang dan nilainya 0 setelah tutup tahun.
 * - Tanda: aset/beban/HPP = debit - kredit; kewajiban/ekuitas/pendapatan = kredit - debit.
 * - Mode 'ringkas' menggabungkan akun ke akun induknya (satu tingkat); 'rinci' menampilkan tiap akun.
 */
class FinancialStatementService
{
    private const PL = ['revenue', 'cogs', 'expense', 'other_income', 'other_expense'];

    public function __construct(private CurrentCompany $current)
    {
    }

    // ------------------------------------------------------------------ Neraca

    public function balanceSheet(AccountingPeriod $period, string $mode = 'rinci', ?string $bandingkan = null): array
    {
        $akhir = $period->tanggal_selesai->toDateString();
        $cur = $this->balancesUpTo($akhir);

        $cmp = null;
        $cmpMeta = null;
        if ($bandingkan !== null) {
            $cp = $this->comparePeriod($period, $bandingkan);
            $cmp = $cp ? $this->balancesUpTo($cp->tanggal_selesai->toDateString()) : [];
            $cmpMeta = $cp ? ['per_tanggal' => $cp->tanggal_selesai->toDateString(), 'periode_id' => (int) $cp->getKey()] : null;
        }

        [$aset, $asetC, $asetP] = $this->section($cur, $cmp, ['asset'], 1, $mode);
        [$kew, $kewC, $kewP] = $this->section($cur, $cmp, ['liability'], -1, $mode);

        $labaC = Money::sub('0', $this->plNet($cur));
        $labaP = $cmp === null ? null : Money::sub('0', $this->plNet($cmp));
        [$eks, $eksC] = $this->section($cur, $cmp, ['equity'], -1, $mode, [
            ['nama' => 'Laba Berjalan (belum ditutup)', '_c' => $labaC, '_p' => $labaP],
        ]);

        $selisih = Money::sub($asetC, Money::add($kewC, $eksC));

        return [
            'periode' => ['id' => (int) $period->getKey(), 'mulai' => $period->tanggal_mulai->toDateString(), 'selesai' => $akhir],
            'per_tanggal' => $akhir,
            'mode' => $mode,
            'pembanding' => $cmpMeta,
            'catatan' => ($bandingkan !== null && $cmpMeta === null) ? 'Periode pembanding tidak ditemukan; dibandingkan dengan nol.' : null,
            'aset' => $aset,
            'kewajiban' => $kew,
            'ekuitas' => $eks,
            'seimbang' => Money::isZero($selisih),
            'selisih' => Money::round($selisih),
        ];
    }

    // ------------------------------------------------------------------ Laba Rugi

    public function incomeStatement(AccountingPeriod $dari, AccountingPeriod $sampai, string $mode = 'rinci', ?string $bandingkan = null): array
    {
        $from = $dari->tanggal_mulai->toDateString();
        $to = $sampai->tanggal_selesai->toDateString();
        $cur = $this->plBalances($from, $to);

        $cmp = null;
        $cmpMeta = null;
        if ($bandingkan !== null) {
            $range = $this->compareRange($from, $to, $bandingkan);
            $cmp = $range ? $this->plBalances($range[0], $range[1]) : [];
            $cmpMeta = $range ? ['dari' => $range[0], 'sampai' => $range[1]] : null;
        }

        [$pend, $pendC, $pendP] = $this->section($cur, $cmp, ['revenue'], -1, $mode);
        [$hpp, $hppC, $hppP] = $this->section($cur, $cmp, ['cogs'], 1, $mode);
        [$beban, $bebanC, $bebanP] = $this->section($cur, $cmp, ['expense'], 1, $mode);
        [$lain, $lainC, $lainP] = $this->section($cur, $cmp, ['other_income'], -1, $mode);
        [$blain, $blainC, $blainP] = $this->section($cur, $cmp, ['other_expense'], 1, $mode);

        $sub = fn (?string $a, ?string $b): ?string => ($a === null || $b === null) ? null : Money::sub($a, $b);
        $add = fn (?string $a, ?string $b): ?string => ($a === null || $b === null) ? null : Money::add($a, $b);

        $kotorC = Money::sub($pendC, $hppC);
        $kotorP = $sub($pendP, $hppP);
        $usahaC = Money::sub($kotorC, $bebanC);
        $usahaP = $sub($kotorP, $bebanP);
        $bersihC = Money::sub(Money::add($usahaC, $lainC), $blainC);
        $bersihP = $sub($add($usahaP, $lainP), $blainP);

        return [
            'periode' => ['dari' => $from, 'sampai' => $to],
            'mode' => $mode,
            'pembanding' => $cmpMeta,
            'catatan' => ($bandingkan !== null && $cmpMeta === null) ? 'Periode pembanding tidak ditemukan; dibandingkan dengan nol.' : null,
            'pendapatan' => $pend,
            'hpp' => $hpp,
            'laba_kotor' => $this->cell($kotorC, $kotorP),
            'beban_operasional' => $beban,
            'laba_usaha' => $this->cell($usahaC, $usahaP),
            'pendapatan_lain' => $lain,
            'beban_lain' => $blain,
            'laba_bersih' => $this->cell($bersihC, $bersihP),
        ];
    }

    // ------------------------------------------------------------------ Buku Besar (ringkasan per akun)

    /**
     * Buku Besar seluruh akun dalam rentang tanggal: saldo awal, total debit, total kredit, saldo akhir (sesuai saldo normal).
     * Paginasi keyset per kode akun. Rincian mutasi satu akun = Histori Akun (AccountHistoryService).
     */
    public function generalLedger(string $dari, string $sampai, ?string $cursor = null, int $limit = 50, bool $includeClosing = false): array
    {
        $cid = $this->current->id();
        $limit = max(1, min($limit, 200));
        $flag = $includeClosing ? 1 : 0;

        $fyStart = DB::table('fiscal_years')->where('company_id', $cid)
            ->where('tanggal_mulai', '<=', $dari)->where('tanggal_selesai', '>=', $dari)->value('tanggal_mulai') ?? $dari;

        $bindings = [$dari, $fyStart, $dari, $sampai, $flag, $dari, $sampai, $flag, $cid, $sampai];
        $cursorSql = '';
        if ($cursor !== null) {
            $cursorSql = ' AND a.kode > ?';
            $bindings[] = $cursor;
        }
        $bindings[] = $limit + 1;

        $rows = DB::select(<<<SQL
SELECT a.id, a.kode, a.nama, a.tipe, a.saldo_normal,
  COALESCE(SUM(CASE WHEN jl.tanggal < ? AND (a.tipe IN ('asset','liability','equity') OR (jl.tanggal >= ? AND j.is_closing = 0))
                    THEN jl.debit - jl.kredit ELSE 0 END), 0) AS awal,
  COALESCE(SUM(CASE WHEN jl.tanggal BETWEEN ? AND ? AND (j.is_closing = 0 OR ? = 1) THEN jl.debit ELSE 0 END), 0) AS d,
  COALESCE(SUM(CASE WHEN jl.tanggal BETWEEN ? AND ? AND (j.is_closing = 0 OR ? = 1) THEN jl.kredit ELSE 0 END), 0) AS k
FROM accounts a
JOIN journal_lines jl ON jl.account_id = a.id AND jl.company_id = a.company_id
JOIN journals j ON j.id = jl.journal_id AND j.company_id = jl.company_id
WHERE a.company_id = ? AND jl.tanggal <= ?{$cursorSql}
GROUP BY a.id, a.kode, a.nama, a.tipe, a.saldo_normal
HAVING awal <> 0 OR d <> 0 OR k <> 0
ORDER BY a.kode
LIMIT ?
SQL, $bindings);

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $out = [];
        foreach ($rows as $r) {
            $awal = (string) $r->awal;
            $akhir = Money::sub(Money::add($awal, (string) $r->d), (string) $r->k);
            $toNormal = fn (string $net): string => $r->saldo_normal === 'debit' ? $net : Money::sub('0', $net);

            $out[] = [
                'account_id' => (int) $r->id, 'kode' => $r->kode, 'nama' => $r->nama, 'tipe' => $r->tipe,
                'saldo_normal' => $r->saldo_normal,
                'saldo_awal' => Money::round($toNormal($awal)),
                'debit' => Money::round((string) $r->d),
                'kredit' => Money::round((string) $r->k),
                'saldo_akhir' => Money::round($toNormal($akhir)),
            ];
        }

        return [
            'periode' => ['dari' => $dari, 'sampai' => $sampai],
            'sebelum_penutup' => ! $includeClosing,
            'rows' => $out,
            'next_cursor' => $more && $out !== [] ? $out[count($out) - 1]['kode'] : null,
        ];
    }

    // ------------------------------------------------------------------ query dasar

    /** @return array<int, array{account_id:int,kode:string,nama:string,tipe:string,parent_id:?int,net:string}> net = debit - kredit, termasuk penutup */
    private function balancesUpTo(string $akhir): array
    {
        $rows = DB::select(<<<'SQL'
SELECT a.id, a.kode, a.nama, a.tipe, a.parent_id,
       SUM(ab.debit + ab.debit_penutup) AS d, SUM(ab.kredit + ab.kredit_penutup) AS k
FROM account_balances ab
JOIN accounts a ON a.id = ab.account_id AND a.company_id = ab.company_id
JOIN accounting_periods p ON p.id = ab.period_id AND p.company_id = ab.company_id
WHERE ab.company_id = ? AND p.tanggal_selesai <= ?
GROUP BY a.id, a.kode, a.nama, a.tipe, a.parent_id
SQL, [$this->current->id(), $akhir]);

        return $this->mapRows($rows);
    }

    /** Hanya akun laba rugi, tanpa kolom penutup, periode yang seluruhnya berada di [from, to]. */
    private function plBalances(string $from, string $to): array
    {
        $rows = DB::select(<<<'SQL'
SELECT a.id, a.kode, a.nama, a.tipe, a.parent_id, SUM(ab.debit) AS d, SUM(ab.kredit) AS k
FROM account_balances ab
JOIN accounts a ON a.id = ab.account_id AND a.company_id = ab.company_id
JOIN accounting_periods p ON p.id = ab.period_id AND p.company_id = ab.company_id
WHERE ab.company_id = ? AND p.tanggal_mulai >= ? AND p.tanggal_selesai <= ?
  AND a.tipe IN ('revenue','cogs','expense','other_income','other_expense')
GROUP BY a.id, a.kode, a.nama, a.tipe, a.parent_id
SQL, [$this->current->id(), $from, $to]);

        return $this->mapRows($rows);
    }

    private function mapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = [
                'account_id' => (int) $r->id, 'kode' => $r->kode, 'nama' => $r->nama, 'tipe' => $r->tipe,
                'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null,
                'net' => Money::sub((string) $r->d, (string) $r->k),
            ];
        }

        return $out;
    }

    private function plNet(array $map): string
    {
        $t = '0';
        foreach ($map as $r) {
            if (in_array($r['tipe'], self::PL, true)) {
                $t = Money::add($t, $r['net']);
            }
        }

        return $t;
    }

    // ------------------------------------------------------------------ perbandingan

    private function comparePeriod(AccountingPeriod $p, string $kind): ?AccountingPeriod
    {
        if ($kind === 'sebelumnya') {
            return AccountingPeriod::query()->where('tanggal_selesai', '<', $p->tanggal_mulai->toDateString())
                ->orderByDesc('tanggal_selesai')->first();
        }

        $d = CarbonImmutable::parse($p->tanggal_selesai->toDateString())->subYearNoOverflow()->toDateString();

        return AccountingPeriod::query()->where('tanggal_mulai', '<=', $d)->where('tanggal_selesai', '>=', $d)->first();
    }

    /** @return array{0:string,1:string}|null rentang tanggal pembanding */
    private function compareRange(string $from, string $to, string $kind): ?array
    {
        if ($kind === 'tahun_lalu') {
            return [
                CarbonImmutable::parse($from)->subYearNoOverflow()->toDateString(),
                CarbonImmutable::parse($to)->subYearNoOverflow()->toDateString(),
            ];
        }

        $n = AccountingPeriod::query()->where('tanggal_mulai', '>=', $from)->where('tanggal_selesai', '<=', $to)->count();
        $prior = AccountingPeriod::query()->where('tanggal_selesai', '<', $from)->orderByDesc('tanggal_selesai')->limit(max($n, 1))->get();
        if ($prior->isEmpty()) {
            return null;
        }

        return [$prior->last()->tanggal_mulai->toDateString(), $prior->first()->tanggal_selesai->toDateString()];
    }

    // ------------------------------------------------------------------ penyusunan baris

    /**
     * @param  array|null $cmp  null = tanpa pembanding; [] = pembanding tidak ada (dianggap nol)
     * @param  array      $extra baris sintetis: ['nama'=>..., '_c'=>..., '_p'=>...]
     * @return array{0:array,1:string,2:?string} [bagian, total sekarang, total pembanding]
     */
    private function section(array $cur, ?array $cmp, array $types, int $sign, string $mode, array $extra = []): array
    {
        $meta = [];
        foreach ([$cur, $cmp ?? []] as $map) {
            foreach ($map as $id => $r) {
                if (in_array($r['tipe'], $types, true)) {
                    $meta[$id] = $r;
                }
            }
        }

        $rows = [];
        foreach ($meta as $id => $m) {
            $rows[] = [
                'account_id' => $id, 'kode' => $m['kode'], 'nama' => $m['nama'], 'parent_id' => $m['parent_id'],
                '_c' => $this->signed($cur[$id]['net'] ?? '0', $sign),
                '_p' => $cmp === null ? null : $this->signed($cmp[$id]['net'] ?? '0', $sign),
            ];
        }
        foreach ($extra as $e) {
            $rows[] = $e + ['account_id' => null, 'kode' => null, 'parent_id' => null];
        }

        if ($mode === 'ringkas') {
            $rows = $this->collapse($rows);
        }
        usort($rows, fn ($a, $b) => strcmp($a['kode'] ?? '~', $b['kode'] ?? '~'));

        $sumC = '0';
        $sumP = $cmp === null ? null : '0';
        $out = [];
        foreach ($rows as $r) {
            $sumC = Money::add($sumC, $r['_c']);
            if ($sumP !== null) {
                $sumP = Money::add($sumP, $r['_p']);
            }
            if (Money::isZero($r['_c']) && ($r['_p'] === null || Money::isZero($r['_p']))) {
                continue;
            }
            $out[] = ['account_id' => $r['account_id'], 'kode' => $r['kode'], 'nama' => $r['nama']] + $this->cell($r['_c'], $r['_p']);
        }

        return [['rows' => $out, 'total' => $this->cell($sumC, $sumP)], $sumC, $sumP];
    }

    /** Mode ringkas: gabungkan akun ke akun induknya (satu tingkat). */
    private function collapse(array $rows): array
    {
        $idx = [];
        foreach (DB::table('accounts')->where('company_id', $this->current->id())->get(['id', 'kode', 'nama']) as $a) {
            $idx[(int) $a->id] = ['kode' => $a->kode, 'nama' => $a->nama];
        }

        $out = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] ?? null;
            if ($r['account_id'] !== null && $pid !== null && isset($idx[$pid])) {
                $key = 'a' . $pid;
                $meta = ['account_id' => $pid, 'kode' => $idx[$pid]['kode'], 'nama' => $idx[$pid]['nama'], 'parent_id' => null];
            } else {
                $key = $r['account_id'] !== null ? 'a' . $r['account_id'] : 'x' . $r['nama'];
                $meta = ['account_id' => $r['account_id'], 'kode' => $r['kode'], 'nama' => $r['nama'], 'parent_id' => null];
            }

            if (! isset($out[$key])) {
                $out[$key] = $meta + ['_c' => '0', '_p' => $r['_p'] === null ? null : '0'];
            }
            $out[$key]['_c'] = Money::add($out[$key]['_c'], $r['_c']);
            if ($r['_p'] !== null) {
                $out[$key]['_p'] = Money::add($out[$key]['_p'] ?? '0', $r['_p']);
            }
        }

        return array_values($out);
    }

    private function signed(string $net, int $sign): string
    {
        return $sign > 0 ? $net : Money::sub('0', $net);
    }

    /** Sel angka: jumlah; dengan pembanding ditambah pembanding, selisih, persen (null bila pembanding 0). */
    private function cell(string $cur, ?string $cmp): array
    {
        $c = ['jumlah' => Money::round($cur)];
        if ($cmp === null) {
            return $c;
        }

        $selisih = Money::sub($cur, $cmp);
        $base = Money::cmp($cmp, '0') < 0 ? Money::sub('0', $cmp) : $cmp;

        return $c + [
            'pembanding' => Money::round($cmp),
            'selisih' => Money::round($selisih),
            'persen' => Money::isZero($cmp) ? null : Money::round(Money::mul(Money::div($selisih, $base), '100'), 2),
        ];
    }
}
