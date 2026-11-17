<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;
use InvalidArgumentException;

/** Mengisi bagan akun dari template (Dagang, Jasa, Manufaktur) dan memetakan akun sistemnya. */
class ApplyChartTemplate
{
    public function __construct(
        private CurrentCompany $current,
        private SystemAccountService $systemAccounts,
    ) {
    }

    public function handle(Company $company, string $template, ?int $createdBy = null): int
    {
        if (! in_array($template, config('coa.templates'), true)) {
            throw new InvalidArgumentException("Template bagan akun '{$template}' tidak dikenal.");
        }

        return $this->current->runAs($company, function () use ($template, $createdBy) {
            $ids = [];
            $keys = [];

            foreach (config('coa.accounts') as $row) {
                if (isset($row['only']) && ! in_array($template, $row['only'], true)) {
                    continue;
                }

                [$kode, $nama, $tipe, $sub, $parentKode] = $row;

                $account = Account::create([
                    'kode' => $kode,
                    'nama' => $nama,
                    'tipe' => $tipe,
                    'sub_tipe' => $sub,
                    'parent_id' => $parentKode ? $ids[$parentKode] : null,
                    'saldo_normal' => $row['normal'] ?? Account::normalBalanceOf($tipe),
                    'is_header' => $row['header'] ?? false,
                    'is_aktif' => true,
                    'kode_mata_uang' => $this->current->company()->mata_uang_dasar,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                ]);

                $ids[$kode] = $account->getKey();
                if (isset($row['key'])) {
                    $keys[$row['key']] = $account->getKey();
                }
            }

            foreach ($keys as $key => $accountId) {
                $this->systemAccounts->map($key, $accountId, $createdBy);
            }

            return count($ids);
        });
    }
}
