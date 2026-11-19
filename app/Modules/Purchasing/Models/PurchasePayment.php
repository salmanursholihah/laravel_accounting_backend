<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchasePayment extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'purchase_payments';

    protected $guarded = [];

    protected $casts = ['tanggal' => 'date', 'version' => 'integer', 'diposting_pada' => 'datetime', 'void_pada' => 'datetime'];

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePaymentAllocation::class, 'purchase_payment_id')->orderBy('id');
    }
}
