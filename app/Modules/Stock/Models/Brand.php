<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToCompany;

    protected $table = 'brands';

    protected $guarded = [];

    protected $casts = ['is_aktif' => 'boolean'];
}
