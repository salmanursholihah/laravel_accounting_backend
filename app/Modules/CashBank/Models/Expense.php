<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'expenses';

    protected $guarded = [];

    protected $casts = [
        'tanggal' => 'date',
        'total' => 'decimal:4',
        'kurs' => 'decimal:6',
        'dapat_dikreditkan' => 'boolean',
        'diposting_pada' => 'datetime',
        'void_pada' => 'datetime',
        'version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'expense_id')->orderBy('line_no');
    }
}
