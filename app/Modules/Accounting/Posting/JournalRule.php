<?php

namespace App\Modules\Accounting\Posting;

use InvalidArgumentException;

/**
 * Satu baris aturan jurnal (ALG-04): (doc_type, kondisi, sisi, kunci akun sistem, sumber nilai).
 * Aturan adalah DATA yang didaftarkan modul bisnis; satu mesin yang menafsirkannya.
 *
 *  side        'D' (debit) atau 'K' (kredit)
 *  accountKey  kunci akun sistem (config/system_accounts.php), bukan nomor akun
 *  amount      ekspresi nilai atas konteks: "total", "subtotal-diskon", "pajak.ppn+pajak.ppnbm".
 *              Hanya + dan -. Operan = angka literal atau jalur di konteks 'values'.
 *  when        nama flag (konteks 'flags') atau nilai (konteks 'values') yang harus benar/bukan nol
 *  group       aturan satu group menjadi satu jurnal (mis. 'main' = penjualan, 'cogs' = HPP)
 *  each        bila diisi: nama daftar di konteks 'lists'; satu baris per item
 *              ['amount', 'account_id'?, 'account_key'?, 'contact_id'?, 'memo'?]; $amount diabaikan
 *  withContact baris membawa kontak dokumen (subledger piutang/utang)
 */
final class JournalRule
{
    public function __construct(
        public readonly string $docType,
        public readonly string $side,
        public readonly string $accountKey,
        public readonly string $amount,
        public readonly ?string $when = null,
        public readonly string $group = 'main',
        public readonly ?string $each = null,
        public readonly bool $withContact = false,
        public readonly ?string $memo = null,
    ) {
        if (! in_array($side, ['D', 'K'], true)) {
            throw new InvalidArgumentException("Sisi aturan jurnal harus 'D' atau 'K', bukan '{$side}'.");
        }
        if (! config("system_accounts.{$accountKey}")) {
            throw new InvalidArgumentException("Kunci akun sistem '{$accountKey}' tidak dikenal.");
        }
    }
}
