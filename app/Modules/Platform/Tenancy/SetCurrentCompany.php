<?php

namespace App\Modules\Platform\Tenancy;

use App\Modules\Platform\Models\CompanyUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan perusahaan aktif. Klien hanya MEMILIH perusahaan (public_id); hak akses
 * selalu diverifikasi dari keanggotaan (company_users) di setiap request.
 */
class SetCurrentCompany
{
    public function __construct(private CurrentCompany $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Tidak terautentikasi.'], 401);
        }

        $publicId = $request->header('X-Company-Id')
            ?: ($request->hasSession() ? $request->session()->get('active_company') : null);

        if (! $publicId) {
            return response()->json([
                'message' => 'Perusahaan aktif belum dipilih.',
                'code' => 'company_required',
            ], 400);
        }

        $membership = CompanyUser::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereHas('company', fn ($q) => $q->where('public_id', $publicId)->where('status', 'active'))
            ->with(['company', 'role'])
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Anda bukan anggota perusahaan ini.',
                'code' => 'not_a_member',
            ], 403);
        }

        $this->current->set($membership->company, $membership);
        Log::withContext(['company_id' => $membership->company_id, 'user_id' => $user->getKey()]);

        return $next($request);
    }

    public function terminate(): void
    {
        $this->current->clear();
    }
}
