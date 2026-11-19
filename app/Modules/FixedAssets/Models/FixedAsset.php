<?php

namespace App\Modules\FixedAssets\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    use BelongsToCompany;

    protected $table = 'fixed_assets';

    protected $guarded = [];
    protected $casts = ['tanggal_perolehan' => 'date', 'tanggal_mulai_susut' => 'date'];
}
