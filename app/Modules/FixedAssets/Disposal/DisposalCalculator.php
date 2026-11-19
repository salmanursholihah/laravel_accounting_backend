<?php

namespace App\Modules\FixedAssets\Disposal;

use App\Modules\Accounting\Support\Money;
use App\Modules\FixedAssets\FixedAssetException;
use App\Modules\FixedAssets\Models\AssetCategory;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Angka disposisi (ALG-08 bagian disposisi, Tahap 4 bagian 6). Dipakai pratinjau, validasi, dan posting supaya sama.
 *
 * Aturan: penyusutan SAMPAI BULAN SEBELUM bulan pelepasan harus sudah dijalankan penuh (bulan pelepasan sendiri tidak disusutkan;
 * bila periode itu sudah terlanjur disusutkan, akumulasinya ikut dihitung). Jadwal penyusutan di bulan SETELAH pelepasan
 * menolak disposisi (pembatalan penyusutan belum ada). Laba/rugi = nilai pelepasan - nilai buku.
 */
class DisposalCalculator
{
    public function __construct(private CurrentCompany $current)
    {
    }

    /** @return array{harga_perolehan:string, akumulasi:string, nilai_buku:string, laba_rugi:string, bulan_disusutkan:int} */
    public function figures(FixedAsset $a, string $tanggal, string $nilaiPelepasan): array
    {
        if ($tanggal < $a->tanggal_perolehan->toDateString()) {
            throw new FixedAssetException('Tanggal pelepasan tidak boleh sebelum tanggal perolehan aset.', 'disposal_before_acquisition');
        }

        $awalBulan = CarbonImmutable::parse($tanggal)->startOfMonth();
        $n = ($awalBulan->year - $a->tanggal_mulai_susut->year) * 12 + ($awalBulan->month - $a->tanggal_mulai_susut->month) + 1; // bulan ke-n
        $wajib = max(0, min((int) $a->umur_bulan, $n - 1));

        $rows = DB::table('depreciation_schedules as s')
            ->join('accounting_periods as p', fn ($j) => $j->on('p.id', '=', 's.period_id')->on('p.company_id', '=', 's.company_id'))
            ->where('s.company_id', $this->current->id())->where('s.asset_id', $a->getKey())->where('s.jenis', 'komersial')
            ->get(['p.tanggal_mulai', 's.akumulasi']);

        $awal = $awalBulan->toDateString();
        $sebelum = $rows->filter(fn ($r) => (string) $r->tanggal_mulai < $awal)->count();
        $sesudah = $rows->filter(fn ($r) => (string) $r->tanggal_mulai > $awal)->count();

        if ($sesudah > 0) {
            throw new FixedAssetException('Aset sudah disusutkan sampai setelah bulan pelepasan. Gunakan tanggal pelepasan yang lebih akhir (pembatalan penyusutan belum tersedia).', 'depreciation_after_disposal', 409);
        }
        if ($sebelum !== $wajib) {
            throw new FixedAssetException("Penyusutan sampai bulan sebelum pelepasan belum lengkap ({$sebelum} dari {$wajib} bulan). Jalankan Penyusutan periode sebelumnya dulu.", 'depreciation_not_run', 409);
        }

        $akum = '0.0000';
        foreach ($rows as $r) {
            if (Money::cmp((string) $r->akumulasi, $akum) > 0) {
                $akum = Money::round((string) $r->akumulasi);
            }
        }

        $harga = Money::round((string) $a->harga_perolehan);
        $buku = Money::sub($harga, $akum);

        return [
            'harga_perolehan' => $harga, 'akumulasi' => $akum, 'nilai_buku' => $buku,
            'laba_rugi' => Money::sub(Money::round($nilaiPelepasan), $buku), 'bulan_disusutkan' => $rows->count(),
        ];
    }

    /**
     * Daftar baris jurnal untuk mesin ALG-04 (lists 'debit' dan 'kredit'); nol dilewati mesin.
     * Dr Penerimaan . Dr Akumulasi . Dr Rugi pelepasan // Cr Aset . Cr Laba pelepasan.
     *
     * @return array{debit:list<array>, kredit:list<array>}
     */
    public function journalLists(AssetCategory $cat, array $f, string $nilaiPelepasan, ?int $akunPenerimaanId, string $label): array
    {
        $debit = [];
        $kredit = [];

        if (Money::cmp($nilaiPelepasan, '0') > 0) {
            $debit[] = ['amount' => Money::round($nilaiPelepasan), 'account_id' => $akunPenerimaanId, 'memo' => "Hasil pelepasan {$label}"];
        }
        $debit[] = ['amount' => $f['akumulasi'], 'account_id' => (int) $cat->akun_akumulasi_id, 'memo' => "Akumulasi penyusutan {$label}"];
        if (Money::cmp($f['laba_rugi'], '0') < 0) {
            $debit[] = ['amount' => Money::sub('0', $f['laba_rugi']), 'account_key' => 'laba_rugi_pelepasan_aset', 'memo' => "Rugi pelepasan {$label}"];
        }

        $kredit[] = ['amount' => $f['harga_perolehan'], 'account_id' => (int) $cat->akun_aset_id, 'memo' => "Harga perolehan {$label}"];
        if (Money::cmp($f['laba_rugi'], '0') > 0) {
            $kredit[] = ['amount' => $f['laba_rugi'], 'account_key' => 'laba_rugi_pelepasan_aset', 'memo' => "Laba pelepasan {$label}"];
        }

        return ['debit' => $debit, 'kredit' => $kredit];
    }
}
