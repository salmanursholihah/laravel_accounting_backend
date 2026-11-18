<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $mesinDbSudahDicek = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Tes lama mengandaikan master Pajak/Syarat kosong; data bawaan diuji khusus di SeedTaxAndTermDefaultsTest.
        config(['tax_defaults.seed_on_company_created' => false]);

        if (! self::$mesinDbSudahDicek) {
            $this->pastikanMySqlAsli();
            self::$mesinDbSudahDicek = true;
        }
    }

    /**
     * Migrasi memakai CHECK, trigger immutable, dan collation utf8mb4_0900_ai_ci,
     * jadi tes hanya sah di MySQL asli 8.0.16+ dengan isolation READ COMMITTED
     * (Tahap 8 bagian 5). MariaDB / SQLite / MySQL lama dihentikan di sini.
     */
    private function pastikanMySqlAsli(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertSame('mysql', $driver, "Tes harus memakai koneksi MySQL, bukan '{$driver}'.");

        $row = DB::selectOne('SELECT VERSION() AS v, @@version_comment AS c, @@transaction_isolation AS i');
        $versi = (string) $row->v;
        $komentar = (string) $row->c;

        $this->assertFalse(
            stripos($versi, 'mariadb') !== false || stripos($komentar, 'mariadb') !== false,
            "Tes harus memakai MySQL asli, bukan MariaDB (terdeteksi: {$versi} / {$komentar})."
        );
        $this->assertTrue(
            version_compare(explode('-', $versi)[0], '8.0.16', '>='),
            "Dibutuhkan MySQL 8.0.16+ untuk CHECK constraint yang ditegakkan (terdeteksi: {$versi})."
        );
        $this->assertSame(
            'READ-COMMITTED',
            (string) $row->i,
            'Koneksi harus READ COMMITTED (config/database.php, options PDO init command).'
        );
    }
}
