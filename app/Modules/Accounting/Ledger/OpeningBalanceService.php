<?php

namespace App\Modules\Accounting\Ledger;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UC-16 Saldo awal (migrasi). Baris opening_balances dengan journal_id NULL = draf; setelah diposting menjadi
 * satu jurnal tipe 'opening' (is_opening = true) pada tanggal mulai pembukuan. Total wajib seimbang.
 * 'jumlah' positif = sesuai saldo normal akun, negatif = berlawanan (mis. akun kontra).
 * Saldo awal stok (persediaan per gudang) dikelola modul stok lewat OpeningStockPort: nilainya masuk sebagai debit
 * akun persediaan ke jurnal yang sama, dan gerakan stoknya dicatat dalam transaksi yang sama (post) atau dibalik (void).
 */
class OpeningBalanceService
{
    public function __construct(
        private CurrentCompany $current,
        private JournalService $journals,
        private JournalValidator $validator,
        private AuditLogger $audit,
        private OpeningStockPort $stock,
    ) {
    }

    public function rows(): Collection
    {
        return DB::table('opening_balances as ob')
            ->join('accounts as a', function ($j) {
                $j->on('a.id', '=', 'ob.account_id')->on('a.company_id', '=', 'ob.company_id');
            })
            ->leftJoin('contacts as c', function ($j) {
                $j->on('c.id', '=', 'ob.contact_id')->on('c.company_id', '=', 'ob.company_id');
            })
            ->where('ob.company_id', $this->current->id())
            ->orderBy('a.kode')->orderBy('ob.id')
            ->get(['ob.id', 'ob.account_id', 'a.kode', 'a.nama', 'a.saldo_normal', 'ob.contact_id', 'c.nama as kontak', 'ob.jumlah', 'ob.journal_id']);
    }

    public function activeJournal(): ?Journal
    {
        return Journal::query()->where('journals.is_opening', true)->active()->first();
    }

    /** Mengganti seluruh baris draf. @param list<array{account_id:int, jumlah:string|int|float, contact_id?:?int}> $rows */
    public function save(array $rows, User $user): int
    {
        if ($this->activeJournal()) {
            throw JournalException::openingAlreadyPosted();
        }

        $clean = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $jumlah = $this->signedAmount($r['jumlah'] ?? null, $i + 1);
            if (Money::isZero($jumlah)) {
                continue;
            }
            $key = ((int) $r['account_id']) . '|' . ((int) ($r['contact_id'] ?? 0));
            if (isset($seen[$key])) {
                throw new JournalException('Baris ' . ($i + 1) . ': kombinasi akun dan kontak yang sama sudah ada di baris lain.', 'duplicate_opening_row');
            }
            $seen[$key] = true;
            $clean[] = ['account_id' => (int) $r['account_id'], 'contact_id' => ! empty($r['contact_id']) ? (int) $r['contact_id'] : null, 'jumlah' => $jumlah];
        }

        $accounts = $clean === [] ? collect() : Account::query()->whereIn('id', array_column($clean, 'account_id'))->get()->keyBy('id');
        foreach ($clean as $c) {
            $a = $accounts->get($c['account_id']) ?? throw JournalException::accountNotFound($c['account_id']);
            if ($a->is_header) {
                throw JournalException::accountHeader($a->kode);
            }
            if (! $a->is_aktif) {
                throw JournalException::accountInactive($a->kode);
            }
        }
        $this->validator->assertContacts($clean);

        return DB::transaction(function () use ($clean, $user) {
            $companyId = $this->current->id();
            $tanggal = $this->current->company()->tanggal_mulai_pembukuan->toDateString();

            DB::table('opening_balances')->where('company_id', $companyId)->whereNull('journal_id')->delete();
            if ($clean !== []) {
                DB::table('opening_balances')->insert(array_map(fn ($c) => $c + [
                    'company_id' => $companyId, 'tanggal' => $tanggal, 'created_by' => $user->getKey(), 'created_at' => now(),
                ], $clean));
            }
            $this->audit->log('opening_balance', null, 'update', null, ['baris' => count($clean)], null, $companyId, (int) $user->getKey());

            return count($clean);
        });
    }

    /** Memposting seluruh baris draf menjadi satu jurnal saldo awal. */
    public function post(User $user): Journal
    {
        return DB::transaction(function () use ($user) {
            if ($this->activeJournal()) {
                throw JournalException::openingAlreadyPosted();
            }

            $companyId = $this->current->id();
            $draft = DB::table('opening_balances')->where('company_id', $companyId)->whereNull('journal_id')->lockForUpdate()->orderBy('id')->get();
            $stockAmounts = $this->stock->draftAccountAmounts();
            if ($draft->isEmpty() && $stockAmounts === []) {
                throw JournalException::openingEmpty();
            }

            $ids = $draft->pluck('account_id')->merge(array_keys($stockAmounts))->unique()->all();
            $accounts = Account::query()->whereIn('id', $ids)->get()->keyBy('id');
            $lines = [];
            foreach ($draft as $r) {
                $a = $accounts->get($r->account_id) ?? throw JournalException::accountNotFound((int) $r->account_id);
                if (isset($stockAmounts[(int) $r->account_id])) {
                    throw new JournalException("Akun {$a->kode} terisi dari saldo awal stok. Hapus baris manual akun ini; nilainya dihitung dari daftar saldo awal stok.", 'stock_account_conflict', ['kode' => $a->kode]);
                }
                if (in_array($a->sub_tipe, ['receivable', 'payable'], true) && ! $r->contact_id) {
                    throw JournalException::contactRequired($a->kode);
                }

                $abs = Money::cmp((string) $r->jumlah, '0') < 0 ? Money::sub('0', (string) $r->jumlah) : (string) $r->jumlah;
                $sideNormal = Money::cmp((string) $r->jumlah, '0') > 0; // positif = sisi normal akun
                $debitSide = ($a->saldo_normal === 'debit') === $sideNormal;

                $lines[] = [
                    'account_id' => (int) $r->account_id,
                    'debit' => $debitSide ? $abs : '0',
                    'kredit' => $debitSide ? '0' : $abs,
                    'memo' => 'Saldo awal',
                    'contact_id' => $r->contact_id ? (int) $r->contact_id : null,
                ];
            }

            foreach ($stockAmounts as $accountId => $nilai) {
                $a = $accounts->get((int) $accountId) ?? throw JournalException::accountNotFound((int) $accountId);
                $debitSide = $a->saldo_normal === 'debit'; // akun persediaan: aset, normal debit
                $lines[] = [
                    'account_id' => (int) $accountId,
                    'debit' => $debitSide ? $nilai : '0',
                    'kredit' => $debitSide ? '0' : $nilai,
                    'memo' => 'Saldo awal stok',
                    'contact_id' => null,
                ];
            }

            $tanggalBuku = $this->current->company()->tanggal_mulai_pembukuan->toDateString();
            $journal = $this->journals->post(new JournalDraft(
                tanggal: $tanggalBuku,
                tipe: 'opening',
                lines: $lines,
                memo: 'Saldo awal',
                sourceType: 'opening_balance',
                userId: (int) $user->getKey(),
            ));

            if ($draft->isNotEmpty()) {
                DB::table('opening_balances')->whereIn('id', $draft->pluck('id')->all())->update(['journal_id' => $journal->getKey()]);
            }
            $this->stock->commit((int) $journal->getKey(), $tanggalBuku, (int) $user->getKey());

            return $journal;
        });
    }

    /** Membatalkan jurnal saldo awal; baris kembali menjadi draf agar bisa diperbaiki dan diposting ulang. */
    public function void(string $reason, User $user): Journal
    {
        return DB::transaction(function () use ($reason, $user) {
            $j = $this->activeJournal() ?? throw JournalException::notReversible('Belum ada jurnal saldo awal yang aktif.');

            $rev = $this->journals->reverse($j, $reason, (int) $user->getKey());

            DB::table('opening_balances')
                ->where('company_id', $this->current->id())
                ->where('journal_id', $j->getKey())
                ->update(['journal_id' => null]);
            $this->stock->revert((int) $j->getKey(), $rev->tanggal->toDateString());

            return $rev;
        });
    }

    private function signedAmount(mixed $raw, int $row): string
    {
        $s = is_float($raw) ? rtrim(rtrim(number_format($raw, 6, '.', ''), '0'), '.') : (string) ($raw ?? '');
        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $s)) {
            throw new JournalException("Baris {$row}: jumlah harus angka dengan paling banyak 4 desimal.", 'invalid_amount');
        }

        return Money::round($s);
    }
}
