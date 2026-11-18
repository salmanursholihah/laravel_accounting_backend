<?php

namespace App\Modules\Stock;

use App\Modules\Accounting\Ledger\OpeningStockPort;
use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Accounting\Posting\StockMovementApplier;
use App\Modules\Stock\Adjustments\StockAdjustmentPostingHandler;
use App\Modules\Stock\Ledger\StockLedgerService;
use App\Modules\Stock\OpeningStock\OpeningStockService;
use Illuminate\Support\ServiceProvider;

/**
 * Modul inti stok. Mengganti binding NoStockMovement (AccountingServiceProvider) dengan StockLedgerService dan
 * mendaftarkan handler + aturan jurnal Penyesuaian Persediaan. Provider ini harus terdaftar SETELAH AccountingServiceProvider.
 */
class StockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockMovementApplier::class, StockLedgerService::class);
        $this->app->bind(OpeningStockPort::class, OpeningStockService::class);
    }

    public function boot(): void
    {
        $handler = new StockAdjustmentPostingHandler();
        $this->app->make(PostingHandlerRegistry::class)->register($handler);
        $this->app->make(JournalRuleRegistry::class)->register(...$handler->journalRules());

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
