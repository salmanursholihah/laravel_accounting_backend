<?php

namespace App\Modules\FixedAssets\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    use BelongsToCompany;

    protected $table = 'asset_categories';

    protected $guarded = [];
}
