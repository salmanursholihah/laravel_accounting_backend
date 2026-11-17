<?php

namespace App\Modules\Accounting\PaymentTerms;

use DomainException;
use Illuminate\Http\JsonResponse;

class PaymentTermException extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'payment_term_rule'], 422);
    }
}
