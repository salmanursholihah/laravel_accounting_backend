<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'stock_adjustments_lines';

    protected $guarded = [];

    protected $casts = ['qty_selisih' => 'decimal:4', 'harga_satuan' => 'decimal:4', 'nilai' => 'decimal:4'];
}
