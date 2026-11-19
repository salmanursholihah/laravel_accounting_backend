<?php

namespace App\Modules\Stock\Recalc;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job hitung ulang HPP satu (barang, gudang). Membawa konteks perusahaan eksplisit (Tahap 8 bagian 8) dan unik per
 * (perusahaan, barang, gudang) sehingga hanya satu job aktif. Percobaan ulang aman: hitung ulang idempoten.
 */
class RecalculateStockJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $companyId, public int $itemId, public int $warehouseId)
    {
    }

    public function uniqueId(): string
    {
        return "stock-recalc:{$this->companyId}:{$this->itemId}:{$this->warehouseId}";
    }

    public function handle(CurrentCompany $current, StockRecalcService $svc): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $current->runAs($company, fn () => $svc->run($this->itemId, $this->warehouseId));
    }
}
