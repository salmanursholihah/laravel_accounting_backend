<?php

namespace App\Modules\Accounting\Posting;

use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\Ledger\JournalDraft;
use App\Modules\Accounting\Support\Money;
use Illuminate\Support\Arr;

/**
 * ALG-04: generator jurnal berbasis aturan. Tidak ada if-else per dokumen.
 *
 * Konteks ($ctx) disusun handler dokumen:
 *  values            [nama => nominal string]        mis. total, subtotal, diskon, pajak.ppn, hpp
 *  flags             [nama => bool]                  mis. dari_pengiriman, ada_stok
 *  lists             [nama => list<item>]            untuk aturan 'each'
 *  account_overrides [kunci_akun => account_id]      override akun (urutan: item -> kategori -> default perusahaan,
 *                                                    ditentukan handler; mesin hanya memakainya)
 *  contact_id        ?int                            kontak dokumen
 *
 * Hasil: satu JournalDraft per group, urut kemunculan group. Baris dengan akun, sisi, dan kontak yang sama digabung.
 * Nominal nol dilewati; nominal negatif adalah kesalahan aturan/handler (ditolak, bukan dibalik diam-diam).
 */
class JournalRuleEngine
{
    public function __construct(private JournalRuleRegistry $rules, private SystemAccountService $accounts)
    {
    }

    /** @return list<JournalDraft> */
    public function build(string $docType, array $ctx, string $tanggal, int $sourceId, int $userId, ?string $memo = null, ?int $branchId = null): array
    {
        $rules = $this->rules->rulesFor($docType);
        if ($rules === []) {
            throw PostingException::rule("Dokumen '{$docType}' belum punya aturan jurnal.");
        }

        /** @var array<string, array<string, array>> $groups group => key => baris */
        $groups = [];

        foreach ($rules as $rule) {
            if ($rule->when !== null && ! $this->truthy($ctx, $rule->when)) {
                continue;
            }

            foreach ($this->amountsOf($rule, $ctx) as $a) {
                if (Money::isZero($a['amount'])) {
                    continue;
                }
                if (Money::cmp($a['amount'], '0') < 0) {
                    throw PostingException::rule("Aturan jurnal {$docType} ({$rule->accountKey}) menghasilkan nominal negatif {$a['amount']}.");
                }

                $accountId = $this->accountId($rule, $a, $ctx);
                $contact = $a['contact_id'] ?? ($rule->withContact ? ($ctx['contact_id'] ?? null) : null);
                $key = $accountId . '|' . $rule->side . '|' . ($contact ?? '-');

                if (! isset($groups[$rule->group][$key])) {
                    $groups[$rule->group][$key] = [
                        'account_id' => $accountId,
                        'side' => $rule->side,
                        'amount' => '0',
                        'memo' => $a['memo'] ?? $rule->memo,
                        'contact_id' => $contact,
                    ];
                }
                $groups[$rule->group][$key]['amount'] = Money::add($groups[$rule->group][$key]['amount'], $a['amount']);
            }
        }

        $drafts = [];
        foreach ($groups as $group => $rows) {
            $lines = [];
            $d = '0';
            $k = '0';
            foreach ($rows as $r) {
                $amount = Money::round($r['amount']);
                if (Money::isZero($amount)) {
                    continue;
                }
                if ($r['side'] === 'D') {
                    $d = Money::add($d, $amount);
                } else {
                    $k = Money::add($k, $amount);
                }
                $lines[] = [
                    'account_id' => $r['account_id'],
                    'debit' => $r['side'] === 'D' ? $amount : '0',
                    'kredit' => $r['side'] === 'K' ? $amount : '0',
                    'memo' => $r['memo'],
                    'contact_id' => $r['contact_id'],
                ];
            }
            if ($lines === []) {
                continue;
            }
            if (Money::cmp(Money::round($d), Money::round($k)) !== 0) {
                throw PostingException::rule(
                    "Jurnal {$docType} group '{$group}' tidak seimbang dari aturan: debit {$d}, kredit {$k}. Periksa nilai yang disusun handler."
                );
            }

            $drafts[] = new JournalDraft(
                tanggal: $tanggal,
                tipe: 'auto',
                lines: $lines,
                memo: $memo,
                sourceType: $docType,
                sourceId: $sourceId,
                branchId: $branchId,
                userId: $userId,
            );
        }

        if ($drafts === []) {
            throw PostingException::rule("Dokumen '{$docType}' tidak menghasilkan jurnal (semua nilai nol).");
        }

        return $drafts;
    }

    /** @return list<array{amount:string, account_id?:?int, account_key?:?string, contact_id?:?int, memo?:?string}> */
    private function amountsOf(JournalRule $rule, array $ctx): array
    {
        if ($rule->each !== null) {
            $items = $ctx['lists'][$rule->each] ?? [];

            return array_values(array_map(fn (array $it) => [
                'amount' => Money::round((string) ($it['amount'] ?? '0')),
                'account_id' => $it['account_id'] ?? null,
                'account_key' => $it['account_key'] ?? null,
                'contact_id' => $it['contact_id'] ?? null,
                'memo' => $it['memo'] ?? null,
            ], $items));
        }

        return [['amount' => $this->evaluate($rule->amount, $ctx)]];
    }

    /** Ekspresi "a+b-c": operan angka literal atau jalur di $ctx['values']. Operan yang tidak ada = kesalahan. */
    private function evaluate(string $expr, array $ctx): string
    {
        $tokens = preg_split('/\s*([+-])\s*/', trim($expr), -1, PREG_SPLIT_DELIM_CAPTURE);
        $total = $this->operand((string) array_shift($tokens), $ctx);

        while ($tokens) {
            $op = array_shift($tokens);
            $val = $this->operand((string) array_shift($tokens), $ctx);
            $total = $op === '+' ? Money::add($total, $val) : Money::sub($total, $val);
        }

        return Money::round($total);
    }

    private function operand(string $name, array $ctx): string
    {
        $name = trim($name);
        if ($name === '') {
            throw PostingException::rule('Ekspresi nilai aturan jurnal tidak valid.');
        }
        if (is_numeric($name)) {
            return $name;
        }

        $v = Arr::get($ctx['values'] ?? [], $name);
        if ($v === null || ! is_numeric((string) $v)) {
            throw PostingException::rule("Nilai '{$name}' tidak tersedia di konteks jurnal.");
        }

        return (string) $v;
    }

    private function truthy(array $ctx, string $name): bool
    {
        if (array_key_exists($name, $ctx['flags'] ?? [])) {
            return (bool) $ctx['flags'][$name];
        }
        $v = Arr::get($ctx['values'] ?? [], $name);

        return $v !== null && is_numeric((string) $v) && ! Money::isZero((string) $v);
    }

    private function accountId(JournalRule $rule, array $item, array $ctx): int
    {
        if (! empty($item['account_id'])) {
            return (int) $item['account_id'];
        }
        $key = $item['account_key'] ?? $rule->accountKey;
        if (! empty($ctx['account_overrides'][$key])) {
            return (int) $ctx['account_overrides'][$key];
        }

        return (int) $this->accounts->resolve($key)->getKey();
    }
}
