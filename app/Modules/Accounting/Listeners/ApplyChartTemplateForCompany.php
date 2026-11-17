<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\ChartOfAccounts\ApplyChartTemplate;
use App\Modules\Platform\Events\CompanyCreated;

class ApplyChartTemplateForCompany
{
    public function __construct(private ApplyChartTemplate $template)
    {
    }

    public function handle(CompanyCreated $event): void
    {
        $this->template->handle($event->company, $event->template, (int) $event->owner->getKey());
    }
}
