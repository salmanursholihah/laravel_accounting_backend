<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Listeners\ApplyChartTemplateForCompany;
use App\Modules\Accounting\Listeners\DeactivatePpnForNonPkp;
use App\Modules\Accounting\Listeners\SetupAccountingForCompany;
use App\Modules\Platform\Events\CompanyCreated;
use App\Modules\Platform\Events\TaxModeChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // cache pemetaan akun sistem per request/job
        $this->app->scoped(SystemAccountService::class);
    }

    public function boot(): void
    {
        // Urutan penting: tahun buku dan penomoran dulu, lalu bagan akun
        Event::listen(CompanyCreated::class, SetupAccountingForCompany::class);
        Event::listen(CompanyCreated::class, ApplyChartTemplateForCompany::class);

        Event::listen(TaxModeChanged::class, DeactivatePpnForNonPkp::class);

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
