<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use App\Modules\Platform\Models\Company;

/**
 * Dikirim di dalam transaksi pembuatan perusahaan. Modul lain (Accounting, dst.) menyiapkan
 * data awalnya lewat listener, supaya Platform tidak bergantung pada modul di atasnya.
 * $template = template bagan akun: dagang | jasa | manufaktur.
 */
class CompanyCreated
{
    public function __construct(public Company $company, public User $owner, public string $template = 'dagang')
    {
    }
}
