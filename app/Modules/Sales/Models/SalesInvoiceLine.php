<?php

namespace App\Modules\Sales\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalesInvoiceLine extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_invoices_lines';

    protected $guarded = [];

    public $timestamps = false;}
