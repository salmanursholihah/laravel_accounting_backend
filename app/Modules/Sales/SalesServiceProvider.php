<?php

namespace App\Modules\Sales;

use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Sales\Invoices\SalesInvoicePostingHandler;
use Illuminate\Support\ServiceProvider;

/** Modul Penjualan. Mendaftarkan handler dan aturan jurnal ke inti akuntansi; tidak menulis jurnal/stok sendiri. Daftarkan SETELAH Stock. */
class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $handler = new SalesInvoicePostingHandler();
        $this->app->make(PostingHandlerRegistry::class)->register($handler);
        $this->app->make(JournalRuleRegistry::class)->register(...$handler->journalRules());

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
