<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToCompany;

    protected $table = 'branches';

    protected $fillable = ['company_id', 'kode', 'nama', 'is_default', 'is_aktif'];

    protected $casts = ['is_default' => 'boolean', 'is_aktif' => 'boolean'];
}
