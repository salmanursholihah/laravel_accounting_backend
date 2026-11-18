<?php

namespace App\Modules\Accounting\Ledger;

/**
 * Port ke saldo awal stok (modul stock). Saldo awal stok TIDAK membuat jurnal sendiri: nilainya ikut masuk ke SATU
 * jurnal saldo awal (OpeningBalanceService) sebagai debit akun persediaan, sehingga buku besar dan stock_ledger
 * selalu sama dan invarian "satu jurnal saldo awal aktif" tetap berlaku.
 */
interface OpeningStockPort
{
    /** Nilai draf saldo awal stok per akun persediaan: [account_id => nilai (DECIMAL string)]. Kosong bila tidak ada draf. */
    public function draftAccountAmounts(): array;

    /** Dipanggil SETELAH jurnal saldo awal diposting (dalam transaksi yang sama): mencatat gerakan stok masuk. */
    public function commit(int $journalId, string $tanggal, int $userId): void;

    /** Dipanggil SETELAH jurnal pembalik saldo awal diposting: membalik gerakan stok; baris kembali menjadi draf. */
    public function revert(int $journalId, string $tanggal): void;
}
