<?php

namespace App\Modules\Stock\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Buku besar stok, append-only. Hanya StockLedgerService yang menulis. */
class StockLedgerEntry extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'stock_ledger';

    protected $guarded = [];

    protected $casts = ['tanggal' => 'date', 'qty_masuk' => 'decimal:4', 'qty_keluar' => 'decimal:4', 'harga_satuan' => 'decimal:4', 'nilai' => 'decimal:4', 'qty_saldo' => 'decimal:4', 'nilai_saldo' => 'decimal:4', 'created_at' => 'datetime'];
}
