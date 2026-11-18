<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use App\Modules\Platform\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'stock_adjustments';

    protected $guarded = [];

    protected $casts = ['tanggal' => 'date', 'diposting_pada' => 'datetime', 'void_pada' => 'datetime', 'version' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'stock_adjustment_id')->orderBy('line_no');
    }
}
