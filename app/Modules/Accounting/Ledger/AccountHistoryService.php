<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Histori Akun (UC-22): mutasi satu akun dalam rentang tanggal dengan saldo berjalan, dibaca dari journal_lines.
 * Paginasi keyset (tanggal, id baris); saldo berjalan tetap benar di halaman berikutnya.
 * Akun neraca: saldo awal = semua mutasi sebelum 'dari' (termasuk jurnal penutup).
 * Akun laba rugi: saldo awal hanya dari awal tahun buku yang sama (reset tiap tahun buku), tanpa jurnal penutup.
 * Jurnal penutup tidak ditampilkan di mutasi kecuali $includeClosing.
 */
class AccountHistoryService
{
    private const PL_TYPES = ['revenue', 'cogs', 'expense', 'other_income', 'other_expense'];

    public function __construct(private CurrentCompany $current, private PeriodService $periods)
    {
    }

    public function history(int $accountId, string $dari, string $sampai, ?string $cursor = null, int $limit = 100, bool $includeClosing = false): array
    {
        $companyId = $this->current->id();
        $account = Account::query()->findOrFail($accountId);
        $limit = max(1, min($limit, 500));
        $sign = $account->saldo_normal === 'debit' ? 1 : -1;

        $opening = $this->opening($account, $dari);

        $range = fn () => DB::table('journal_lines as jl')
            ->join('journals as j', function ($q) {
                $q->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id');
            })
            ->where('jl.company_id', $companyId)
            ->where('jl.account_id', $accountId)
            ->whereBetween('jl.tanggal', [$dari, $sampai])
            ->when(! $includeClosing, fn ($q) => $q->where('j.is_closing', false));

        $tot = $range()->selectRaw('COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.kredit),0) AS k')->first();

        $running = $opening;
        $page = $range();
        if ($cursor !== null) {
            [$cTanggal, $cId] = $this->parseCursor($cursor);
            $before = $range()
                ->where(fn ($q) => $q->where('jl.tanggal', '<', $cTanggal)->orWhere(fn ($w) => $w->where('jl.tanggal', $cTanggal)->where('jl.id', '<=', $cId)))
                ->selectRaw('COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.kredit),0) AS k')->first();
            $running = Money::add($running, $this->net($before->d, $before->k, $sign));
            $page->where(fn ($q) => $q->where('jl.tanggal', '>', $cTanggal)->orWhere(fn ($w) => $w->where('jl.tanggal', $cTanggal)->where('jl.id', '>', $cId)));
        }

        $rows = $page->orderBy('jl.tanggal')->orderBy('jl.id')->limit($limit + 1)->get([
            'jl.id as line_id', 'jl.journal_id', 'j.nomor', 'jl.tanggal', 'j.tipe', 'j.source_type', 'j.source_id',
            'jl.memo', 'jl.debit', 'jl.kredit', 'jl.contact_id',
        ]);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->map(function ($r) use (&$running, $sign) {
            $running = Money::add($running, $this->net($r->debit, $r->kredit, $sign));

            return [
                'line_id' => (int) $r->line_id, 'journal_id' => (int) $r->journal_id, 'nomor' => $r->nomor,
                'tanggal' => $r->tanggal, 'tipe' => $r->tipe, 'source_type' => $r->source_type, 'source_id' => $r->source_id,
                'memo' => $r->memo, 'contact_id' => $r->contact_id,
                'debit' => Money::round((string) $r->debit), 'kredit' => Money::round((string) $r->kredit),
                'saldo' => Money::round($running),
            ];
        })->values();

        $last = $rows->last();

        return [
            'account' => ['id' => (int) $account->id, 'kode' => $account->kode, 'nama' => $account->nama, 'saldo_normal' => $account->saldo_normal],
            'dari' => $dari, 'sampai' => $sampai,
            'saldo_awal' => Money::round($opening),
            'total_debit' => Money::round((string) $tot->d),
            'total_kredit' => Money::round((string) $tot->k),
            'saldo_akhir' => Money::round(Money::add($opening, $this->net($tot->d, $tot->k, $sign))),
            'rows' => $rows,
            'next_cursor' => ($hasMore && $last) ? $last['tanggal'] . '|' . $last['line_id'] : null,
        ];
    }

    private function opening(Account $account, string $dari): string
    {
        $q = DB::table('journal_lines as jl')
            ->join('journals as j', function ($q) {
                $q->on('j.id', '=', 'jl.journal_id')->on('j.company_id', '=', 'jl.company_id');
            })
            ->where('jl.company_id', $this->current->id())
            ->where('jl.account_id', $account->getKey())
            ->where('jl.tanggal', '<', $dari);

        if (in_array($account->tipe, self::PL_TYPES, true)) {
            $fyStart = $this->periods->periodFor($dari)?->fiscalYear?->tanggal_mulai?->toDateString();
            if ($fyStart === null) {
                return '0';
            }
            $q->where('jl.tanggal', '>=', $fyStart)->where('j.is_closing', false);
        }

        $r = $q->selectRaw('COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.kredit),0) AS k')->first();

        return $this->net($r->d, $r->k, $account->saldo_normal === 'debit' ? 1 : -1);
    }

    private function net(mixed $debit, mixed $kredit, int $sign): string
    {
        $n = Money::sub((string) $debit, (string) $kredit);

        return $sign === 1 ? $n : Money::sub('0', $n);
    }

    private function parseCursor(string $cursor): array
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d+)$/', $cursor, $m)) {
            throw new JournalException('Cursor tidak valid.', 'invalid_cursor');
        }

        return [$m[1], (int) $m[2]];
    }
}
