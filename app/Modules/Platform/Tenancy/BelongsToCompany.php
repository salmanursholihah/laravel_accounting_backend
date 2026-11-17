<?php

namespace App\Modules\Platform\Tenancy;

use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pasang di semua model tabel bisnis yang punya company_id.
 * - Query: difilter otomatis (CompanyScope).
 * - Simpan: company_id diisi dari konteks; bila diisi manual harus sama dengan konteks.
 * - Kode sistem tanpa konteks (seeder/job) wajib mengisi company_id eksplisit atau memakai CurrentCompany::runAs().
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (Model $model) {
            $current = app(CurrentCompany::class);

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $current->id()); // lempar NoCompanyContext bila kosong
                return;
            }

            if ($current->has() && (int) $model->getAttribute('company_id') !== $current->id()) {
                throw new CrossCompanyException();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
