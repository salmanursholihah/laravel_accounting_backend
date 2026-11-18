<?php

namespace App\Modules\CashBank\History;

use App\Modules\Accounting\Ledger\AccountHistoryService;
use App\Modules\Accounting\Support\Money;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Histori Bank (UC dari fitur 'histori_bank'): mutasi satu rekening dari SEMUA modul, karena semua dokumen
 * (Penerimaan, Pembayaran, Transfer, dan kelak Penerimaan Penjualan/Pembayaran Pembelian) menulis jurnal ke
 * akun bank. Tidak ada tabel sendiri: ini pembacaan journal_lines lewat Histori Akun (saldo berjalan, paginasi keyset).
 */
class BankHistoryService
{
    public function __construct(private AccountHistoryService $history, private CurrentCompany $current)
    {
    }

    public function history(int $bankAccountId, string $dari, string $sampai, ?string $cursor = null, int $limit = 100): array
    {
        $bank = BankAccount::query()->with('account')->find($bankAccountId)
            ?? throw new CashBankException('Rekening kas/bank tidak ditemukan.', 'bank_account_not_found', 404);

        $h = $this->history->history((int) $bank->account_id, $dari, $sampai, $cursor, $limit);
        $labels = collect(config('numbering.types'))->map(fn ($t) => $t['label']);

        $h['rows'] = $h['rows']->map(function (array $r) use ($labels) {
            $r['jenis_dokumen'] = $r['source_type'] ? ($labels[$r['source_type']] ?? $r['source_type']) : 'Jurnal Umum';

            return $r;
        })->values();

        return ['bank_account' => $this->present($bank)] + $h;
    }

    /** Daftar rekening beserta saldo buku per tanggal (default hari ini). */
    public function balances(?string $asOf = null, bool $onlyActive = false): array
    {
        $asOf ??= now()->toDateString();

        $banks = BankAccount::query()->with('account')->when($onlyActive, fn ($q) => $q->where('is_aktif', true))->orderBy('id')->get();
        if ($banks->isEmpty()) {
            return ['as_of' => $asOf, 'rekening' => [], 'total' => '0.0000'];
        }

        $sums = DB::table('journal_lines')
            ->where('company_id', $this->current->id())
            ->whereIn('account_id', $banks->pluck('account_id'))
            ->where('tanggal', '<=', $asOf)
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit),0) AS d, COALESCE(SUM(kredit),0) AS k')
            ->get()->keyBy('account_id');

        $total = '0';
        $rows = $banks->map(function (BankAccount $b) use ($sums, &$total) {
            $s = $sums[$b->account_id] ?? null;
            $saldo = $s ? Money::sub((string) $s->d, (string) $s->k) : '0'; // akun kas/bank bersaldo normal debit
            $total = Money::add($total, $saldo);

            return $this->present($b) + ['saldo' => Money::round($saldo)];
        })->values()->all();

        return ['as_of' => $asOf, 'rekening' => $rows, 'total' => Money::round($total)];
    }

    public function present(BankAccount $b): array
    {
        return [
            'id' => (int) $b->id, 'account_id' => (int) $b->account_id,
            'kode_akun' => $b->account?->kode, 'nama_akun' => $b->account?->nama,
            'nama_bank' => $b->nama_bank, 'no_rekening' => $b->no_rekening, 'atas_nama' => $b->atas_nama,
            'is_kas' => (bool) $b->is_kas, 'is_aktif' => (bool) $b->is_aktif,
        ];
    }
}
