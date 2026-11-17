<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/** Preferensi per perusahaan (1 baris per perusahaan, PK = company_id). */
class CompanySetting extends Model
{
    use BelongsToCompany;

    public const CREATED_AT = null;

    protected $table = 'company_settings';
    protected $primaryKey = 'company_id';
    public $incrementing = false;

    protected $fillable = [
        'company_id', 'izinkan_stok_minus', 'metode_persediaan', 'toleransi_kirim_persen',
        'aturan_awal_penyusutan', 'tanggal_void_periode_terkunci', 'kebijakan_draft_akhir_bulan',
        'mode_pajak', 'updated_by',
    ];

    protected $casts = [
        'izinkan_stok_minus' => 'boolean',
        'toleransi_kirim_persen' => 'float',
    ];

    /** Preferensi perusahaan aktif; dibuat dengan nilai bawaan bila belum ada. */
    public static function current(): self
    {
        $company = app(CurrentCompany::class)->company();

        return static::query()->firstOrCreate([], [
            'mode_pajak' => $company->is_pkp ? 'pkp' : 'non_pkp',
        ]);
    }
}
