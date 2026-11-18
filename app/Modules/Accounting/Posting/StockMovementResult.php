<?php

namespace App\Modules\Accounting\Posting;

/**
 * Hasil pergerakan stok sebuah dokumen (ALG-05), dipakai handler untuk menyusun nilai jurnal HPP/persediaan.
 * $lines: satu elemen per gerakan: ['item_id', 'warehouse_id', 'arah' => 'in'|'out', 'qty', 'harga_satuan', 'nilai'].
 */
final class StockMovementResult
{
    public function __construct(
        public readonly array $lines = [],
        public readonly string $nilaiMasuk = '0',
        public readonly string $nilaiKeluar = '0',
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
