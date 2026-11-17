<?php

namespace App\Modules\Accounting\ChartOfAccounts;

use App\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * UC-15: Akun Perkiraan. Aturan:
 * - induk (header) tidak bisa dipakai transaksi; anak harus bertipe sama dengan induknya dan induknya header;
 * - akun yang sudah punya transaksi tidak bisa dihapus, diubah tipenya, atau dijadikan induk;
 * - akun sistem (terpetakan di system_accounts) tidak bisa dihapus, diubah tipenya, atau dinonaktifkan.
 */
class AccountService
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    public function create(array $data, ?User $user = null): Account
    {
        if (Account::query()->where('kode', $data['kode'])->exists()) {
            throw new AccountException("Kode akun {$data['kode']} sudah dipakai.");
        }

        $this->assertParent($data['parent_id'] ?? null, $data['tipe']);

        return DB::transaction(function () use ($data, $user) {
            $account = Account::create([
                'kode' => $data['kode'],
                'nama' => $data['nama'],
                'tipe' => $data['tipe'],
                'sub_tipe' => $data['sub_tipe'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'saldo_normal' => $data['saldo_normal'] ?? Account::normalBalanceOf($data['tipe']),
                'is_header' => (bool) ($data['is_header'] ?? false),
                'is_aktif' => true,
                'kode_mata_uang' => $this->current->company()->mata_uang_dasar,
                'created_by' => $user?->getKey(),
                'updated_by' => $user?->getKey(),
            ]);

            $this->audit->log('account', (int) $account->getKey(), 'create', null, $account->only(['kode', 'nama', 'tipe', 'parent_id', 'is_header']));

            return $account;
        });
    }

    public function update(Account $account, array $data, ?User $user = null): Account
    {
        $account->fill(array_intersect_key($data, array_flip([
            'kode', 'nama', 'tipe', 'sub_tipe', 'parent_id', 'saldo_normal', 'is_header', 'is_aktif',
        ])));
        $dirty = $account->getDirty();

        if (! $dirty) {
            return $account;
        }

        if (isset($dirty['kode']) && Account::query()->where('kode', $dirty['kode'])->where('id', '!=', $account->getKey())->exists()) {
            throw new AccountException("Kode akun {$dirty['kode']} sudah dipakai.");
        }

        if (isset($dirty['tipe'])) {
            if ($this->hasTransactions($account) || $this->isSystem($account)) {
                throw new AccountException('Tipe akun tidak bisa diubah karena akun sudah punya transaksi atau merupakan akun sistem.');
            }
            if ($account->children()->exists()) {
                throw new AccountException('Tipe akun induk tidak bisa diubah selama masih punya akun anak.');
            }
        }

        if (array_key_exists('is_header', $dirty)) {
            if ($account->is_header && $this->hasTransactions($account)) {
                throw new AccountException('Akun yang sudah punya transaksi tidak bisa dijadikan akun induk.');
            }
            if ($account->is_header && $this->isSystem($account)) {
                throw new AccountException('Akun sistem tidak bisa dijadikan akun induk.');
            }
            if (! $account->is_header && $account->children()->exists()) {
                throw new AccountException('Akun yang punya akun anak harus tetap menjadi akun induk.');
            }
        }

        if (array_key_exists('parent_id', $dirty) || isset($dirty['tipe'])) {
            $this->assertParent($account->parent_id, $account->tipe, $account);
        }

        if (isset($dirty['is_aktif']) && ! $account->is_aktif) {
            if ($this->isSystem($account)) {
                throw new AccountException('Akun sistem tidak bisa dinonaktifkan. Petakan ulang akun defaultnya terlebih dahulu.');
            }
            if ($account->children()->where('is_aktif', true)->exists()) {
                throw new AccountException('Nonaktifkan akun anaknya terlebih dahulu.');
            }
        }

        return DB::transaction(function () use ($account, $dirty, $user) {
            $before = array_intersect_key($account->getOriginal(), $dirty);
            $account->updated_by = $user?->getKey();
            $account->save();
            $this->audit->log('account', (int) $account->getKey(), 'update', $before, $dirty);

            return $account;
        });
    }

    public function delete(Account $account): void
    {
        if ($this->hasTransactions($account)) {
            throw new AccountException('Akun sudah punya transaksi dan tidak bisa dihapus. Nonaktifkan saja.');
        }
        if ($account->children()->exists()) {
            throw new AccountException('Akun masih punya akun anak.');
        }
        if ($this->isSystem($account)) {
            throw new AccountException('Akun sistem tidak bisa dihapus.');
        }

        DB::transaction(function () use ($account) {
            $snapshot = $account->only(['kode', 'nama', 'tipe', 'parent_id']);
            $account->delete();
            $this->audit->log('account', (int) $account->getKey(), 'delete', $snapshot, null);
        });
    }

    public function hasTransactions(Account $account): bool
    {
        $companyId = $this->current->id();

        return DB::table('journal_lines')->where(['company_id' => $companyId, 'account_id' => $account->getKey()])->exists()
            || DB::table('opening_balances')->where(['company_id' => $companyId, 'account_id' => $account->getKey()])->exists();
    }

    public function isSystem(Account $account): bool
    {
        return DB::table('system_accounts')
            ->where(['company_id' => $this->current->id(), 'account_id' => $account->getKey()])
            ->exists();
    }

    /** Induk harus ada di perusahaan ini, berupa header, bertipe sama, dan tidak membentuk siklus. */
    private function assertParent(?int $parentId, string $tipe, ?Account $self = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Account::query()->find($parentId) ?? throw new AccountException('Akun induk tidak ditemukan.');

        if (! $parent->is_header) {
            throw new AccountException('Akun induk harus berstatus header (akun induk).');
        }
        if ($parent->tipe !== $tipe) {
            throw new AccountException('Akun anak harus bertipe sama dengan akun induknya.');
        }

        if ($self) {
            for ($node = $parent; $node !== null; $node = $node->parent_id ? Account::query()->find($node->parent_id) : null) {
                if ($node->getKey() === $self->getKey()) {
                    throw new AccountException('Akun induk tidak boleh berupa akun itu sendiri atau turunannya.');
                }
            }
        }
    }
}
