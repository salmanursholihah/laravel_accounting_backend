<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Numbering\NumberingService;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Platform\Events\CompanyCreated;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/** Menyiapkan tahun buku pertama (12 periode) dan konfigurasi penomoran untuk perusahaan baru. */
class SetupAccountingForCompany
{
    public function __construct(
        private CurrentCompany $current,
        private PeriodService $periods,
        private NumberingService $numbering,
    ) {
    }

    public function handle(CompanyCreated $event): void
    {
        $company = $event->company;

        $this->current->runAs($company, function () use ($company) {
            $mulai = CarbonImmutable::parse($company->tanggal_mulai_pembukuan);
            $tahun = $mulai->month >= (int) $company->bulan_mulai_tahun_buku ? $mulai->year : $mulai->year - 1;

            $this->periods->createFiscalYear($tahun);
            $this->numbering->seedDefaults();
        });
    }
}
