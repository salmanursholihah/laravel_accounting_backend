<?php

namespace App\Modules\Accounting\Reports;

use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Umur Piutang dan Umur Utang (6C, ALG-10). Hanya membaca; semua query terfilter company_id perusahaan aktif.
 *
 * Sisa tagihan DIHITUNG ULANG per tanggal laporan, bukan memakai kolom sisa_tagihan (yang hanya benar untuk "hari ini"):
 *   sisa = total faktur - SUM(alokasi dari pembayaran yang berlaku pada tanggal laporan)
 *
 * Sebuah dokumen (faktur atau pembayaran) berlaku pada tanggal T bila:
 *   tanggal <= T  DAN  (status = posted  ATAU  status = void dengan jurnal pembalik bertanggal > T)
 * Jadi laporan bertanggal mundur tetap benar walau dokumen baru di-void kemudian, dan sejalan dengan Buku Besar
 * (jurnal pembalik memakai tanggal asli bila periode terbuka, sehingga dokumen itu tidak pernah berlaku).
 *
 * Bucket (hari = tanggal laporan - jatuh tempo; jatuh tempo kosong memakai tanggal faktur):
 *   belum_jatuh_tempo (<= 0), 1-30, 31-60, 61-90, >90.
 *
 * Belum tercakup (modul belum ada di project): Retur, Uang Muka / kredit pelanggan. Setelah modul itu ada,
 * pengurangnya harus ditambahkan ke rumus sisa di sini.
 */
class AgingReportService
{
    public const BUCKETS = ['belum_jatuh_tempo', 'd1_30', 'd31_60', 'd61_90', 'd_lebih_90'];

    private const SIDES = [
        'piutang' => [
            'inv' => 'sales_invoices', 'alloc' => 'sales_receipt_allocations', 'pay' => 'sales_receipts',
            'inv_fk' => 'sales_invoice_id', 'pay_fk' => 'sales_receipt_id',
        ],
        'utang' => [
            'inv' => 'purchase_invoices', 'alloc' => 'purchase_payment_allocations', 'pay' => 'purchase_payments',
            'inv_fk' => 'purchase_invoice_id', 'pay_fk' => 'purchase_payment_id',
        ],
    ];

    public function __construct(private CurrentCompany $current)
    {
    }

    /**
     * @param string $sisi 'piutang' | 'utang'
     * @param string $mode 'ringkas' (per kontak) | 'rinci' (per kontak + daftar faktur)
     */
    public function aging(string $sisi, string $perTanggal, string $mode = 'ringkas', ?string $kontakKode = null): array
    {
        $rows = $this->openInvoices($sisi, $perTanggal, $kontakKode);
        $asOf = CarbonImmutable::parse($perTanggal)->startOfDay();

        $contacts = [];
        $grand = $this->emptyBuckets();

        foreach ($rows as $r) {
            $due = CarbonImmutable::parse($r->jatuh_tempo ?? $r->tanggal)->startOfDay();
            $hari = (int) $due->diffInDays($asOf, false); // positif = lewat jatuh tempo
            $bucket = self::bucketFor($hari);
            $sisa = Money::round((string) $r->sisa);

            $key = (int) ($r->contact_id ?? 0);
            $contacts[$key] ??= [
                'kode' => $r->kontak_kode, 'nama' => $r->kontak_nama ?? '(tanpa kontak)',
                'buckets' => $this->emptyBuckets(), 'total' => '0.0000', 'jumlah_faktur' => 0, 'faktur' => [],
            ];
            $contacts[$key]['buckets'][$bucket] = Money::add($contacts[$key]['buckets'][$bucket], $sisa);
            $contacts[$key]['total'] = Money::add($contacts[$key]['total'], $sisa);
            $contacts[$key]['jumlah_faktur']++;
            $grand[$bucket] = Money::add($grand[$bucket], $sisa);

            if ($mode === 'rinci') {
                $contacts[$key]['faktur'][] = [
                    'nomor' => $r->nomor, 'tanggal' => (string) $r->tanggal, 'jatuh_tempo' => $r->jatuh_tempo ? (string) $r->jatuh_tempo : null,
                    'hari_lewat_jatuh_tempo' => max($hari, 0), 'bucket' => $bucket,
                    'total' => Money::round((string) $r->total), 'sisa' => $sisa,
                ];
            }
        }

        $list = array_values($contacts);
        usort($list, fn ($a, $b) => Money::cmp($b['total'], $a['total']) ?: strcmp((string) $a['nama'], (string) $b['nama']));

        return [
            'sisi' => $sisi,
            'per_tanggal' => $asOf->toDateString(),
            'mode' => $mode,
            'bucket_keys' => self::BUCKETS,
            'kontak' => $list,
            'total' => ['buckets' => $grand, 'total' => Money::sum(array_values($grand)), 'jumlah_faktur' => $rows->count()],
        ];
    }

    public static function bucketFor(int $hariLewat): string
    {
        return match (true) {
            $hariLewat <= 0 => 'belum_jatuh_tempo',
            $hariLewat <= 30 => 'd1_30',
            $hariLewat <= 60 => 'd31_60',
            $hariLewat <= 90 => 'd61_90',
            default => 'd_lebih_90',
        };
    }

    private function emptyBuckets(): array
    {
        return array_fill_keys(self::BUCKETS, '0.0000');
    }

    /** Faktur dengan sisa > 0 pada tanggal laporan. Nama tabel/kolom berasal dari konstanta, bukan input pengguna. */
    private function openInvoices(string $sisi, string $perTanggal, ?string $kontakKode)
    {
        $s = self::SIDES[$sisi];
        $cid = $this->current->id();

        $sql = "
SELECT i.id, i.nomor, i.tanggal, i.jatuh_tempo, i.total, i.contact_id, c.kode AS kontak_kode, c.nama AS kontak_nama,
       i.total - COALESCE((
           SELECT SUM(a.nominal_dialokasikan)
           FROM {$s['alloc']} a
           JOIN {$s['pay']} p ON p.id = a.{$s['pay_fk']} AND p.company_id = a.company_id
           LEFT JOIN journals rp ON rp.reversed_of_id = p.journal_id AND rp.company_id = p.company_id
           WHERE a.company_id = i.company_id AND a.{$s['inv_fk']} = i.id
             AND p.tanggal <= ?
             AND (p.status = 'posted' OR (p.status = 'void' AND rp.id IS NOT NULL AND rp.tanggal > ?))
       ), 0) AS sisa
FROM {$s['inv']} i
LEFT JOIN contacts c ON c.id = i.contact_id AND c.company_id = i.company_id
LEFT JOIN journals ri ON ri.reversed_of_id = i.journal_id AND ri.company_id = i.company_id
WHERE i.company_id = ? AND i.tanggal <= ?
  AND (i.status = 'posted' OR (i.status = 'void' AND ri.id IS NOT NULL AND ri.tanggal > ?))";
        $bind = [$perTanggal, $perTanggal, $cid, $perTanggal, $perTanggal];

        if ($kontakKode !== null && $kontakKode !== '') {
            $sql .= ' AND c.kode = ?';
            $bind[] = $kontakKode;
        }

        $sql .= ' HAVING sisa > 0 ORDER BY c.nama, i.jatuh_tempo, i.tanggal, i.id';

        return collect(DB::select($sql, $bind));
    }
}
