<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ItemUnitConversion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'item_unit_conversions';

    protected $guarded = [];

    protected $casts = ['rasio_ke_dasar' => 'decimal:6'];
}
