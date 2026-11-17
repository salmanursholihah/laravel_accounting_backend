<?php

namespace App\Modules\Platform\Tenancy;

use RuntimeException;

class CrossCompanyException extends RuntimeException
{
    public function __construct(string $message = 'Data lintas perusahaan tidak diizinkan.')
    {
        parent::__construct($message);
    }
}
