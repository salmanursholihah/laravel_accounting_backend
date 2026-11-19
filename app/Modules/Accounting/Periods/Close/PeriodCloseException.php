<?php

namespace App\Modules\Accounting\Periods\Close;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan Proses Akhir Bulan dan Tutup Tahun (ALG-11). Membawa hasil checklist bila ada. */
class PeriodCloseException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'period_close_error',
        public readonly ?array $hasil = null,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function alreadyClosed(): self
    {
        return new self('Periode sudah dikunci.', 'already_closed');
    }

    public static function useFiscalYearClose(): self
    {
        return new self('Periode terakhir tahun buku hanya ditutup lewat Tutup Tahun Buku (jurnal penutup diterbitkan saat periode ini masih terbuka).', 'use_fiscal_year_close');
    }

    public static function blocked(array $hasil): self
    {
        return new self('Penutupan ditolak: ada pemeriksaan yang gagal. Perbaiki lalu coba lagi.', 'close_blocked', $hasil);
    }

    public static function confirmRequired(array $hasil): self
    {
        return new self('Ada peringatan pada checklist. Kirim konfirmasi = true bila tetap ingin menutup.', 'confirm_required', $hasil, 409);
    }

    public static function yearNotOpen(): self
    {
        return new self('Tahun buku sudah ditutup.', 'year_not_open');
    }

    public static function yearNotClosed(): self
    {
        return new self('Tahun buku belum ditutup.', 'year_not_closed');
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan wajib diisi.', 'reason_required');
    }

    public static function laterYearClosed(int $tahun): self
    {
        return new self("Tahun buku {$tahun} yang lebih baru sudah ditutup. Buka kembali dari tahun terbaru ke belakang.", 'later_year_closed');
    }

    public static function closingExists(): self
    {
        return new self('Tahun buku ini sudah punya jurnal penutup aktif. Periksa data sebelum menutup lagi.', 'closing_exists');
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->errorCode] + ($this->hasil !== null ? ['hasil' => $this->hasil] : []),
            $this->status,
        );
    }
}
