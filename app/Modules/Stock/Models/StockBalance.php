<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Hanya StockLedgerService yang menulis tabel ini (Tahap 8 bagian 3). */
class StockBalance extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'stock_balances';

    protected $guarded = [];

    protected $casts = ['qty_saldo' => 'decimal:4', 'nilai_saldo' => 'decimal:4', 'hpp_rata_rata' => 'decimal:4', 'stok_minimum' => 'decimal:4', 'perlu_hitung_ulang' => 'boolean', 'hitung_ulang_dari' => 'date'];
}
