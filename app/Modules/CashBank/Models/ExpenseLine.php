<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExpenseLine extends Model
{
    use BelongsToCompany;

    protected $table = 'expenses_lines';

    public $timestamps = false;

    protected $guarded = [];
}
