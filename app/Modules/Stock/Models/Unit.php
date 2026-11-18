<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $table = 'units';

    protected $guarded = [];

    protected $casts = ['is_aktif' => 'boolean'];
}
