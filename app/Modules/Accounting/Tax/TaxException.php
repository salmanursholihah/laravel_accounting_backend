<?php

namespace App\Modules\Accounting\Tax;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan master pajak (Tahap 4 bagian 4). */
class TaxException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'tax_rule'], 422);
    }
}
