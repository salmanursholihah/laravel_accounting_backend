<?php

namespace App\Modules\Platform\Audit;

use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Hanya INSERT. audit_logs append-only (trigger MySQL menolak UPDATE/DELETE).
 * Panggil di dalam transaksi yang sama dengan perubahan datanya.
 */
class AuditLogger
{
    public function __construct(private CurrentCompany $current)
    {
    }

    public function log(
        string $entityType,
        ?int $entityId,
        string $aksi,
        ?array $before = null,
        ?array $after = null,
        ?string $alasan = null,
        ?int $companyId = null,
        ?int $userId = null,
    ): void {
        $ip = request()?->ip();
        $packedIp = $ip ? @inet_pton($ip) : false;

        DB::table('audit_logs')->insert([
            'company_id' => $companyId ?? $this->current->idOrNull(),
            'user_id' => $userId ?? Auth::id(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'aksi' => $aksi,
            'alasan' => $alasan,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => $packedIp === false ? null : $packedIp,
            'created_at' => now(),
        ]);
    }
}
