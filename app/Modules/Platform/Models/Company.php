<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Perusahaan = tenant. Tidak memakai BelongsToCompany. */
class Company extends Model
{
    use HasPublicId;

    protected $table = 'companies';

    protected $fillable = [
        'nama', 'npwp', 'is_pkp', 'alamat', 'mata_uang_dasar',
        'bulan_mulai_tahun_buku', 'tanggal_mulai_pembukuan',
        'logo_file_id', 'status', 'created_by',
    ];

    protected $casts = [
        'is_pkp' => 'boolean',
        'bulan_mulai_tahun_buku' => 'integer',
        'tanggal_mulai_pembukuan' => 'date',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }
}
