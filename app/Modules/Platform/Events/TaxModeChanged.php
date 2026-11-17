<?php

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Models\Company;

/**
 * Dikirim di dalam transaksi pengubahan Preferensi saat mode pajak (PKP / non-PKP) berganti.
 * Modul Accounting menanggapinya (nonaktifkan kode PPN bagi non-PKP), sehingga Platform
 * tidak memanggil modul di atasnya.
 */
class TaxModeChanged
{
    public function __construct(public Company $company, public bool $isPkp)
    {
    }
}
