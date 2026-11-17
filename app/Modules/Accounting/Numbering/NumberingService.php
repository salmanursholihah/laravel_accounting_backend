<?php

namespace App\Modules\Accounting\Numbering;

use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * ALG-01: nomor dokumen unik, berurutan, tanpa celah.
 * - next() HARUS dipanggil di dalam transaksi posting yang sama: kunci baris (FOR UPDATE) ditahan sampai commit,
 *   dan bila posting gagal, kenaikan counter ikut batal sehingga tidak ada celah.
 * - Satu baris dengan period_key '-' menyimpan konfigurasi (prefix, format, reset) per jenis dokumen.
 *   Untuk reset yearly/monthly, counter ada di baris per periode ("2026" / "2026-11") yang menyalin konfigurasi itu.
 */
class NumberingService
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    /** Mengambil dan menaikkan nomor berikutnya. Panggil saat Posting, bukan saat Draft. */
    public function next(string $docType, DateTimeInterface|string $tanggal): string
    {
        $this->assertKnown($docType);
        $companyId = $this->current->id();
        $date = CarbonImmutable::parse($tanggal);

        return DB::transaction(function () use ($companyId, $docType, $date) {
            $tpl = $this->templateRow($companyId, $docType);
            $key = $this->periodKey($tpl->reset, $date);

            if ($key !== '-') {
                DB::table('number_sequences')->insertOrIgnore([
                    'company_id' => $companyId, 'doc_type' => $docType, 'period_key' => $key,
                    'prefix' => $tpl->prefix, 'format' => $tpl->format, 'reset' => $tpl->reset,
                    'nomor_berikut' => 1, 'updated_at' => now(),
                ]);
            }

            $row = DB::table('number_sequences')
                ->where(['company_id' => $companyId, 'doc_type' => $docType, 'period_key' => $key])
                ->lockForUpdate()
                ->first();

            $n = (int) $row->nomor_berikut;
            DB::table('number_sequences')->where('id', $row->id)->update(['nomor_berikut' => $n + 1, 'updated_at' => now()]);

            return $this->render($row->format, $row->prefix, $n, $date);
        });
    }

    /** Contoh nomor berikutnya tanpa mengonsumsi counter (untuk layar Penomoran). */
    public function preview(string $docType, DateTimeInterface|string|null $tanggal = null): string
    {
        $this->assertKnown($docType);
        $companyId = $this->current->id();
        $date = CarbonImmutable::parse($tanggal ?? now());

        $tpl = $this->templateRow($companyId, $docType);
        $key = $this->periodKey($tpl->reset, $date);
        $row = DB::table('number_sequences')
            ->where(['company_id' => $companyId, 'doc_type' => $docType, 'period_key' => $key])
            ->first();

        return $this->render($tpl->format, $tpl->prefix, (int) ($row->nomor_berikut ?? 1), $date);
    }

    /** UC-07: ubah konfigurasi. Validasi aturan format dilakukan di controller (formatError). */
    public function configure(string $docType, string $prefix, string $format, string $reset): void
    {
        $this->assertKnown($docType);
        $companyId = $this->current->id();

        DB::transaction(function () use ($companyId, $docType, $prefix, $format, $reset) {
            $tpl = $this->templateRow($companyId, $docType);
            $before = ['prefix' => $tpl->prefix, 'format' => $tpl->format, 'reset' => $tpl->reset];

            $rows = DB::table('number_sequences')->where(['company_id' => $companyId, 'doc_type' => $docType])->lockForUpdate()->get();

            // Pindah ke reset 'none': counter tunggal harus meneruskan nomor tertinggi agar tidak ada nomor ganda.
            $carry = ($reset === 'none' && $tpl->reset !== 'none') ? (int) $rows->max('nomor_berikut') : null;

            DB::table('number_sequences')
                ->where(['company_id' => $companyId, 'doc_type' => $docType])
                ->update(['prefix' => $prefix, 'format' => $format, 'reset' => $reset, 'updated_at' => now()]);

            if ($carry !== null) {
                DB::table('number_sequences')
                    ->where(['company_id' => $companyId, 'doc_type' => $docType, 'period_key' => '-'])
                    ->update(['nomor_berikut' => $carry]);
            }

            $this->audit->log('number_sequence', null, 'update', $before, compact('prefix', 'format', 'reset') + ['doc_type' => $docType]);
        });
    }

    /** Membuat konfigurasi bawaan untuk semua jenis dokumen (dipanggil saat perusahaan dibuat). */
    public function seedDefaults(): void
    {
        $companyId = $this->current->id();

        foreach (array_keys(config('numbering.types')) as $docType) {
            $this->templateRow($companyId, $docType);
        }
    }

    /** Pesan galat bila kombinasi format dan reset berisiko menghasilkan nomor ganda; null bila valid. */
    public static function formatError(string $format, string $reset): ?string
    {
        if (! preg_match('/\{SEQ(:\d+)?\}/', $format)) {
            return 'Format wajib memuat {SEQ} atau {SEQ:n}.';
        }
        if (preg_match('/\{(?!(PREFIX|YYYY|YY|MM|SEQ(:\d+)?)\})/', $format)) {
            return 'Format memuat token yang tidak dikenal. Token yang tersedia: {PREFIX} {YYYY} {YY} {MM} {SEQ:n}.';
        }
        $hasYear = str_contains($format, '{YYYY}') || str_contains($format, '{YY}');
        if ($reset === 'monthly' && ! ($hasYear && str_contains($format, '{MM}'))) {
            return 'Reset bulanan mewajibkan format memuat tahun ({YYYY}/{YY}) dan bulan ({MM}).';
        }
        if ($reset === 'yearly' && ! $hasYear) {
            return 'Reset tahunan mewajibkan format memuat tahun ({YYYY}/{YY}).';
        }

        return null;
    }

    private function templateRow(int $companyId, string $docType): object
    {
        $where = ['company_id' => $companyId, 'doc_type' => $docType, 'period_key' => '-'];

        $row = DB::table('number_sequences')->where($where)->first();
        if ($row) {
            return $row;
        }

        DB::table('number_sequences')->insertOrIgnore($where + [
            'prefix' => config("numbering.types.{$docType}.prefix"),
            'format' => config('numbering.default_format'),
            'reset' => config('numbering.default_reset'),
            'nomor_berikut' => 1,
            'updated_at' => now(),
        ]);

        return DB::table('number_sequences')->where($where)->first();
    }

    private function periodKey(string $reset, CarbonImmutable $date): string
    {
        return match ($reset) {
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Y-m'),
            default => '-',
        };
    }

    private function render(string $format, string $prefix, int $n, CarbonImmutable $date): string
    {
        return preg_replace_callback('/\{(PREFIX|YYYY|YY|MM|SEQ(?::(\d+))?)\}/', function (array $m) use ($prefix, $n, $date) {
            return match (true) {
                $m[1] === 'PREFIX' => $prefix,
                $m[1] === 'YYYY' => $date->format('Y'),
                $m[1] === 'YY' => $date->format('y'),
                $m[1] === 'MM' => $date->format('m'),
                default => str_pad((string) $n, (int) ($m[2] ?? 1), '0', STR_PAD_LEFT),
            };
        }, $format);
    }

    private function assertKnown(string $docType): void
    {
        if (! array_key_exists($docType, config('numbering.types', []))) {
            throw new InvalidArgumentException("Jenis dokumen '{$docType}' tidak dikenal.");
        }
    }
}
