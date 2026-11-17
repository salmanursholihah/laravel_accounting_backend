<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu VERSI tarif suatu kode pajak (kode + berlaku_dari).
 * Semua tarif adalah data; tidak ada angka tarif atau DPP di kode program.
 */
class TaxCode extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_codes';

    protected $fillable = [
        'company_id', 'kode', 'nama', 'jenis', 'tarif', 'dpp_pembilang', 'dpp_penyebut',
        'akun_penjualan_id', 'akun_pembelian_id', 'berlaku_dari', 'berlaku_sampai', 'is_aktif',
    ];

    protected $casts = [
        'tarif' => 'decimal:6',
        'dpp_pembilang' => 'integer',
        'dpp_penyebut' => 'integer',
        'berlaku_dari' => 'date',
        'berlaku_sampai' => 'date',
        'is_aktif' => 'boolean',
    ];

    public function berlakuPada(CarbonInterface|string $tanggal): bool
    {
        $t = \Carbon\Carbon::parse($tanggal)->toDateString();

        return $this->berlaku_dari->toDateString() <= $t
            && ($this->berlaku_sampai === null || $this->berlaku_sampai->toDateString() >= $t);
    }
}
