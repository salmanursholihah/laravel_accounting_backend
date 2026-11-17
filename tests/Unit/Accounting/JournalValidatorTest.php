<?php

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Ledger\JournalException;
use App\Modules\Accounting\Ledger\JournalValidator;
use PHPUnit\Framework\TestCase;

/** Aturan 1 dan 2 (murni, tanpa database). */
class JournalValidatorTest extends TestCase
{
    private function reject(array $lines, string $code): void
    {
        try {
            JournalValidator::normalizeLines($lines);
            $this->fail("Seharusnya ditolak dengan kode {$code}");
        } catch (JournalException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_jurnal_seimbang_dinormalkan_ke_4_desimal(): void
    {
        $r = JournalValidator::normalizeLines([
            ['account_id' => 1, 'debit' => '11100000'],
            ['account_id' => 2, 'kredit' => 10000000],
            ['account_id' => 3, 'kredit' => '1100000.00'],
        ]);

        $this->assertSame('11100000.0000', $r['total_debit']);
        $this->assertSame('11100000.0000', $r['total_kredit']);
        $this->assertSame([1, 2, 3], array_column($r['lines'], 'line_no'));
        $this->assertSame('0.0000', $r['lines'][1]['debit']);
    }

    public function test_selisih_satu_rupiah_ditolak(): void
    {
        $this->reject([['account_id' => 1, 'debit' => '1000'], ['account_id' => 2, 'kredit' => '999']], 'unbalanced');
    }

    public function test_selisih_sangat_kecil_tetap_ditolak_dan_float_tidak_menipu(): void
    {
        $this->reject([['account_id' => 1, 'debit' => '0.0001'], ['account_id' => 2, 'kredit' => '0.0002']], 'unbalanced');

        // 0.1 + 0.2 sebagai float tidak boleh meloloskan jurnal yang sebenarnya tidak seimbang
        $ok = JournalValidator::normalizeLines([
            ['account_id' => 1, 'debit' => 0.1], ['account_id' => 2, 'debit' => 0.2], ['account_id' => 3, 'kredit' => 0.3],
        ]);
        $this->assertSame('0.3000', $ok['total_debit']);
    }

    public function test_minimal_dua_baris(): void
    {
        $this->reject([['account_id' => 1, 'debit' => '100']], 'too_few_lines');
        $this->reject([], 'too_few_lines');
    }

    public function test_satu_baris_hanya_satu_sisi_dan_tidak_boleh_kosong(): void
    {
        $this->reject([['account_id' => 1, 'debit' => '5', 'kredit' => '5'], ['account_id' => 2, 'kredit' => '0']], 'line_both_sides');
        $this->reject([['account_id' => 1, 'debit' => '5'], ['account_id' => 2]], 'line_empty');
        $this->reject([['account_id' => 1, 'debit' => '0'], ['account_id' => 2, 'kredit' => '0']], 'line_empty');
    }

    public function test_nominal_negatif_atau_lebih_dari_4_desimal_atau_bukan_angka_ditolak(): void
    {
        $this->reject([['account_id' => 1, 'debit' => '-5'], ['account_id' => 2, 'kredit' => '5']], 'invalid_amount');
        $this->reject([['account_id' => 1, 'debit' => '1.00001'], ['account_id' => 2, 'kredit' => '1.00001']], 'invalid_amount');
        $this->reject([['account_id' => 1, 'debit' => '1e3'], ['account_id' => 2, 'kredit' => '1000']], 'invalid_amount');
        $this->reject([['account_id' => 1, 'debit' => 'abc'], ['account_id' => 2, 'kredit' => '1']], 'invalid_amount');
    }

    public function test_akun_wajib_ada_di_setiap_baris(): void
    {
        $this->reject([['debit' => '5'], ['account_id' => 2, 'kredit' => '5']], 'account_not_found');
    }
}
