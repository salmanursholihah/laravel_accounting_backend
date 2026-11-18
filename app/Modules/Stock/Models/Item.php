<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use App\Modules\Platform\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'items';

    protected $guarded = [];

    protected $casts = ['is_aktif' => 'boolean', 'harga_jual' => 'decimal:4', 'harga_beli_standar' => 'decimal:4'];

    public function isBarang(): bool
    {
        return $this->tipe === 'barang';
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ItemUnitConversion::class, 'item_id');
    }
}
