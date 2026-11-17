<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Events\TaxModeChanged;
use App\Modules\Platform\Models\CompanySetting;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** UC-06: Preferensi. Akun default (system_accounts) menyusul di Step 3. */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => CompanySetting::current()->toArray()]);
    }

    public function update(Request $request, CurrentCompany $current, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'izinkan_stok_minus' => ['sometimes', 'boolean'],
            'metode_persediaan' => ['sometimes', 'in:average,fifo'],
            'toleransi_kirim_persen' => ['sometimes', 'numeric', 'between:0,100'],
            'aturan_awal_penyusutan' => ['sometimes', 'in:acquisition_month,next_month'],
            'tanggal_void_periode_terkunci' => ['sometimes', 'in:today,next_open_period'],
            'kebijakan_draft_akhir_bulan' => ['sometimes', 'in:warn,block'],
            'mode_pajak' => ['sometimes', 'in:pkp,non_pkp'],
        ]);

        $setting = CompanySetting::current();
        $setting->fill($data);
        $dirty = $setting->getDirty();

        if (array_key_exists('metode_persediaan', $dirty)
            && Schema::hasTable('stock_ledger')
            && DB::table('stock_ledger')->where('company_id', $current->id())->exists()) {
            return response()->json([
                'message' => 'Metode persediaan tidak bisa diganti setelah ada transaksi stok.',
                'code' => 'inventory_method_locked',
            ], 422);
        }

        if ($dirty) {
            DB::transaction(function () use ($setting, $dirty, $current, $audit, $request) {
                $before = array_intersect_key($setting->getOriginal(), $dirty);
                $setting->updated_by = $request->user()->getKey();
                $setting->save();

                if (array_key_exists('mode_pajak', $dirty)) {
                    $current->company()->update(['is_pkp' => $dirty['mode_pajak'] === 'pkp']);
                    event(new TaxModeChanged($current->company()->fresh(), $dirty['mode_pajak'] === 'pkp'));
                }

                $audit->log('company_settings', $current->id(), 'update', $before, $dirty);
            });
        }

        return response()->json(['data' => $setting->fresh()->toArray()]);
    }
}
