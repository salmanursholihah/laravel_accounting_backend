<?php

namespace App\Modules\Accounting\Ledger;

/** Pengganti bila modul stok tidak terpasang. */
final class NullOpeningStock implements OpeningStockPort
{
    public function draftAccountAmounts(): array
    {
        return [];
    }

    public function commit(int $journalId, string $tanggal, int $userId): void
    {
    }

    public function revert(int $journalId, string $tanggal): void
    {
    }
}
