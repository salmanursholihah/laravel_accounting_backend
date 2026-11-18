<?php

namespace App\Modules\Sales\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'sales_invoices';

    protected $guarded = [];

    protected $casts = [
        'tanggal' => 'date', 'jatuh_tempo' => 'date', 'harga_termasuk_pajak' => 'boolean', 'version' => 'integer',
        'diposting_pada' => 'datetime', 'void_pada' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class, 'sales_invoice_id')->orderBy('line_no');
    }
}
