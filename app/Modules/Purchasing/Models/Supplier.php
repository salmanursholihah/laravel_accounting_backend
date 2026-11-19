<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use BelongsToCompany;

    protected $table = 'suppliers';

    protected $guarded = [];
}
