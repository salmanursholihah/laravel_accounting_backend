<?php

namespace App\Modules\FixedAssets\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DepreciationSchedule extends Model
{
    use BelongsToCompany;

    protected $table = 'depreciation_schedules';

    protected $guarded = [];
    public const UPDATED_AT = null;
}
