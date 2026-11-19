<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Sesi rekonsiliasi satu rekening untuk satu periode rekening koran. Status: berjalan | selesai | dibuka_kembali. */
class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_reconciliations';

    protected $guarded = [];

    protected $casts = ['periode_mulai' => 'date', 'periode_selesai' => 'date', 'diselesaikan_pada' => 'datetime'];

    public function isLocked(): bool
    {
        return $this->status === 'selesai';
    }
}
