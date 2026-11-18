<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashReceiptLine extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_receipts_lines';

    public $timestamps = false;

    protected $guarded = [];
}
