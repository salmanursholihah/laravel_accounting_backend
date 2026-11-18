<?php

namespace App\Modules\Sales\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToCompany;

    protected $table = 'customers';

    protected $guarded = [];
}
