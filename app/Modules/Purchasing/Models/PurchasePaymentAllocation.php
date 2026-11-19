<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchasePaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_payment_allocations';

    protected $guarded = [];
}
