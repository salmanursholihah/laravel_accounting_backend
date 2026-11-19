<?php

namespace App\Modules\FixedAssets;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan modul Aset Tetap. */
class FixedAssetException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'fixed_asset_error', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
