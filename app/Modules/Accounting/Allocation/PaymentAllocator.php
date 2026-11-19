<?php

namespace App\Modules\Accounting\Allocation;

use App\Modules\Accounting\Support\Money;
use DomainException;

/**
 * ALG-06: alokasi pembayaran ke faktur. Murni hitung, tanpa akses database, sehingga dipakai ulang oleh
 * Penerimaan Penjualan (sisi piutang) dan Pembayaran Pembelian (sisi utang).
 *
 * Faktur terbuka: ['id' => int, 'sisa' => string, 'jatuh_tempo' => 'Y-m-d', 'tanggal' => 'Y-m-d'].
 */
final class PaymentAllocator
{
    /**
     * Otomatis: jatuh tempo terlama dulu (lalu tanggal, lalu id).
     *
     * @return array{allocations: list<array{invoice_id:int, nominal:string}>, sisa: string} sisa = nominal yang tak teralokasi
     */
    public static function auto(string $nominal, array $open): array
    {
        usort($open, fn ($a, $b) => [$a['jatuh_tempo'], $a['tanggal'], $a['id']] <=> [$b['jatuh_tempo'], $b['tanggal'], $b['id']]);

        $remaining = Money::round($nominal);
        $out = [];
        foreach ($open as $inv) {
            if (Money::cmp($remaining, '0') <= 0) {
                break;
            }
            $a = Money::cmp($remaining, $inv['sisa']) < 0 ? $remaining : Money::round($inv['sisa']);
            if (Money::cmp($a, '0') > 0) {
                $out[] = ['invoice_id' => (int) $inv['id'], 'nominal' => $a];
                $remaining = Money::sub($remaining, $a);
            }
        }

        return ['allocations' => $out, 'sisa' => Money::round($remaining)];
    }

    /**
     * Manual: tiap alokasi tidak boleh melebihi sisa tagihan fakturnya dan jumlahnya harus sama dengan nominal.
     *
     * @param list<array{invoice_id:int, nominal:string}> $requested
     * @param array<int, array> $openById faktur terbuka berindeks id
     * @return list<array{invoice_id:int, nominal:string}>
     */
    public static function manual(string $nominal, array $requested, array $openById): array
    {
        $sum = '0';
        $seen = [];
        $out = [];
        foreach ($requested as $r) {
            $id = (int) $r['invoice_id'];
            $n = Money::round((string) $r['nominal']);
            if (isset($seen[$id])) {
                throw new DomainException("Faktur #{$id} muncul lebih dari sekali di alokasi.");
            }
            $seen[$id] = true;
            if (Money::cmp($n, '0') <= 0) {
                throw new DomainException('Nominal alokasi harus lebih dari 0.');
            }
            if (! isset($openById[$id])) {
                throw new DomainException("Faktur #{$id} tidak terbuka untuk pembayaran (bukan milik pemasok ini, belum diposting, void, atau sudah lunas).");
            }
            if (Money::cmp($n, $openById[$id]['sisa']) > 0) {
                throw new DomainException("Alokasi ke faktur #{$id} ({$n}) melebihi sisa tagihan ({$openById[$id]['sisa']}).");
            }
            $sum = Money::add($sum, $n);
            $out[] = ['invoice_id' => $id, 'nominal' => $n];
        }

        if (Money::cmp(Money::round($sum), Money::round($nominal)) !== 0) {
            throw new DomainException("Jumlah alokasi ({$sum}) harus sama dengan nominal pembayaran ({$nominal}).");
        }

        return $out;
    }
}
