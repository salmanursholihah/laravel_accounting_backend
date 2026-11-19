<?php

namespace App\Modules\FixedAssets;

use App\Modules\Accounting\Posting\JournalRuleRegistry;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\FixedAssets\Disposal\AssetDisposalPostingHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Modul Aset Tetap (Tahap 7D). Menulis jurnal hanya lewat inti akuntansi: penyusutan lewat JournalService,
 * disposisi lewat orkestrator posting (handler + aturan jurnal didaftarkan di sini).
 */
class FixedAssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $handler = new AssetDisposalPostingHandler();
        $this->app->make(PostingHandlerRegistry::class)->register($handler);
        $this->app->make(JournalRuleRegistry::class)->register(...$handler->journalRules());

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
