<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Events\TaxModeChanged;
use App\Modules\Platform\Tenancy\CurrentCompany;

/** Perusahaan non-PKP: semua kode PPN dinonaktifkan otomatis (Tahap 4 bagian 4). */
class DeactivatePpnForNonPkp
{
    public function __construct(private CurrentCompany $current, private TaxCodeService $taxes)
    {
    }

    public function handle(TaxModeChanged $event): void
    {
        if ($event->isPkp) {
            return;
        }

        $this->current->runAs($event->company, fn () => $this->taxes->deactivatePpn());
    }
}
