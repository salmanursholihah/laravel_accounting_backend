<?php

namespace App\Modules\Accounting\Ledger;

/**
 * Masukan untuk JournalService::post(). Baris: ['account_id', 'debit', 'kredit', 'memo'?, 'contact_id'?].
 * tipe: general | auto | correction | opening | closing | reversal
 */
final class JournalDraft
{
    public const TYPES = ['general', 'auto', 'correction', 'opening', 'closing', 'reversal'];

    public function __construct(
        public string $tanggal,
        public string $tipe,
        public array $lines,
        public ?string $memo = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?int $reversedOfId = null,
        public ?int $branchId = null,
        public ?int $userId = null,
    ) {
    }
}
