<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use DomainException;
use Illuminate\Http\JsonResponse;

class SystemAccountMissing extends DomainException
{
    public function __construct(public readonly string $key)
    {
        $label = config("system_accounts.{$key}.label", $key);
        parent::__construct("Akun default \"{$label}\" belum dipetakan. Atur di Preferensi > Akun Default.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'system_account_missing', 'kunci_akun' => $this->key], 422);
    }
}
