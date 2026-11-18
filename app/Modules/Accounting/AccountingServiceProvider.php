<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Listeners\ApplyChartTemplateForCompany;
use App\Modules\Accounting\Listeners\DeactivatePpnForNonPkp;
use App\Modules\Accounting\Listeners\SeedTaxAndTermsForCompany;
use App\Modules\Accounting\Listeners\SetupAccountingForCompany;
use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\NoStockMovement;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Accounting\Posting\StockMovementApplier;
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

        // Registri posting: modul bisnis mendaftarkan handler dan aturan jurnal di boot() mereka
        $this->app->singleton(PostingHandlerRegistry::class);
        $this->app->singleton(JournalRuleRegistry::class);
        // Port stok: modul persediaan (Langkah 4) menggantinya dengan implementasi nyata
        $this->app->bindIf(StockMovementApplier::class, NoStockMovement::class);
    }

    public function boot(): void
    {
        // Urutan penting: tahun buku dan penomoran dulu, lalu bagan akun
        Event::listen(CompanyCreated::class, SetupAccountingForCompany::class);
        Event::listen(CompanyCreated::class, ApplyChartTemplateForCompany::class);
        Event::listen(CompanyCreated::class, SeedTaxAndTermsForCompany::class); // setelah bagan akun: butuh akun PPN

        Event::listen(TaxModeChanged::class, DeactivatePpnForNonPkp::class);

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
