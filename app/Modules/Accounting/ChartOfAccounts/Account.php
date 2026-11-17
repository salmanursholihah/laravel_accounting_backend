<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'accounts';

    protected $fillable = [
        'company_id', 'kode', 'nama', 'tipe', 'sub_tipe', 'parent_id', 'saldo_normal',
        'is_header', 'is_aktif', 'kode_mata_uang', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_header' => 'boolean', 'is_aktif' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Akun yang boleh dipakai transaksi: bukan induk dan aktif. */
    public function isPostable(): bool
    {
        return ! $this->is_header && $this->is_aktif;
    }

    public static function normalBalanceOf(string $tipe): string
    {
        return in_array($tipe, ['asset', 'cogs', 'expense', 'other_expense'], true) ? 'debit' : 'credit';
    }
}
