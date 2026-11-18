<?php

namespace App\Modules\Accounting\Posting;

/** Pengganti sampai modul stok terpasang: dokumen tanpa gerakan stok jalan normal, dokumen bergerak stok ditolak jelas. */
final class NoStockMovement implements StockMovementApplier
{
    public function apply(string $sourceType, int $sourceId, string $tanggal, array $movements): StockMovementResult
    {
        if ($movements !== []) {
            throw PostingException::stockModuleMissing();
        }

        return StockMovementResult::none();
    }

    public function reverse(string $sourceType, int $sourceId, string $tanggal): void
    {
    }
}
