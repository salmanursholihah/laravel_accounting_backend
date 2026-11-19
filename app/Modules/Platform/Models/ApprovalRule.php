<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan approval per perusahaan. doc_type '*' = semua dokumen (dipakai aturan void).
 * kondisi_json (null = selalu berlaku): amount_gte (angka), creator_role_not_in (daftar nama role).
 */
class ApprovalRule extends Model
{
    use BelongsToCompany;

    public const AKSI_POST = 'post';
    public const AKSI_VOID = 'void';
    public const ANY_DOC = '*';

    protected $table = 'approval_rules';

    protected $fillable = ['company_id', 'doc_type', 'aksi', 'kondisi_json', 'approver_role_id', 'is_aktif'];

    protected $casts = ['kondisi_json' => 'array', 'is_aktif' => 'boolean'];

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }
}
