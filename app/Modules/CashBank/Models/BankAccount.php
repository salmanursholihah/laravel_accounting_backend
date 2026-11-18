<?php

namespace App\Modules\CashBank\Models;

use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master kas/bank: 1:1 dengan satu akun Kas/Bank di Akun Perkiraan. Saldo selalu dibaca dari jurnal, bukan disimpan di sini. */
class BankAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'company_id', 'account_id', 'nama_bank', 'no_rekening', 'atas_nama', 'is_kas', 'is_aktif', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_kas' => 'boolean', 'is_aktif' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
