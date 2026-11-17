<?php

namespace App\Modules\Accounting\PaymentTerms;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    use BelongsToCompany;

    protected $table = 'payment_terms';

    protected $fillable = ['company_id', 'nama', 'jumlah_hari', 'diskon_persen', 'diskon_hari', 'is_aktif'];

    protected $casts = [
        'jumlah_hari' => 'integer',
        'diskon_persen' => 'decimal:4',
        'diskon_hari' => 'integer',
        'is_aktif' => 'boolean',
    ];

    /** Jatuh tempo = tanggal dokumen + jumlah hari (dipakai Faktur Penjualan/Pembelian nanti). */
    public function jatuhTempo(CarbonInterface|string $tanggal): Carbon
    {
        return Carbon::parse($tanggal)->startOfDay()->addDays($this->jumlah_hari);
    }

    /** Batas akhir diskon pelunasan cepat; null bila syarat ini tidak punya diskon. */
    public function batasDiskon(CarbonInterface|string $tanggal): ?Carbon
    {
        return bccomp((string) $this->diskon_persen, '0', 4) > 0
            ? Carbon::parse($tanggal)->startOfDay()->addDays($this->diskon_hari)
            : null;
    }
}
