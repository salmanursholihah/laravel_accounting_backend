<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceLine extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_invoices_lines';

    protected $guarded = [];

    public $timestamps = false;
}
