<?php

namespace Tests\Feature\CashBank;

use App\Modules\CashBank\Reconciliation\ReconciliationMatcher as M;
use PHPUnit\Framework\TestCase;

/** ALG-09 murni (tanpa database). BELUM pernah dijalankan oleh penulisnya. */
class ReconciliationMatcherTest extends TestCase
{
    private function s(int $id, string $tgl, string $arah, string $nominal, ?string $desk = null): array
    {
        return ['id' => $id, 'tanggal' => $tgl, 'arah' => $arah, 'nominal' => $nominal, 'deskripsi' => $desk];
    }

    private function b(int $id, string $tgl, string $arah, string $nominal, ?string $ref = null): array
    {
        return ['id' => $id, 'tanggal' => $tgl, 'arah' => $arah, 'nominal' => $nominal, 'referensi' => $ref];
    }

    public function test_token_dan_overlap(): void
    {
        $this->assertSame(['trf', 'cv', 'sinar'], M::tokens('TRF - CV Sinar! x'));
        $this->assertEqualsWithDelta(1 / 3, M::overlap('TRF CV SINAR', 'KM 00033 sinar'), 1e-9); // 1 dari 3 token referensi
        $this->assertSame(0.0, M::overlap('apa saja', null));
    }

    public function test_skor(): void
    {
        $this->assertSame(0.8, M::score(0, 3, 0.0));
        $this->assertSame(1.0, M::score(0, 3, 1.0));
        $this->assertSame(0.6, M::score(2, 3, 0.0));
        $this->assertSame(0.5, M::score(3, 3, 0.0));
        $this->assertSame(0.8, M::score(0, 0, 0.0)); // toleransi 0 tidak membagi nol
    }

    public function test_alg09_dua_penerimaan_nominal_sama_beda_tanggal_tidak_tertukar(): void
    {
        // koran urut terbalik terhadap buku; semua dalam toleransi 3 hari
        $stmt = [$this->s(1, '2026-10-04', 'masuk', '1000000'), $this->s(2, '2026-10-02', 'masuk', '1000000')];
        $book = [$this->b(10, '2026-10-02', 'masuk', '1000000'), $this->b(11, '2026-10-04', 'masuk', '1000000')];

        $r = M::plan($stmt, $book, 3);
        $pairs = collect($r['auto'])->mapWithKeys(fn ($p) => [$p['stmt_id'] => $p['journal_line_id']])->all();
        $this->assertSame([1 => 11, 2 => 10], $pairs);
        $this->assertSame([], $r['saran']);
    }

    public function test_syarat_kandidat(): void
    {
        $stmt = [$this->s(1, '2026-10-02', 'masuk', '1000000')];
        $this->assertSame([], M::plan($stmt, [$this->b(10, '2026-10-02', 'masuk', '1000001')], 3)['auto']);   // nominal beda 1 rupiah
        $this->assertSame([], M::plan($stmt, [$this->b(10, '2026-10-02', 'keluar', '1000000')], 3)['auto']);  // arah beda
        $this->assertSame([], M::plan($stmt, [$this->b(10, '2026-10-06', 'masuk', '1000000')], 3)['auto']);   // 4 hari > toleransi
        $this->assertCount(1, M::plan($stmt, [$this->b(10, '2026-10-02', 'masuk', '1000000.0000')], 3)['auto']); // format nominal berbeda
    }

    public function test_ambang_otomatis_dan_saran(): void
    {
        $stmt = [$this->s(1, '2026-10-02', 'masuk', '500')];
        $dua = M::plan($stmt, [$this->b(10, '2026-10-04', 'masuk', '500')], 3); // skor 0.6
        $this->assertSame([], $dua['auto']);
        $this->assertCount(1, $dua['saran']);
        $this->assertSame(0.6, $dua['saran'][0]['skor']);

        $tiga = M::plan($stmt, [$this->b(10, '2026-10-05', 'masuk', '500')], 3); // skor 0.5 tepat = saran
        $this->assertCount(1, $tiga['saran']);

        $ref = M::plan([$this->s(1, '2026-10-04', 'masuk', '500', 'TRF KM/2026/10/00033')], [$this->b(10, '2026-10-02', 'masuk', '500', 'KM/2026/10/00033')], 3);
        $this->assertCount(1, $ref['auto']); // 0.5 + 0.1 + 0.2 = 0.8
    }

    public function test_satu_ke_satu(): void
    {
        $r = M::plan(
            [$this->s(1, '2026-10-02', 'masuk', '700'), $this->s(2, '2026-10-02', 'masuk', '700')],
            [$this->b(10, '2026-10-02', 'masuk', '700')],
            3
        );
        $this->assertCount(1, $r['auto']);
        $this->assertSame(1, $r['auto'][0]['stmt_id']); // seri: id koran terkecil
    }
}
