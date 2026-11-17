<?php

namespace App\Modules\Platform\Access;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian di route: ->middleware(['company', 'feature:faktur_penjualan,create'])
 * Cek per-dokumen (hanya_miliknya) dilakukan di Policy/service dengan PermissionChecker::can(..., $dokumen).
 */
class RequireFeature
{
    public function __construct(private PermissionChecker $checker)
    {
    }

    public function handle(Request $request, Closure $next, string $fitur, string $aksi): Response
    {
        $user = $request->user();

        if (! $user || ! $this->checker->can($user, $fitur, $aksi)) {
            return response()->json([
                'message' => 'Anda tidak punya akses ke fitur ini.',
                'code' => 'forbidden',
                'fitur' => $fitur,
                'aksi' => $aksi,
            ], 403);
        }

        return $next($request);
    }
}
