<?php

namespace App\Modules\CashBank\Posting;

use App\Models\User;
use App\Modules\Accounting\Posting\AbstractPostingHandler;
use App\Modules\Accounting\Posting\JournalRule;
use App\Modules\Accounting\Posting\PostingResult;
use App\Modules\Accounting\Posting\StockMovementResult;
use App\Modules\Accounting\Support\Money;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\CashBank\Models\BankTransfer;
use Illuminate\Database\Eloquent\Model;

/** Transfer Bank: Dr Bank Tujuan (nominal) · Dr Beban Admin Bank (biaya) · Cr Bank Asal (nominal + biaya). */
class BankTransferPostingHandler extends AbstractPostingHandler
{
    public function journalRules(): array
    {
        return [
            new JournalRule('bank_transfer', 'D', 'beban_admin_bank', '0', each: 'debit'),
            new JournalRule('bank_transfer', 'K', 'beban_admin_bank', '0', each: 'kredit'),
        ];
    }

    public function docType(): string
    {
        return 'bank_transfer';
    }

    public function feature(): string
    {
        return 'transfer_bank';
    }

    public function find(int $id, bool $lock = false): Model
    {
        $q = BankTransfer::query();
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->findOrFail($id);
    }

    public function tanggal(Model $doc): string
    {
        return $doc->tanggal->toDateString();
    }

    public function status(Model $doc): string
    {
        return (string) $doc->status;
    }

    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array
    {
        $nominal = Money::round((string) $doc->nominal);
        $biaya = Money::round((string) $doc->biaya_admin);
        $asal = (int) BankAccount::query()->findOrFail($doc->rekening_asal_id)->account_id;
        $tujuan = (int) BankAccount::query()->findOrFail($doc->rekening_tujuan_id)->account_id;

        return [
            'lists' => [
                'debit' => [
                    ['amount' => $nominal, 'account_id' => $tujuan, 'memo' => $doc->memo],
                    ['amount' => $biaya, 'account_key' => 'beban_admin_bank', 'memo' => 'Biaya admin transfer'], // nol dilewati mesin
                ],
                'kredit' => [
                    ['amount' => Money::add($nominal, $biaya), 'account_id' => $asal, 'memo' => $doc->memo],
                ],
            ],
        ];
    }

    public function markPosted(Model $doc, PostingResult $result, User $user): void
    {
        $doc->forceFill([
            'nomor' => $result->nomor, 'status' => 'posted', 'journal_id' => $result->journalIds[0] ?? null,
            'diposting_oleh' => $user->getKey(), 'diposting_pada' => now(), 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function isReconciled(Model $doc): bool
    {
        return LineDocumentPostingHandler::journalsReconciled('bank_transfer', (int) $doc->getKey(), (int) $doc->company_id);
    }

    public function markVoid(Model $doc, string $reason, User $user): void
    {
        $doc->forceFill([
            'status' => 'void', 'void_oleh' => $user->getKey(), 'void_pada' => now(),
            'alasan_void' => $reason, 'updated_by' => $user->getKey(),
        ])->save();
    }

    public function eventPayload(Model $doc): array
    {
        return [
            'rekening_asal_id' => $doc->rekening_asal_id, 'rekening_tujuan_id' => $doc->rekening_tujuan_id,
            'nominal' => (string) $doc->nominal, 'biaya_admin' => (string) $doc->biaya_admin,
        ];
    }

    public function journalMemo(Model $doc): ?string
    {
        return $doc->memo;
    }
}
