<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Platform\Models\Concerns\HasPublicId;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use BelongsToCompany, HasPublicId;

    protected $table = 'purchase_invoices';

    protected $guarded = [];

    protected $casts = [
        'tanggal' => 'date', 'jatuh_tempo' => 'date', 'harga_termasuk_pajak' => 'boolean', 'dapat_dikreditkan' => 'boolean',
        'version' => 'integer', 'diposting_pada' => 'datetime', 'void_pada' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class, 'purchase_invoice_id')->orderBy('line_no');
    }
}
