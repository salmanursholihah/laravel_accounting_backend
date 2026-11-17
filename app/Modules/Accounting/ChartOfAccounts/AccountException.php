<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan akun (Tahap 4 bagian 1). */
class AccountException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'account_rule'], 422);
    }
}
