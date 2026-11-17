<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;

/**
 * ALG-03: perhitungan pajak dan total dokumen. Murni hitung (tidak menyimpan apa pun).
 *
 * Masukan $doc:
 *  tanggal                   'YYYY-MM-DD' (menentukan versi tarif)
 *  harga_termasuk_pajak      bool
 *  diskon_header             nominal (string) ATAU diskon_header_persen
 *  ongkir                    nominal, tanpa pajak (opsional)
 *  lines[]                   qty, harga, diskon_persen (opsional), tax_code_id atau tax_code_ids[] (opsional)
 *
 * Aturan: netto baris = qty x harga x (1 - diskon%); diskon header dibagi proporsional ke baris sebelum
 * pajak; harga termasuk pajak dihitung mundur (dasar = netto / (1 + sum tarif efektif)); DPP = dasar x
 * pembilang/penyebut; pajak dibulatkan ke rupiah PER DOKUMEN PER KODE (bukan per baris); selisih pembulatan
 * total masuk ke akun `pembulatan`, sehingga: total = dasar + pajak + ongkir + pembulatan (tepat).
 * Perusahaan non-PKP: PPN dan PPnBM = 0.
 */
class TaxCalculator
{
    public function __construct(private TaxCodeService $taxes, private CurrentCompany $current)
    {
    }

    public function calculate(array $doc): array
    {
        $tanggal = (string) $doc['tanggal'];
        $inclusive = (bool) ($doc['harga_termasuk_pajak'] ?? false);
        $pkp = (bool) $this->current->company()->is_pkp;
        $lines = array_values($doc['lines'] ?? []);

        // 1. netto per baris
        $netto = [];
        foreach ($lines as $i => $l) {
            $diskon = (string) ($l['diskon_persen'] ?? '0');
            if (Money::cmp((string) $l['qty'], '0') < 0 || Money::cmp((string) $l['harga'], '0') < 0
                || Money::cmp($diskon, '0') < 0 || Money::cmp($diskon, '100') > 0) {
                throw new TaxException('Qty, harga, atau diskon baris tidak valid.');
            }
            $faktor = Money::sub('1', Money::div($diskon, '100'));
            $netto[$i] = Money::round(Money::mul(Money::mul((string) $l['qty'], (string) $l['harga']), $faktor));
        }
        $subtotalKotor = Money::round(Money::sum($netto));

        // 2. diskon header proporsional (baris terakhir menyerap sisa agar jumlahnya tepat)
        $diskonHeader = $this->headerDiscount($doc, $subtotalKotor);
        $nettoSetelah = $this->allocate($netto, $diskonHeader, $subtotalKotor);

        // 3. kode pajak per baris (versi sesuai tanggal dokumen)
        $codeCache = [];
        $perLine = [];
        $dppTotal = [];   // kode => dpp (skala dalam)
        $dasarLines = []; // baris => dasar tanpa pajak

        foreach ($lines as $i => $l) {
            $ids = $l['tax_code_ids'] ?? (empty($l['tax_code_id']) ? [] : [$l['tax_code_id']]);
            $codes = [];
            foreach ($ids as $id) {
                $code = $codeCache[$id] ??= $this->taxes->resolveAt((int) $id, $tanggal);
                if (! $pkp && in_array($code->jenis, ['ppn', 'ppnbm'], true)) {
                    continue; // non-PKP: tanpa PPN/PPnBM
                }
                if (! $code->is_aktif) {
                    throw new TaxException("Kode pajak {$code->kode} nonaktif.");
                }
                $codes[] = $code;
            }

            $sumEfektif = '0';
            foreach ($codes as $c) {
                $sumEfektif = Money::add($sumEfektif, $this->efektif($c));
            }

            $base = ($inclusive && $codes)
                ? Money::div($nettoSetelah[$i], Money::add('1', $sumEfektif))
                : $nettoSetelah[$i];
            $dasarLines[$i] = $base;

            $perLine[$i] = ['netto' => $netto[$i], 'netto_setelah_diskon' => $nettoSetelah[$i], 'codes' => []];
            foreach ($codes as $c) {
                $dpp = Money::div(Money::mul($base, (string) $c->dpp_pembilang), (string) $c->dpp_penyebut);
                $dppTotal[$c->kode] = Money::add($dppTotal[$c->kode] ?? '0', $dpp);
                $perLine[$i]['codes'][$c->kode] = $dpp;
            }
        }

        // 4. pajak per dokumen per kode, dibulatkan ke rupiah
        $pajak = [];
        foreach ($dppTotal as $kode => $dpp) {
            $c = $this->codeByKode($codeCache, $kode);
            $pajak[$kode] = [
                'kode' => $kode, 'jenis' => $c->jenis, 'tax_code_id' => $c->id, 'tarif' => (string) $c->tarif,
                'dpp_pembilang' => $c->dpp_pembilang, 'dpp_penyebut' => $c->dpp_penyebut,
                'dpp' => Money::round($dpp, 0),
                'dpp_presisi' => Money::round($dpp),
                'pajak' => Money::round(Money::mul((string) $c->tarif, $dpp), 0),
                'akun_penjualan_id' => $c->akun_penjualan_id, 'akun_pembelian_id' => $c->akun_pembelian_id,
            ];
        }

        // 5. rincian per baris (untuk document_taxes): pajak dokumen dibagi menurut porsi DPP, sisa ke baris terakhir
        $lineResults = [];
        $remaining = array_map(fn ($p) => $p['pajak'], $pajak);
        $lastIndex = [];
        foreach ($perLine as $i => $pl) {
            foreach (array_keys($pl['codes']) as $kode) {
                $lastIndex[$kode] = $i;
            }
        }
        foreach ($perLine as $i => $pl) {
            $taxes = [];
            foreach ($pl['codes'] as $kode => $dpp) {
                if ($lastIndex[$kode] === $i) {
                    $nominal = $remaining[$kode];
                } else {
                    $nominal = Money::round(Money::div(Money::mul($pajak[$kode]['pajak'], $dpp), $dppTotal[$kode]));
                }
                $remaining[$kode] = Money::sub($remaining[$kode], $nominal);
                $taxes[] = [
                    'kode' => $kode, 'tax_code_id' => $pajak[$kode]['tax_code_id'],
                    'dpp' => Money::round($dpp), 'tarif' => $pajak[$kode]['tarif'], 'pajak' => Money::round($nominal),
                ];
            }
            $lineResults[$i] = [
                'netto' => $pl['netto'], 'netto_setelah_diskon' => $pl['netto_setelah_diskon'],
                'dasar' => Money::round($dasarLines[$i]), 'pajak' => $taxes,
            ];
        }

        // 6. total, pembulatan
        $dasar = Money::round(Money::sum($dasarLines));
        $totalPajak = Money::round(Money::sum(array_column($pajak, 'pajak')));
        $ongkir = Money::round((string) ($doc['ongkir'] ?? '0'));

        $totalMentah = $inclusive
            ? Money::add(Money::sum($nettoSetelah), $ongkir)
            : Money::add(Money::add($dasar, $totalPajak), $ongkir);
        $total = Money::round($totalMentah, 0);
        $pembulatan = Money::round(Money::sub(Money::sub(Money::sub($total, $dasar), $totalPajak), $ongkir));

        return [
            'harga_termasuk_pajak' => $inclusive,
            'is_pkp' => $pkp,
            'lines' => $lineResults,
            'subtotal' => $subtotalKotor,
            'diskon_header' => Money::round($diskonHeader),
            'dasar' => $dasar,
            // DPP dokumen = DPP kelompok PPN (PPnBM punya DPP sendiri di $pajak[kode]['dpp'])
            'dpp' => Money::round(Money::sum(array_column(array_filter($pajak, fn ($p) => $p['jenis'] === 'ppn'), 'dpp'))),
            'pajak' => $pajak,
            'total_pajak' => $totalPajak,
            'ongkir' => $ongkir,
            'pembulatan' => $pembulatan,
            'total' => Money::round($total),
        ];
    }

    private function efektif(TaxCode $c): string
    {
        return Money::div(Money::mul((string) $c->tarif, (string) $c->dpp_pembilang), (string) $c->dpp_penyebut);
    }

    private function codeByKode(array $cache, string $kode): TaxCode
    {
        foreach ($cache as $c) {
            if ($c->kode === $kode) {
                return $c;
            }
        }
        throw new TaxException("Kode pajak {$kode} tidak ditemukan."); // tidak terjadi: kode berasal dari cache
    }

    private function headerDiscount(array $doc, string $subtotal): string
    {
        $d = '0';
        if (isset($doc['diskon_header_persen']) && Money::cmp((string) $doc['diskon_header_persen'], '0') > 0) {
            $d = Money::round(Money::div(Money::mul($subtotal, (string) $doc['diskon_header_persen']), '100'));
        } elseif (isset($doc['diskon_header'])) {
            $d = (string) $doc['diskon_header'];
        }

        if (Money::cmp($d, '0') < 0 || Money::cmp($d, $subtotal) > 0) {
            throw new TaxException('Diskon header tidak valid (negatif atau melebihi subtotal).');
        }

        return $d;
    }

    /** @param array<int,string> $netto */
    private function allocate(array $netto, string $diskon, string $subtotal): array
    {
        if (Money::isZero($diskon) || Money::isZero($subtotal)) {
            return $netto;
        }

        $result = [];
        $sisa = $diskon;
        $keys = array_keys($netto);
        $last = end($keys);
        foreach ($netto as $i => $n) {
            $share = ($i === $last) ? $sisa : Money::round(Money::div(Money::mul($diskon, $n), $subtotal));
            $sisa = Money::sub($sisa, $share);
            $result[$i] = Money::round(Money::sub($n, $share));
        }

        return $result;
    }
}
