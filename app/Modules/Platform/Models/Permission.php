<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** Katalog tetap: fitur x aksi (juga hak khusus dengan fitur = 'khusus'). */
class Permission extends Model
{
    public $timestamps = false;

    protected $table = 'permissions';

    protected $fillable = ['fitur', 'aksi'];
}
