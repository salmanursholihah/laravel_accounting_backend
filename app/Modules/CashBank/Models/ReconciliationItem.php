<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Pasangan satu baris rekening koran dengan satu baris jurnal bank (1:1). tipe_cocok: otomatis | manual | transaksi_baru. */
class ReconciliationItem extends Model
{
    use BelongsToCompany;

    protected $table = 'reconciliation_items';

    protected $guarded = [];
}
