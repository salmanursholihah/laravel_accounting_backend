<?php

namespace App\Modules\Accounting\Posting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Bawaan aman untuk dokumen sederhana: tanpa pajak, tanpa stok, tanpa turunan, tanpa rekonsiliasi. */
abstract class AbstractPostingHandler implements PostingHandler
{
    public function validate(Model $doc, User $user): void
    {
    }

    public function taxInput(Model $doc): ?array
    {
        return null;
    }

    public function stockMovements(Model $doc): array
    {
        return [];
    }

    public function blockers(Model $doc): array
    {
        return [];
    }

    public function isReconciled(Model $doc): bool
    {
        return false;
    }

    public function restoreSources(Model $doc): void
    {
    }

    public function eventPayload(Model $doc): array
    {
        return [];
    }

    public function journalMemo(Model $doc): ?string
    {
        return null;
    }
}
