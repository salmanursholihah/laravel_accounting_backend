<?php

namespace App\Modules\Platform\Approval;

use App\Modules\Platform\Models\ApprovalRule;
use Illuminate\Support\Facades\DB;

/**
 * Aturan approval bawaan (PDF Tahap 7). Idempoten; Owner bebas mengubahnya lewat endpoint aturan (6A.5).
 * Hanya doc_type yang SUDAH terdaftar di PostingHandlerRegistry. Menyusul saat modulnya ada:
 * sales_return, purchase_return (amount_gte), asset_disposal, hasil_stok_opname, jurnal_umum (creator_role_not_in).
 * Panggil di dalam konteks perusahaan (CurrentCompany::runAs) setelah RbacSeeder. Belum dipanggil dari CreateCompany (6A.5).
 */
class DefaultApprovalRules
{
    public const THRESHOLD = 5_000_000;

    public function seed(): void
    {
        $roles = DB::table('roles')->whereNull('company_id')->whereIn('nama', ['Owner', 'Akuntan'])->pluck('id', 'nama');
        if (! isset($roles['Owner'], $roles['Akuntan'])) {
            throw new \RuntimeException('Role bawaan Owner/Akuntan belum ada; jalankan RbacSeeder dulu.');
        }

        $rules = [
            ['stock_adjustment', 'post', null, $roles['Akuntan']],
            ['cash_payment', 'post', ['amount_gte' => self::THRESHOLD], $roles['Akuntan']],
            ['bank_transfer', 'post', ['amount_gte' => self::THRESHOLD], $roles['Akuntan']],
            ['purchase_payment', 'post', ['amount_gte' => self::THRESHOLD], $roles['Akuntan']],
            [ApprovalRule::ANY_DOC, 'void', null, $roles['Owner']],
        ];

        foreach ($rules as [$docType, $aksi, $kondisi, $roleId]) {
            ApprovalRule::firstOrCreate(
                ['doc_type' => $docType, 'aksi' => $aksi],
                ['kondisi_json' => $kondisi, 'approver_role_id' => $roleId, 'is_aktif' => true],
            );
        }
    }
}
