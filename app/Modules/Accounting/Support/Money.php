<?php

namespace App\Modules\Accounting\Support;

/**
 * Aritmetika uang dengan BCMath (string desimal, tanpa float). Pembulatan: half-up (menjauhi nol).
 * Skala dalam: 10 digit; uang disimpan 4 digit (DECIMAL(19,4)); pajak dibulatkan ke rupiah.
 */
final class Money
{
    public const SCALE = 10;
    public const MONEY = 4;

    public static function add(string $a, string $b): string { return bcadd($a, $b, self::SCALE); }
    public static function sub(string $a, string $b): string { return bcsub($a, $b, self::SCALE); }
    public static function mul(string $a, string $b): string { return bcmul($a, $b, self::SCALE); }
    public static function div(string $a, string $b): string { return bcdiv($a, $b, self::SCALE); }
    public static function cmp(string $a, string $b): int { return bccomp($a, $b, self::SCALE); }
    public static function isZero(string $a): bool { return bccomp($a, '0', self::SCALE) === 0; }

    public static function sum(iterable $values): string
    {
        $t = '0';
        foreach ($values as $v) {
            $t = bcadd($t, (string) $v, self::SCALE);
        }

        return $t;
    }

    /** Bulatkan half-up ke $places desimal; hasil selalu string berskala $places. */
    public static function round(string $value, int $places = self::MONEY): string
    {
        $half = '0.' . str_repeat('0', $places) . '5';
        if ($places === 0) {
            $half = '0.5';
        }
        $negative = bccomp($value, '0', self::SCALE) < 0;
        $r = $negative ? bcsub($value, $half, $places) : bcadd($value, $half, $places);

        // bcadd/bcsub memotong, bukan membulatkan; -0.0000 dinormalkan
        return bccomp($r, '0', $places) === 0 ? bcadd('0', '0', $places) : $r;
    }
}
