<?php

namespace App\Modules\Platform\Access;

use App\Models\User;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ALG-13: can(user, company, fitur, aksi). Dicek di backend pada setiap endpoint.
 * Peran dibaca dari keanggotaan di perusahaan aktif.
 */
final class PermissionChecker
{
    /** @var array<int, array<string, object>> role_id => ["fitur:aksi" => baris] */
    private array $cache = [];

    public function __construct(private CurrentCompany $current)
    {
    }

    public function grant(User $user, string $fitur, string $aksi): ?object
    {
        $membership = $this->current->membership();

        if (! $membership
            || (int) $membership->user_id !== (int) $user->getKey()
            || $membership->status !== 'active') {
            return null;
        }

        return $this->grantsOfRole((int) $membership->role_id)[$fitur . ':' . $aksi] ?? null;
    }

    /**
     * Bila grant bertanda hanya_miliknya dan $document diberikan, dokumen harus dibuat oleh user itu.
     * Tanpa $document (cek tingkat daftar/menu) hasilnya true; pemanggil per-dokumen WAJIB mengirim dokumennya.
     */
    public function can(User $user, string $fitur, string $aksi, ?Model $document = null): bool
    {
        $grant = $this->grant($user, $fitur, $aksi);
        if (! $grant) {
            return false;
        }

        if ($grant->hanya_miliknya && $document !== null) {
            return (int) $document->getAttribute('created_by') === (int) $user->getKey();
        }

        return true;
    }

    /** Nilai batas (mis. batas diskon persen) untuk hak tertentu; null = tanpa batas atau tidak punya hak. */
    public function limit(User $user, string $fitur, string $aksi): ?float
    {
        $grant = $this->grant($user, $fitur, $aksi);

        return ($grant && $grant->nilai_batas !== null) ? (float) $grant->nilai_batas : null;
    }

    /** Seluruh hak role aktif, untuk frontend (menyembunyikan menu). Bukan pengganti cek di backend. */
    public function all(User $user): array
    {
        $membership = $this->current->membership();
        if (! $membership || (int) $membership->user_id !== (int) $user->getKey()) {
            return [];
        }

        return array_values(array_map(fn ($g) => [
            'fitur' => $g->fitur,
            'aksi' => $g->aksi,
            'hanya_miliknya' => (bool) $g->hanya_miliknya,
            'nilai_batas' => $g->nilai_batas !== null ? (float) $g->nilai_batas : null,
        ], $this->grantsOfRole((int) $membership->role_id)));
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function grantsOfRole(int $roleId): array
    {
        return $this->cache[$roleId] ??= DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get(['p.fitur', 'p.aksi', 'rp.hanya_miliknya', 'rp.nilai_batas'])
            ->keyBy(fn ($r) => $r->fitur . ':' . $r->aksi)
            ->all();
    }
}
