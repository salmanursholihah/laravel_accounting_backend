<?php

namespace App\Console\Commands;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Stock\Recalc\StockRecalcService;
use Illuminate\Console\Command;

/** Hitung ulang HPP semua (barang, gudang) yang bertanda perlu_hitung_ulang, per perusahaan. Dijadwalkan tiap 5 menit. */
class StockRecalcCommand extends Command
{
    protected $signature = 'stock:recalc {--company= : id perusahaan tertentu (default semua)}';

    protected $description = 'Hitung ulang HPP transaksi mundur yang tertunda';

    public function handle(CurrentCompany $current): int
    {
        $q = Company::query();
        if ($this->option('company')) {
            $q->whereKey((int) $this->option('company'));
        }

        $gagal = 0;
        foreach ($q->orderBy('id')->get() as $company) {
            $hasil = $current->runAs($company, fn () => app(StockRecalcService::class)->runPending());
            // cache SystemAccountService bersifat per-request dan tidak memuat company_id: bersihkan antar perusahaan
            app()->forgetScopedInstances();

            if ($hasil['selesai'] || $hasil['gagal']) {
                $this->line("{$company->nama}: {$hasil['selesai']} selesai, " . count($hasil['gagal']) . ' gagal');
            }
            foreach ($hasil['gagal'] as $g) {
                $this->warn("  item {$g['item_id']} gudang {$g['warehouse_id']}: {$g['pesan']}");
                $gagal++;
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
