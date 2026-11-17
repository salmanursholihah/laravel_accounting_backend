<?php

namespace App\Modules\Platform\Tenancy;

use RuntimeException;

class NoCompanyContext extends RuntimeException
{
    public function __construct(string $message = 'Konteks perusahaan aktif belum ditetapkan.')
    {
        parent::__construct($message);
    }
}
