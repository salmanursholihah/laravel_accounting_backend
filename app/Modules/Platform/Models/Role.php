<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** company_id NULL = role bawaan sistem (is_sistem = true). */
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = ['company_id', 'nama', 'is_sistem'];

    protected $casts = ['is_sistem' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withPivot(['hanya_miliknya', 'nilai_batas']);
    }
}
