<?php

namespace App\Modules\CashBank\Reconciliation;

use DateTimeImmutable;

/**
 * ALG-09 (analisis hlm. 38), bagian pencocokan satu-ke-satu. Murni, tanpa database.
 *
 * Kandidat: arah sama dan nominal SAMA PERSIS dan selisih hari <= toleransi.
 * Skor = 0.5 (nominal) + 0.3 * (1 - selisihHari / toleransi) + 0.2 * tokenOverlap.
 * Pilihan satu-ke-satu: semua pasangan diurutkan skor menurun (seri: selisih hari kecil, lalu id kecil), diambil serakah.
 * skor >= 0.8 -> cocok otomatis; 0.5 <= skor < 0.8 -> saran (tidak disimpan).
 *
 * Asumsi saya (analisis tidak merinci): tokenOverlap = bagian token referensi buku yang muncul di deskripsi koran
 * (|A ∩ B| / |B|, B = token referensi). Token = huruf/angka huruf kecil, panjang >= 2.
 * Kasus 1-ke-banyak (subset maks 5) TIDAK dikerjakan: unique uq_ri_stmt membatasi satu baris koran ke satu item.
 */
final class ReconciliationMatcher
{
    public const AUTO = 0.8;
    public const SUGGEST = 0.5;
    private const EPS = 1e-9;

    /** @return list<string> */
    public static function tokens(?string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($t) => strlen($t) >= 2)));
    }

    public static function overlap(?string $deskripsiKoran, ?string $referensiBuku): float
    {
        $ref = self::tokens($referensiBuku);
        if ($ref === []) {
            return 0.0;
        }
        $hit = count(array_intersect($ref, self::tokens($deskripsiKoran)));

        return $hit / count($ref);
    }

    public static function score(int $dayDiff, int $toleransi, float $overlap): float
    {
        $dekat = $toleransi > 0 ? 1 - ($dayDiff / $toleransi) : 1.0; // toleransi 0: hanya tanggal sama yang lolos
        $skor = 0.5 + 0.3 * $dekat + 0.2 * $overlap;

        return round($skor, 4);
    }

    public static function dayDiff(string $a, string $b): int
    {
        return (int) (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days;
    }

    /**
     * @param list<array{id:int,tanggal:string,arah:string,nominal:string,deskripsi:?string}> $stmt baris koran belum cocok
     * @param list<array{id:int,tanggal:string,arah:string,nominal:string,referensi:?string}> $book baris jurnal bank belum cocok
     * @return array{auto: list<array>, saran: list<array>} tiap elemen: stmt_id, journal_line_id, skor, hari
     */
    public static function plan(array $stmt, array $book, int $toleransi): array
    {
        $index = [];
        foreach ($book as $b) {
            $index[$b['arah'] . '|' . self::norm($b['nominal'])][] = $b;
        }

        $pairs = [];
        foreach ($stmt as $s) {
            foreach ($index[$s['arah'] . '|' . self::norm($s['nominal'])] ?? [] as $b) {
                $hari = self::dayDiff($s['tanggal'], $b['tanggal']);
                if ($hari > $toleransi) {
                    continue;
                }
                $pairs[] = [
                    'stmt_id' => $s['id'], 'journal_line_id' => $b['id'], 'hari' => $hari,
                    'skor' => self::score($hari, $toleransi, self::overlap($s['deskripsi'], $b['referensi'])),
                ];
            }
        }

        usort($pairs, fn ($x, $y) => [$y['skor'], $x['hari'], $x['stmt_id'], $x['journal_line_id']] <=> [$x['skor'], $y['hari'], $y['stmt_id'], $y['journal_line_id']]);

        $usedS = [];
        $usedB = [];
        $auto = [];
        $saran = [];
        foreach ($pairs as $p) {
            if (isset($usedS[$p['stmt_id']]) || isset($usedB[$p['journal_line_id']])) {
                continue;
            }
            if ($p['skor'] + self::EPS < self::SUGGEST) {
                continue;
            }
            $usedS[$p['stmt_id']] = true;
            $usedB[$p['journal_line_id']] = true;
            if ($p['skor'] + self::EPS >= self::AUTO) {
                $auto[] = $p;
            } else {
                $saran[] = $p;
            }
        }

        return ['auto' => $auto, 'saran' => $saran];
    }

    /** Kunci nominal yang stabil terhadap '5000000' vs '5000000.0000'. */
    private static function norm(string $n): string
    {
        return bcadd($n, '0', 4);
    }
}
