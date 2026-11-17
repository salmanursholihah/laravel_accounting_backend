<?php

namespace App\Modules\Platform\Tenancy;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanyUser;
use Closure;

/**
 * Konteks perusahaan aktif untuk satu request / satu job.
 * Dipasang oleh middleware SetCurrentCompany (dari keanggotaan, bukan dari input mentah klien)
 * atau lewat runAs() untuk job dan proses sistem.
 */
final class CurrentCompany
{
    private ?Company $company = null;
    private ?CompanyUser $membership = null;

    public function set(Company $company, ?CompanyUser $membership = null): void
    {
        $this->company = $company;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function company(): Company
    {
        return $this->company ?? throw new NoCompanyContext();
    }

    public function id(): int
    {
        return (int) $this->company()->getKey();
    }

    public function idOrNull(): ?int
    {
        return $this->company?->getKey();
    }

    public function membership(): ?CompanyUser
    {
        return $this->membership;
    }

    /** Jalankan callback dalam konteks perusahaan tertentu, lalu kembalikan konteks sebelumnya. */
    public function runAs(Company $company, Closure $callback): mixed
    {
        $previousCompany = $this->company;
        $previousMembership = $this->membership;
        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->company = $previousCompany;
            $this->membership = $previousMembership;
        }
    }
}
