<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashPaymentLine extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_payments_lines';

    public $timestamps = false;

    protected $guarded = [];
}
