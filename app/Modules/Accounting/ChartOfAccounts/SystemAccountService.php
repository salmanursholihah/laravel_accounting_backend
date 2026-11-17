<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Pemetaan kunci akun sistem -> akun nyata (Tahap 4 bagian 1). Mesin jurnal (ALG-04)
 * tidak menulis nama akun permanen, tetapi memanggil resolve('piutang_usaha') dan sejenisnya.
 */
class SystemAccountService
{
    /** @var array<string, Account> */
    private array $cache = [];

    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    public function map(string $key, int $accountId, ?int $userId = null): void
    {
        $rule = config("system_accounts.{$key}") ?? throw new AccountException("Kunci akun sistem '{$key}' tidak dikenal.");
        $account = Account::query()->find($accountId) ?? throw new AccountException('Akun tidak ditemukan di perusahaan ini.');

        if ($account->is_header) {
            throw new AccountException('Akun induk (header) tidak bisa dijadikan akun default.');
        }
        if (! $account->is_aktif) {
            throw new AccountException('Akun nonaktif tidak bisa dijadikan akun default.');
        }
        if (! in_array($account->tipe, $rule['tipe'], true)) {
            throw new AccountException("\"{$rule['label']}\" harus berupa akun bertipe " . implode(' atau ', $rule['tipe']) . '.');
        }

        $companyId = $this->current->id();
        $before = DB::table('system_accounts')->where(['company_id' => $companyId, 'kunci_akun' => $key])->value('account_id');

        DB::table('system_accounts')->upsert(
            [['company_id' => $companyId, 'kunci_akun' => $key, 'account_id' => $accountId, 'updated_at' => now()]],
            ['company_id', 'kunci_akun'],
            ['account_id', 'updated_at'],
        );
        unset($this->cache[$key]);

        $this->audit->log('system_account', null, 'map', ['kunci_akun' => $key, 'account_id' => $before], ['kunci_akun' => $key, 'account_id' => $accountId], null, null, $userId);
    }

    /** Akun untuk kunci tertentu pada perusahaan aktif. Lempar SystemAccountMissing bila belum dipetakan. */
    public function resolve(string $key): Account
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $accountId = DB::table('system_accounts')
            ->where(['company_id' => $this->current->id(), 'kunci_akun' => $key])
            ->value('account_id');

        if (! $accountId) {
            throw new SystemAccountMissing($key);
        }

        return $this->cache[$key] = Account::query()->findOrFail($accountId);
    }

    /** Daftar semua kunci beserta akun yang terpetakan (null bila belum). */
    public function all(): array
    {
        $mapped = DB::table('system_accounts')->where('company_id', $this->current->id())->pluck('account_id', 'kunci_akun');
        $accounts = Account::query()->whereIn('id', $mapped->values())->get()->keyBy('id');

        $rows = [];
        foreach (config('system_accounts') as $key => $rule) {
            $account = isset($mapped[$key]) ? $accounts[$mapped[$key]] ?? null : null;
            $rows[] = [
                'kunci_akun' => $key,
                'label' => $rule['label'],
                'tipe_diizinkan' => $rule['tipe'],
                'account' => $account ? ['id' => $account->id, 'kode' => $account->kode, 'nama' => $account->nama] : null,
            ];
        }

        return $rows;
    }
}
