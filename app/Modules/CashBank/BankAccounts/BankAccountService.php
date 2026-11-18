<?php

namespace App\Modules\CashBank\BankAccounts;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\ChartOfAccounts\AccountService;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Master Rekening Kas/Bank. Satu rekening = satu akun Kas/Bank (asset, sub_tipe cash_bank, bukan induk).
 * Akun bisa dipilih dari bagan akun yang ada, atau dibuat sekalian lewat 'akun_baru' (kode, nama, parent_id?).
 */
class BankAccountService
{
    public function __construct(private AccountService $accounts, private AuditLogger $audit)
    {
    }

    public function create(array $data, ?User $user = null): BankAccount
    {
        return DB::transaction(function () use ($data, $user) {
            $accountId = $data['account_id'] ?? null;

            if ($accountId === null) {
                $baru = $data['akun_baru'] ?? null;
                if (! is_array($baru) || empty($baru['kode']) || empty($baru['nama'])) {
                    throw new CashBankException('Pilih akun Kas/Bank yang ada atau isi akun_baru (kode dan nama).');
                }
                $accountId = (int) $this->accounts->create([
                    'kode' => $baru['kode'], 'nama' => $baru['nama'], 'tipe' => 'asset', 'sub_tipe' => 'cash_bank',
                    'parent_id' => $baru['parent_id'] ?? null,
                ], $user)->getKey();
            }

            $account = $this->assertUsableAccount((int) $accountId);

            if (BankAccount::query()->where('account_id', $account->getKey())->exists()) {
                throw new CashBankException("Akun {$account->kode} sudah terdaftar sebagai rekening kas/bank.", 'bank_account_exists');
            }

            $bank = BankAccount::create([
                'account_id' => $account->getKey(),
                'nama_bank' => $data['nama_bank'] ?? null,
                'no_rekening' => $data['no_rekening'] ?? null,
                'atas_nama' => $data['atas_nama'] ?? null,
                'is_kas' => (bool) ($data['is_kas'] ?? false),
                'is_aktif' => true,
                'created_by' => $user?->getKey(),
                'updated_by' => $user?->getKey(),
            ]);

            $this->audit->log('bank_account', (int) $bank->getKey(), 'create', null, $bank->only(['account_id', 'nama_bank', 'no_rekening', 'is_kas']));

            return $bank;
        });
    }

    /** Akun tidak bisa diganti (histori jurnal menempel pada akun); hanya data rekening dan status. */
    public function update(BankAccount $bank, array $data, ?User $user = null): BankAccount
    {
        $bank->fill(array_intersect_key($data, array_flip(['nama_bank', 'no_rekening', 'atas_nama', 'is_kas', 'is_aktif'])));
        $dirty = $bank->getDirty();
        if (! $dirty) {
            return $bank;
        }

        return DB::transaction(function () use ($bank, $dirty, $user) {
            $before = array_intersect_key($bank->getOriginal(), $dirty);
            $bank->updated_by = $user?->getKey();
            $bank->save();
            $this->audit->log('bank_account', (int) $bank->getKey(), 'update', $before, $dirty);

            return $bank;
        });
    }

    /** Rekening yang sudah punya jurnal tidak dihapus, cukup dinonaktifkan. */
    public function delete(BankAccount $bank): void
    {
        if ($this->hasTransactions($bank)) {
            throw new CashBankException('Rekening sudah punya transaksi dan tidak bisa dihapus. Nonaktifkan saja.', 'bank_account_used');
        }
        if (DB::table('bank_statements')->where('company_id', $bank->company_id)->where('bank_account_id', $bank->getKey())->exists()) {
            throw new CashBankException('Rekening sudah punya rekening koran dan tidak bisa dihapus. Nonaktifkan saja.', 'bank_account_used');
        }

        DB::transaction(function () use ($bank) {
            $this->audit->log('bank_account', (int) $bank->getKey(), 'delete', $bank->only(['account_id', 'nama_bank']), null);
            $bank->delete();
        });
    }

    public function hasTransactions(BankAccount $bank): bool
    {
        return DB::table('journal_lines')
            ->where('company_id', $bank->company_id)
            ->where('account_id', $bank->account_id)
            ->exists();
    }

    /** Dipakai dokumen: rekening harus ada di perusahaan aktif dan aktif. */
    public function assertActive(int $bankAccountId): BankAccount
    {
        $bank = BankAccount::query()->find($bankAccountId);
        if (! $bank) {
            throw new CashBankException('Rekening kas/bank tidak ditemukan.', 'bank_account_not_found');
        }
        if (! $bank->is_aktif) {
            throw new CashBankException('Rekening kas/bank nonaktif tidak bisa dipakai transaksi.', 'bank_account_inactive');
        }

        return $bank;
    }

    private function assertUsableAccount(int $accountId): Account
    {
        $account = Account::query()->find($accountId);
        if (! $account) {
            throw new CashBankException('Akun tidak ditemukan.', 'account_not_found');
        }
        if ($account->tipe !== 'asset' || $account->sub_tipe !== 'cash_bank') {
            throw new CashBankException("Akun {$account->kode} bukan akun Kas/Bank (asset, sub tipe cash_bank).", 'account_not_cash_bank');
        }
        if (! $account->isPostable()) {
            throw new CashBankException("Akun {$account->kode} adalah akun induk atau nonaktif.", 'account_not_postable');
        }

        return $account;
    }
}
