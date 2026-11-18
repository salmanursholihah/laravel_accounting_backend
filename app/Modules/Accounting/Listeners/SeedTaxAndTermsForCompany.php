<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Setup\SeedTaxAndTermDefaults;
use App\Modules\Platform\Events\CompanyCreated;

/** Harus berjalan SETELAH ApplyChartTemplateForCompany: kode pajak butuh akun PPN dari bagan akun. */
class SeedTaxAndTermsForCompany
{
    public function __construct(private SeedTaxAndTermDefaults $seed)
    {
    }

    public function handle(CompanyCreated $event): void
    {
        if (! config('tax_defaults.seed_on_company_created', true)) {
            return;
        }

        $this->seed->handle($event->company);
    }
}
