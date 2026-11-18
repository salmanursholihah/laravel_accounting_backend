<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use BelongsToCompany;

    protected $table = 'warehouses';

    protected $guarded = [];

    protected $casts = ['is_aktif' => 'boolean', 'is_default' => 'boolean'];
}
