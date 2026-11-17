<?php

namespace App\Modules\Accounting\Periods;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use BelongsToCompany;

    protected $table = 'accounting_periods';

    protected $fillable = [
        'company_id', 'fiscal_year_id', 'bulan', 'tanggal_mulai', 'tanggal_selesai',
        'status', 'ditutup_oleh', 'ditutup_pada',
    ];

    protected $casts = [
        'bulan' => 'integer',
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'ditutup_pada' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
