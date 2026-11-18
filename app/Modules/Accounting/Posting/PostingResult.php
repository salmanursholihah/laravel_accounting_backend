<?php

namespace App\Modules\Accounting\Posting;

/** Hasil posting: dipakai handler untuk markPosted() dan dikembalikan ke pemanggil. */
final class PostingResult
{
    /**
     * @param array|null $taxes keluaran TaxCalculator::calculate() (null bila dokumen tanpa pajak)
     * @param list<int> $journalIds
     */
    public function __construct(
        public readonly string $nomor,
        public readonly ?array $taxes,
        public readonly array $journalIds,
        public readonly StockMovementResult $stock,
    ) {
    }
}
