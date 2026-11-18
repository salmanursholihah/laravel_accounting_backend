<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankTransfer extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'bank_transfers';

    protected $guarded = [];

    protected $casts = [
        'tanggal' => 'date',
        'nominal' => 'decimal:4',
        'biaya_admin' => 'decimal:4',
        'kurs' => 'decimal:6',
        'diposting_pada' => 'datetime',
        'void_pada' => 'datetime',
        'version' => 'integer',
    ];
}
