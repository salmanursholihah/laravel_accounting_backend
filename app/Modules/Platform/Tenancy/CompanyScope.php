<?php

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Semua query model bertenant otomatis difilter company_id. Tanpa konteks = ditolak. */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('company_id'), app(CurrentCompany::class)->id());
    }
}
