<?php

namespace App\Modules\Accounting\Periods;

use DomainException;
use Illuminate\Http\JsonResponse;

class PeriodException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'period_error')
    {
        parent::__construct($message);
    }

    public static function beforeBookStart(string $date, string $mulai): self
    {
        return new self("Tanggal {$date} lebih awal dari tanggal mulai pembukuan ({$mulai}).", 'before_book_start');
    }

    public static function notFound(string $date): self
    {
        return new self("Tidak ada periode akuntansi untuk tanggal {$date}. Buat tahun buku terlebih dahulu.", 'period_not_found');
    }

    public static function closed(AccountingPeriod $period): self
    {
        return new self(
            'Periode ' . $period->tanggal_mulai->format('m/Y') . ' sudah dikunci. Gunakan jurnal koreksi di periode terbuka.',
            'period_closed',
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], 422);
    }
}
