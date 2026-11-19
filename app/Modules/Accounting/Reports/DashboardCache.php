<?php

namespace App\Modules\Accounting\Reports;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cache dashboard per perusahaan dengan kunci berversi. Versi diganti (bump) setiap ada posting atau void,
 * sehingga semua blok lama otomatis tidak terpakai tanpa perlu menghapus kunci satu per satu. TTL pendek menjadi
 * pengaman bila bump terlewat (mis. perubahan data di luar posting). Kunci selalu memuat company_id.
 */
class DashboardCache
{
    public const TTL = 300;

    public static function version(int $companyId): string
    {
        return (string) Cache::get(self::versionKey($companyId), 'v0');
    }

    /**
     * Ganti versi sekarang (agar pembaca setelah posting tidak memakai cache lama) dan sekali lagi setelah commit
     * (agar pembaca yang menyimpan data lama saat transaksi masih terbuka tidak bertahan di bawah versi baru).
     */
    public static function bump(int $companyId): void
    {
        Cache::forever(self::versionKey($companyId), (string) Str::uuid());
        DB::afterCommit(fn () => Cache::forever(self::versionKey($companyId), (string) Str::uuid()));
    }

    public static function remember(int $companyId, string $block, \Closure $compute, string $suffix = ''): mixed
    {
        $key = "dash:{$companyId}:" . self::version($companyId) . ":{$block}:{$suffix}";

        return Cache::remember($key, self::TTL, $compute);
    }

    private static function versionKey(int $companyId): string
    {
        return "dash:ver:{$companyId}";
    }
}
