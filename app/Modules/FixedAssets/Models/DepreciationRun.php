<?php

namespace App\Modules\FixedAssets\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DepreciationRun extends Model
{
    use BelongsToCompany;

    protected $table = 'depreciation_runs';

    protected $guarded = [];
}
