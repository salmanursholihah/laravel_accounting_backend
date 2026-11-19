<?php

namespace App\Modules\FixedAssets\Depreciation;

use App\Models\User;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Ledger\JournalService;
use App\Modules\Accounting\Periods\AccountingPeriod;
use App\Modules\Accounting\Support\Money;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penyusutan aset tetap garis lurus (ALG-08), idempoten per (aset, periode).
 *
 * Bulan ke-n aset = selisih bulan sejak tanggal_mulai_susut + 1. Penyusutan bulanan = (harga - residu) / umur dipotong ke 4 desimal;
 * bulan terakhir (n = umur) = sisa agar nilai buku = residu. Jadwal harus berurutan: bulan n hanya bisa diproses bila n-1 bulan
 * sebelumnya sudah ada (kalau tidak: previous_period_not_run). Satu jurnal per kategori per periode, tanggal = akhir periode:
 * Dr akun beban penyusutan, Cr akun akumulasi penyusutan (akun dari Kategori Aset). Seluruh periode diproses dalam satu transaksi.
 * Belum ada pembatalan penyusutan (void) dan penyusutan fiskal.
 */
class DepreciationService
{
    public function __construct(private CurrentCompany $current, private JournalService $journals, private AuditLogger $audit)
    {
    }

    /** Pratinjau tanpa menulis apa pun. */
    public function preview(int $periodId): array
    {
        $period = AccountingPeriod::query()->findOrFail($periodId);

        return $this->summarize($period, $this->plan($period, false));
    }

    public function run(int $periodId, User $user): array
    {
        return DB::transaction(function () use ($periodId, $user) {
            $cid = $this->current->id();
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($periodId);
            if ($period->status === 'closed') {
                throw new FixedAssetException('Periode sudah Closed. Penyusutan tidak bisa dijalankan.', 'period_closed', 409);
            }

            $plan = $this->plan($period, true);
            $now = now();
            $akhir = $period->tanggal_selesai->toDateString();
            $label = $period->tanggal_mulai->format('m/Y');
            $jurnal = [];

            foreach ($plan as $categoryId => $rows) {
                $cat = AssetCategory::query()->findOrFail($categoryId);
                $total = Money::sum(array_column($rows, 'nilai'));

                $runId = (int) DB::table('depreciation_runs')->insertGetId([
                    'company_id' => $cid, 'period_id' => $period->getKey(), 'category_id' => $categoryId, 'journal_id' => null,
                    'jumlah_aset' => count($rows), 'total' => $total,
                    'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
                ]);

                $journalId = null;
                if (Money::cmp($total, '0') > 0) {
                    $j = $this->journals->post(new JournalDraft(
                        tanggal: $akhir, tipe: 'auto',
                        lines: [
                            ['account_id' => $cat->akun_beban_id, 'debit' => $total, 'kredit' => '0', 'memo' => "Penyusutan {$cat->nama} {$label}"],
                            ['account_id' => $cat->akun_akumulasi_id, 'debit' => '0', 'kredit' => $total, 'memo' => "Akumulasi penyusutan {$cat->nama} {$label}"],
                        ],
                        memo: "Penyusutan aset {$cat->nama} periode {$label}", sourceType: 'depreciation_run', sourceId: $runId, userId: (int) $user->getKey(),
                    ));
                    $journalId = (int) $j->getKey();
                    DB::table('depreciation_runs')->where('company_id', $cid)->where('id', $runId)->update(['journal_id' => $journalId]);
                    $jurnal[] = ['category_id' => $categoryId, 'journal_id' => $journalId, 'nomor' => $j->nomor, 'total' => $total];
                }

                DB::table('depreciation_schedules')->insert(array_map(fn (array $r) => [
                    'company_id' => $cid, 'asset_id' => $r['asset_id'], 'jenis' => 'komersial', 'period_id' => $period->getKey(), 'run_id' => $runId,
                    'bulan_ke' => $r['bulan_ke'], 'nilai_penyusutan' => $r['nilai'], 'akumulasi' => $r['akumulasi'], 'nilai_buku' => $r['nilai_buku'],
                    'journal_id' => $journalId, 'created_by' => $user->getKey(), 'created_at' => $now,
                ], $rows));
            }

            $summary = $this->summarize($period, $plan) + ['jurnal' => $jurnal];
            $this->audit->log('depreciation', (int) $period->getKey(), 'run', null, ['periode' => $label, 'jumlah_aset' => $summary['jumlah_aset'], 'total' => $summary['total']]);

            return $summary;
        });
    }

    /**
     * Hitung rencana penyusutan periode, dikelompokkan per kategori: [category_id => list<baris>].
     * Aset yang sudah punya jadwal periode ini dilewati (idempoten). Aset dengan bulan sebelumnya belum diproses menggagalkan seluruhnya.
     */
    private function plan(AccountingPeriod $period, bool $lock): array
    {
        $cid = $this->current->id();
        $start = $period->tanggal_mulai->toDateString();

        $assets = FixedAsset::query()->where('status', 'aktif')->where('tanggal_mulai_susut', '<=', $start)->orderBy('id');
        if ($lock) {
            $assets->lockForUpdate();
        }
        $assets = $assets->get();
        if ($assets->isEmpty()) {
            return [];
        }
        $ids = $assets->pluck('id')->all();

        $sudah = DB::table('depreciation_schedules')->where('company_id', $cid)->where('jenis', 'komersial')->where('period_id', $period->getKey())->whereIn('asset_id', $ids)->pluck('asset_id')->flip();
        $prev = DB::table('depreciation_schedules as s')
            ->join('accounting_periods as p', fn ($j) => $j->on('p.id', '=', 's.period_id')->on('p.company_id', '=', 's.company_id'))
            ->where('s.company_id', $cid)->where('s.jenis', 'komersial')->whereIn('s.asset_id', $ids)->where('p.tanggal_mulai', '<', $start)
            ->groupBy('s.asset_id')->selectRaw('s.asset_id AS asset_id, COUNT(*) AS c, MAX(s.akumulasi) AS m')->get()->keyBy('asset_id');

        $plan = [];
        $tertunda = [];
        foreach ($assets as $a) {
            $n = $this->monthNumber($a->tanggal_mulai_susut->toDateString(), $start);
            if ($n > $a->umur_bulan || isset($sudah[$a->id])) {
                continue; // habis disusutkan atau sudah diproses
            }
            $p = $prev->get($a->id);
            if ((int) ($p->c ?? 0) !== $n - 1) {
                $tertunda[] = $a->kode;
                continue;
            }

            $dasar = Money::sub((string) $a->harga_perolehan, (string) $a->nilai_residu);
            $akumLalu = $p ? bcadd((string) $p->m, '0', 4) : '0.0000';
            $nilai = $n === (int) $a->umur_bulan ? Money::sub($dasar, $akumLalu) : bcdiv($dasar, (string) $a->umur_bulan, 4);
            if (Money::cmp(Money::add($akumLalu, $nilai), $dasar) > 0) {
                $nilai = Money::sub($dasar, $akumLalu); // jangan lewat residu
            }
            if (Money::cmp($nilai, '0') < 0) {
                $nilai = '0.0000';
            }
            $akum = Money::add($akumLalu, $nilai);

            $plan[(int) $a->category_id][] = [
                'asset_id' => (int) $a->id, 'kode' => $a->kode, 'nama' => $a->nama, 'bulan_ke' => $n,
                'nilai' => $nilai, 'akumulasi' => $akum, 'nilai_buku' => Money::sub((string) $a->harga_perolehan, $akum),
            ];
        }

        if ($tertunda !== []) {
            $daftar = implode(', ', array_slice($tertunda, 0, 10)) . (count($tertunda) > 10 ? ', ...' : '');
            throw new FixedAssetException("Ada aset yang belum disusutkan di periode sebelumnya: {$daftar}. Jalankan penyusutan periode sebelumnya dulu.", 'previous_period_not_run', 409);
        }
        ksort($plan);

        return $plan;
    }

    private function summarize(AccountingPeriod $period, array $plan): array
    {
        $rows = [];
        foreach ($plan as $categoryId => $list) {
            foreach ($list as $r) {
                $rows[] = $r + ['category_id' => $categoryId];
            }
        }

        return [
            'period_id' => (int) $period->getKey(), 'periode' => $period->tanggal_mulai->format('Y-m'),
            'jumlah_aset' => count($rows), 'total' => Money::sum(array_column($rows, 'nilai')), 'rincian' => $rows,
        ];
    }

    /** Bulan ke-n (1-based) dari dua tanggal awal bulan. */
    private function monthNumber(string $mulaiSusut, string $periodeMulai): int
    {
        $a = CarbonImmutable::parse($mulaiSusut);
        $b = CarbonImmutable::parse($periodeMulai);

        return ($b->year - $a->year) * 12 + ($b->month - $a->month) + 1;
    }
}
