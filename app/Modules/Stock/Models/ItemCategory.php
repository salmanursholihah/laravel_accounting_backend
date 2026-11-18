<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    use BelongsToCompany;

    protected $table = 'item_categories';

    protected $guarded = [];

    protected $casts = ['is_aktif' => 'boolean'];
}
