<?php

namespace App\Modules\Accounting\Periods;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToCompany;

    protected $table = 'fiscal_years';

    protected $fillable = ['company_id', 'tahun', 'tanggal_mulai', 'tanggal_selesai', 'status', 'ditutup_oleh', 'ditutup_pada'];

    protected $casts = [
        'tahun' => 'integer',
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'ditutup_pada' => 'datetime',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('bulan');
    }
}
