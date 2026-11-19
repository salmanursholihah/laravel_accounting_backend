<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Platform\Approval\DefaultApprovalRules;
use App\Modules\Platform\Events\CompanyCreated;
use App\Modules\Platform\Tenancy\CurrentCompany;

/** 6A.5: aturan approval bawaan untuk perusahaan baru (Owner dapat mengubahnya). Butuh role bawaan dari RbacSeeder. */
class SeedApprovalRulesForCompany
{
    public function __construct(private CurrentCompany $current, private DefaultApprovalRules $rules)
    {
    }

    public function handle(CompanyCreated $event): void
    {
        $this->current->runAs($event->company, fn () => $this->rules->seed());
    }
}
