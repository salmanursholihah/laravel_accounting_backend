<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keanggotaan user x perusahaan x role. Sengaja TANPA global scope:
 * model inilah yang dipakai untuk menentukan konteks perusahaan.
 */
class CompanyUser extends Model
{
    protected $table = 'company_users';

    protected $fillable = ['company_id', 'user_id', 'role_id', 'branch_id', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
