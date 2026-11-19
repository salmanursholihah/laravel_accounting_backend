<?php

namespace App\Modules\Accounting\Approval;

/**
 * Murni (tanpa database) agar mudah diuji. Semua kunci kondisi harus terpenuhi (AND).
 * Kunci: amount_gte (nominal dokumen >= nilai), creator_role_not_in (role pembuat tidak ada di daftar).
 * Kunci tak dikenal atau data pembuat tak diketahui dianggap COCOK (fail-closed: approval tetap diminta). DECISIONS #5.
 */
final class ApprovalRuleEvaluator
{
    public function matches(?array $kondisi, float $amount, ?string $creatorRole): bool
    {
        if ($kondisi === null || $kondisi === []) {
            return true;
        }

        foreach ($kondisi as $key => $value) {
            $ok = match ($key) {
                'amount_gte' => $amount >= (float) $value,
                'creator_role_not_in' => $creatorRole === null || ! in_array($creatorRole, (array) $value, true),
                default => true,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
