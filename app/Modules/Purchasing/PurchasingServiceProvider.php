<?php

namespace App\Modules\Purchasing;

use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Purchasing\Invoices\PurchaseInvoicePostingHandler;
use App\Modules\Purchasing\Payments\PurchasePaymentPostingHandler;
use Illuminate\Support\ServiceProvider;

/** Modul Pembelian. Mendaftarkan handler dan aturan jurnal ke inti akuntansi; tidak menulis jurnal/stok sendiri. Daftarkan SETELAH Stock. */
class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $handlers = $this->app->make(PostingHandlerRegistry::class);
        $rules = $this->app->make(JournalRuleRegistry::class);

        foreach ([new PurchaseInvoicePostingHandler(), new PurchasePaymentPostingHandler()] as $handler) {
            $handlers->register($handler);
            $rules->register(...$handler->journalRules());
        }

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
