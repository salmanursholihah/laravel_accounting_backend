<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Saldo ringkas account_balances (company, akun, periode). Hanya service ini yang menulisnya,
 * dan hanya dari dalam transaksi posting (JournalService). Jurnal penutup masuk kolom *_penutup.
 * Tabel ini bisa dibangun ulang dari journal_lines (rebuild) dan dibandingkan berkala (discrepancies).
 */
class AccountBalanceService
{
    public function __construct(private CurrentCompany $current)
    {
    }

    /** Menambahkan mutasi baris jurnal. Baris diurut per akun agar urutan penguncian selalu sama (anti deadlock). */
    public function apply(AccountingPeriod $period, array $lines, bool $closing): void
    {
        $sums = [];
        foreach ($lines as $l) {
            $id = (int) $l['account_id'];
            $sums[$id]['d'] = Money::add($sums[$id]['d'] ?? '0', $l['debit']);
            $sums[$id]['k'] = Money::add($sums[$id]['k'] ?? '0', $l['kredit']);
        }
        ksort($sums);

        $companyId = $this->current->id();
        [$cd, $ck] = $closing ? ['debit_penutup', 'kredit_penutup'] : ['debit', 'kredit']; // konstanta, bukan input

        foreach ($sums as $accountId => $s) {
            DB::table('account_balances')->insertOrIgnore([
                'company_id' => $companyId, 'account_id' => $accountId, 'period_id' => $period->getKey(),
                'debit' => 0, 'kredit' => 0, 'debit_penutup' => 0, 'kredit_penutup' => 0, 'updated_at' => now(),
            ]);

            DB::update(
                "UPDATE account_balances SET {$cd} = {$cd} + ?, {$ck} = {$ck} + ?, updated_at = ? "
                . 'WHERE company_id = ? AND account_id = ? AND period_id = ?',
                [Money::round($s['d']), Money::round($s['k']), now(), $companyId, $accountId, $period->getKey()],
            );
        }
    }

    /** Hitung ulang seluruh saldo ringkas perusahaan aktif dari journal_lines. */
    public function rebuild(): int
    {
        $companyId = $this->current->id();

        return DB::transaction(function () use ($companyId) {
            DB::delete('DELETE FROM account_balances WHERE company_id = ?', [$companyId]);

            return DB::affectingStatement(<<<'SQL'
INSERT INTO account_balances (company_id, account_id, period_id, debit, kredit, debit_penutup, kredit_penutup, updated_at)
SELECT jl.company_id, jl.account_id, j.period_id,
       SUM(CASE WHEN j.is_closing = 0 THEN jl.debit ELSE 0 END),
       SUM(CASE WHEN j.is_closing = 0 THEN jl.kredit ELSE 0 END),
       SUM(CASE WHEN j.is_closing = 1 THEN jl.debit ELSE 0 END),
       SUM(CASE WHEN j.is_closing = 1 THEN jl.kredit ELSE 0 END),
       NOW()
FROM journal_lines jl
JOIN journals j ON j.id = jl.journal_id AND j.company_id = jl.company_id
WHERE jl.company_id = ?
GROUP BY jl.company_id, jl.account_id, j.period_id
SQL, [$companyId]);
        });
    }

    /**
     * Pemeriksaan integritas (job malam, Tahap 8 bagian 8): bandingkan saldo ringkas dengan jumlah journal_lines.
     *
     * @return list<array{account_id:int, period_id:int, tersimpan:array, seharusnya:array}>
     */
    public function discrepancies(): array
    {
        $companyId = $this->current->id();

        $actual = DB::select(<<<'SQL'
SELECT jl.account_id, j.period_id,
       SUM(CASE WHEN j.is_closing = 0 THEN jl.debit ELSE 0 END) AS d,
       SUM(CASE WHEN j.is_closing = 0 THEN jl.kredit ELSE 0 END) AS k,
       SUM(CASE WHEN j.is_closing = 1 THEN jl.debit ELSE 0 END) AS dp,
       SUM(CASE WHEN j.is_closing = 1 THEN jl.kredit ELSE 0 END) AS kp
FROM journal_lines jl
JOIN journals j ON j.id = jl.journal_id AND j.company_id = jl.company_id
WHERE jl.company_id = ?
GROUP BY jl.account_id, j.period_id
SQL, [$companyId]);

        $stored = DB::table('account_balances')->where('company_id', $companyId)->get();

        $map = [];
        foreach ($actual as $r) {
            $map[$r->account_id . '|' . $r->period_id]['seharusnya'] = [$r->d, $r->k, $r->dp, $r->kp];
        }
        foreach ($stored as $r) {
            $map[$r->account_id . '|' . $r->period_id]['tersimpan'] = [$r->debit, $r->kredit, $r->debit_penutup, $r->kredit_penutup];
        }

        $diff = [];
        foreach ($map as $key => $v) {
            $a = $v['seharusnya'] ?? ['0', '0', '0', '0'];
            $s = $v['tersimpan'] ?? ['0', '0', '0', '0'];
            $same = true;
            foreach ($a as $i => $x) {
                if (Money::cmp((string) $x, (string) $s[$i]) !== 0) {
                    $same = false;
                }
            }
            if (! $same) {
                [$accountId, $periodId] = array_map('intval', explode('|', $key));
                $diff[] = ['account_id' => $accountId, 'period_id' => $periodId, 'tersimpan' => $s, 'seharusnya' => $a];
            }
        }

        return $diff;
    }

    /**
     * Neraca Saldo satu periode, dibaca dari account_balances.
     * - Akun neraca (aset, kewajiban, ekuitas): saldo awal = semua periode sebelumnya, termasuk jurnal penutup.
     * - Akun laba rugi: saldo awal hanya dari periode sebelumnya di tahun buku yang sama (reset tiap tahun buku).
     * - Mutasi tidak memuat jurnal penutup kecuali $includeClosing.
     * - 'seimbang' = pemeriksaan mentah debit total = kredit total s.d. akhir periode (dipakai ALG-11); tidak
     *   bergantung pada tutup tahun. Kolom saldo awal bisa tidak imbang bila tahun buku sebelumnya belum ditutup.
     */
    public function trialBalance(AccountingPeriod $period, bool $includeClosing = false): array
    {
        $companyId = $this->current->id();
        $mulai = $period->tanggal_mulai->toDateString();
        $selesai = $period->tanggal_selesai->toDateString();
        $fyId = (int) $period->fiscal_year_id;
        $pid = (int) $period->getKey();
        $flag = $includeClosing ? 1 : 0;

        $rows = DB::select(<<<'SQL'
SELECT a.id, a.kode, a.nama, a.tipe, a.saldo_normal,
  COALESCE(SUM(CASE WHEN p.tanggal_selesai < ? AND (a.tipe IN ('asset','liability','equity') OR p.fiscal_year_id = ?)
                    THEN ab.debit + ab.debit_penutup ELSE 0 END), 0) AS awal_debit,
  COALESCE(SUM(CASE WHEN p.tanggal_selesai < ? AND (a.tipe IN ('asset','liability','equity') OR p.fiscal_year_id = ?)
                    THEN ab.kredit + ab.kredit_penutup ELSE 0 END), 0) AS awal_kredit,
  COALESCE(SUM(CASE WHEN ab.period_id = ? THEN ab.debit + ab.debit_penutup * ? ELSE 0 END), 0) AS mutasi_debit,
  COALESCE(SUM(CASE WHEN ab.period_id = ? THEN ab.kredit + ab.kredit_penutup * ? ELSE 0 END), 0) AS mutasi_kredit
FROM accounts a
JOIN account_balances ab ON ab.account_id = a.id AND ab.company_id = a.company_id
JOIN accounting_periods p ON p.id = ab.period_id AND p.company_id = ab.company_id
WHERE a.company_id = ? AND p.tanggal_selesai <= ?
GROUP BY a.id, a.kode, a.nama, a.tipe, a.saldo_normal
HAVING awal_debit <> 0 OR awal_kredit <> 0 OR mutasi_debit <> 0 OR mutasi_kredit <> 0
ORDER BY a.kode
SQL, [$mulai, $fyId, $mulai, $fyId, $pid, $flag, $pid, $flag, $companyId, $selesai]);

        $out = [];
        $tot = array_fill_keys(['awal_debit', 'awal_kredit', 'mutasi_debit', 'mutasi_kredit', 'akhir_debit', 'akhir_kredit'], '0');

        foreach ($rows as $r) {
            $awal = Money::sub((string) $r->awal_debit, (string) $r->awal_kredit);
            $akhir = Money::add($awal, Money::sub((string) $r->mutasi_debit, (string) $r->mutasi_kredit));

            $row = [
                'account_id' => (int) $r->id, 'kode' => $r->kode, 'nama' => $r->nama, 'tipe' => $r->tipe,
                'awal_debit' => Money::cmp($awal, '0') > 0 ? Money::round($awal) : Money::round('0'),
                'awal_kredit' => Money::cmp($awal, '0') < 0 ? Money::round(Money::sub('0', $awal)) : Money::round('0'),
                'mutasi_debit' => Money::round((string) $r->mutasi_debit),
                'mutasi_kredit' => Money::round((string) $r->mutasi_kredit),
                'akhir_debit' => Money::cmp($akhir, '0') > 0 ? Money::round($akhir) : Money::round('0'),
                'akhir_kredit' => Money::cmp($akhir, '0') < 0 ? Money::round(Money::sub('0', $akhir)) : Money::round('0'),
            ];
            foreach ($tot as $k => $v) {
                $tot[$k] = Money::add($v, $row[$k]);
            }
            $out[] = $row;
        }

        $raw = DB::selectOne(<<<'SQL'
SELECT COALESCE(SUM(ab.debit + ab.debit_penutup), 0) AS d, COALESCE(SUM(ab.kredit + ab.kredit_penutup), 0) AS k
FROM account_balances ab
JOIN accounting_periods p ON p.id = ab.period_id AND p.company_id = ab.company_id
WHERE ab.company_id = ? AND p.tanggal_selesai <= ?
SQL, [$companyId, $selesai]);
        $selisih = Money::round(Money::sub((string) $raw->d, (string) $raw->k));

        return [
            'periode' => ['id' => $pid, 'mulai' => $mulai, 'selesai' => $selesai],
            'sebelum_penutup' => ! $includeClosing,
            'rows' => $out,
            'total' => array_map(fn ($v) => Money::round($v), $tot),
            'seimbang' => Money::isZero($selisih),
            'selisih' => $selisih,
        ];
    }
}
