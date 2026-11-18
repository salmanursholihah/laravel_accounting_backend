<?php

namespace App\Modules\CashBank;

use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\CashBank\Posting\BankTransferPostingHandler;
use App\Modules\CashBank\Posting\LineDocumentPostingHandler;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\CashBank\Models\Expense;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Kas & Bank. Mendaftarkan handler posting dan aturan jurnalnya ke inti akuntansi (ALG-02/ALG-04):
 * menambah dokumen = menambah konfigurasi, bukan mengubah mesin.
 */
class CashBankServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $handlers = $this->app->make(PostingHandlerRegistry::class);
        $rules = $this->app->make(JournalRuleRegistry::class);

        $all = [
            new LineDocumentPostingHandler('cash_receipt', 'penerimaan', CashReceipt::class, 'in', 'rekening_tujuan_id'),
            new LineDocumentPostingHandler('cash_payment', 'pembayaran', CashPayment::class, 'out', 'rekening_sumber_id'),
            new LineDocumentPostingHandler('expense', 'pencatatan_beban', Expense::class, 'out', 'rekening_pembayaran_id'),
            new BankTransferPostingHandler(),
        ];

        foreach ($all as $handler) {
            $handlers->register($handler);
            $rules->register(...$handler->journalRules());
        }

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
