<?php

namespace App\Modules\Platform\Models\Concerns;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tambahkan ke App\Models\User bersama HasPublicId. */
trait HasCompanies
{
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_users')
            ->withPivot(['role_id', 'branch_id', 'status'])
            ->withTimestamps();
    }
}
