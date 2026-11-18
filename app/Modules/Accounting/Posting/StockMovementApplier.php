<?php

namespace App\Modules\Accounting\Posting;

/**
 * Port ke inti stok (ALG-05). Orkestrator hanya tahu antarmuka ini; modul stock yang menulis stock_ledger dan
 * stock_balances dan mengikatnya ke container (Langkah 4). Sebelum itu dipakai NoStockMovement.
 */
interface StockMovementApplier
{
    /**
     * @param list<array{item_id:int, warehouse_id:int, arah:string, qty:string, harga_satuan?:?string}> $movements
     */
    public function apply(string $sourceType, int $sourceId, string $tanggal, array $movements): StockMovementResult;

    /** Membalik semua gerakan stok dokumen (nilai asli). Dipanggil void dokumen. */
    public function reverse(string $sourceType, int $sourceId, string $tanggal): void;
}
