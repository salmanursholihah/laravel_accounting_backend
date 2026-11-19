<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pengajuan = satu dokumen + satu aksi (post/void). Tabel tidak punya created_at/updated_at;
 * waktu pengajuan = diajukan_pada, waktu keputusan = diputuskan_pada.
 * aktif_key terisi hanya saat pending (lihat migration 2026_11_18_110001) sehingga tidak ada pengajuan ganda.
 */
class ApprovalRequest extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    public $timestamps = false;

    protected $table = 'approval_requests';

    protected $fillable = [
        'company_id', 'rule_id', 'doc_type', 'doc_id', 'aksi', 'pengaju', 'approver_role_id', 'approver_id',
        'status', 'alasan_tolak', 'alasan_void', 'aktif_key', 'diajukan_pada', 'diputuskan_pada',
    ];

    protected $casts = ['diajukan_pada' => 'datetime', 'diputuskan_pada' => 'datetime'];

    public static function activeKey(int $companyId, string $docType, int $docId, string $aksi): string
    {
        return "{$companyId}:{$docType}:{$docId}:{$aksi}";
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'rule_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
