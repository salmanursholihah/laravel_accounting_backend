<?php

namespace App\Modules\Platform\Actions;

use App\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Events\CompanyCreated;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\CompanySetting;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * UC-02: membuat perusahaan. Pembuat otomatis menjadi Owner; dibuat cabang default dan preferensi bawaan.
 * Modul lain menyiapkan data awalnya lewat listener CompanyCreated (tahun buku, penomoran, bagan akun, dst.).
 */
class CreateCompany
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    public function handle(User $owner, array $data): Company
    {
        return DB::transaction(function () use ($owner, $data) {
            $company = Company::create([
                'nama' => $data['nama'],
                'npwp' => $data['npwp'] ?? null,
                'is_pkp' => (bool) ($data['is_pkp'] ?? false),
                'alamat' => $data['alamat'] ?? null,
                'mata_uang_dasar' => 'IDR',
                'bulan_mulai_tahun_buku' => (int) ($data['bulan_mulai_tahun_buku'] ?? 1),
                'tanggal_mulai_pembukuan' => $data['tanggal_mulai_pembukuan'],
                'status' => 'active',
                'created_by' => $owner->getKey(),
            ]);

            $this->current->runAs($company, function () use ($company) {
                Branch::create(['kode' => 'PUSAT', 'nama' => 'Kantor Pusat', 'is_default' => true, 'is_aktif' => true]);
                CompanySetting::create(['mode_pajak' => $company->is_pkp ? 'pkp' : 'non_pkp']);
            });

            $ownerRole = Role::query()
                ->whereNull('company_id')
                ->where('nama', 'Owner')
                ->where('is_sistem', true)
                ->firstOrFail(); // jalankan RbacSeeder dulu

            CompanyUser::create([
                'company_id' => $company->getKey(),
                'user_id' => $owner->getKey(),
                'role_id' => $ownerRole->getKey(),
                'branch_id' => null,
                'status' => 'active',
            ]);

            $this->audit->log(
                'company', (int) $company->getKey(), 'create', null,
                $company->only(['public_id', 'nama', 'npwp', 'is_pkp', 'tanggal_mulai_pembukuan']),
                null, (int) $company->getKey(), (int) $owner->getKey(),
            );

            event(new CompanyCreated($company, $owner, $data['template'] ?? 'dagang')); // listener sinkron, ikut transaksi ini

            return $company;
        });
    }
}
