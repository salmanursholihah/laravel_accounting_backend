<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * 8 validasi jurnal (Tahap 4 bagian 2):
 *  1 debit = kredit                       normalizeLines()
 *  2 minimal 2 baris, satu sisi per baris normalizeLines()
 *  3 akun valid, aktif, bukan induk       assertAccounts() (+ assertContacts untuk subledger)
 *  4 periode Open                         PeriodService::assertPostable()  (dipanggil JournalService)
 *  5 tidak lebih awal dari mulai buku     PeriodService::assertPostable()
 *  6 Posted tidak bisa diubah             trigger MySQL + tidak ada API update; koreksi hanya lewat pembalik
 *  7 source_type dan source_id            assertSource()
 *  8 nomor dari Penomoran, tanpa celah    NumberingService::next() dalam transaksi yang sama
 */
class JournalValidator
{
    private const AMOUNT = '/^\d+(\.\d{1,4})?$/';

    public function __construct(private CurrentCompany $current)
    {
    }

    /**
     * Murni (tanpa database): menormalkan baris ke string DECIMAL(19,4) dan memeriksa aturan 1 dan 2.
     * Nominal lebih dari 4 desimal ditolak (bukan dibulatkan diam-diam).
     *
     * @return array{lines: list<array>, total_debit: string, total_kredit: string}
     */
    public static function normalizeLines(array $lines): array
    {
        $lines = array_values($lines);
        if (count($lines) < 2) {
            throw JournalException::tooFewLines();
        }

        $out = [];
        $td = '0';
        $tk = '0';

        foreach ($lines as $i => $l) {
            $no = $i + 1;
            $debit = self::amount($l['debit'] ?? null, $no, 'debit');
            $kredit = self::amount($l['kredit'] ?? null, $no, 'kredit');
            $hasD = Money::cmp($debit, '0') > 0;
            $hasK = Money::cmp($kredit, '0') > 0;

            if ($hasD && $hasK) {
                throw JournalException::lineBothSides($no);
            }
            if (! $hasD && ! $hasK) {
                throw JournalException::lineEmpty($no);
            }
            if (empty($l['account_id']) || ! is_numeric($l['account_id']) || (int) $l['account_id'] <= 0) {
                throw JournalException::accountNotFound((int) ($l['account_id'] ?? 0));
            }

            $out[] = [
                'line_no' => $no,
                'account_id' => (int) $l['account_id'],
                'debit' => $debit,
                'kredit' => $kredit,
                'memo' => isset($l['memo']) && $l['memo'] !== '' ? mb_substr((string) $l['memo'], 0, 500) : null,
                'contact_id' => isset($l['contact_id']) && $l['contact_id'] !== '' ? (int) $l['contact_id'] : null,
            ];
            $td = Money::add($td, $debit);
            $tk = Money::add($tk, $kredit);
        }

        $td = Money::round($td);
        $tk = Money::round($tk);
        if (Money::cmp($td, $tk) !== 0) {
            throw JournalException::unbalanced($td, $tk);
        }

        return ['lines' => $out, 'total_debit' => $td, 'total_kredit' => $tk];
    }

    private static function amount(mixed $raw, int $line, string $field): string
    {
        if ($raw === null || $raw === '') {
            return Money::round('0');
        }
        if (is_float($raw)) {
            $raw = rtrim(rtrim(number_format($raw, 6, '.', ''), '0'), '.');
        }
        $s = (string) $raw;
        if (! preg_match(self::AMOUNT, $s)) {
            throw JournalException::invalidAmount($line, $field);
        }

        return Money::round($s);
    }

    /** Aturan 7. */
    public function assertSource(JournalDraft $d): void
    {
        if (! in_array($d->tipe, JournalDraft::TYPES, true)) {
            throw JournalException::unknownType($d->tipe);
        }
        if ($d->sourceType === null || trim($d->sourceType) === '' || mb_strlen($d->sourceType) > 60) {
            throw JournalException::sourceRequired();
        }
        if (in_array($d->tipe, ['auto', 'correction', 'reversal'], true) && ! $d->sourceId) {
            throw JournalException::sourceRequired();
        }
    }

    /** Aturan 3: semua akun milik perusahaan aktif (query ber-scope), aktif, dan bukan induk. */
    public function assertAccounts(array $lines): void
    {
        $ids = array_values(array_unique(array_column($lines, 'account_id')));
        $accounts = Account::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $a = $accounts->get($id) ?? throw JournalException::accountNotFound($id);
            if ($a->is_header) {
                throw JournalException::accountHeader($a->kode);
            }
            if (! $a->is_aktif) {
                throw JournalException::accountInactive($a->kode);
            }
        }
    }

    /** Kontak subledger harus milik perusahaan yang sama. */
    public function assertContacts(array $lines): void
    {
        $ids = array_values(array_unique(array_filter(array_column($lines, 'contact_id'))));
        if ($ids === []) {
            return;
        }

        $found = DB::table('contacts')
            ->where('company_id', $this->current->id())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            if (! in_array($id, $found, false)) {
                throw JournalException::contactNotFound((int) $id);
            }
        }
    }
}
