<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Http\Requests\LoginRequest;
use App\Modules\Platform\Models\CompanyUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** UC-01: login, logout, dan profil sesi. Pemilihan perusahaan tetap di CompanyController::select. */
class AuthController extends Controller
{
    public function login(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $cred = $request->only('email', 'password') + ['status' => 'active'];

        // Pesan sama untuk email salah, sandi salah, dan akun ditangguhkan (tidak membocorkan keberadaan akun)
        if (! Auth::guard('web')->attempt($cred)) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login' => now()])->save();
        $audit->log('user', $user->getKey(), 'login', userId: $user->getKey());

        return response()->json(['data' => $this->payload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $id = $request->user()?->getKey();
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        if ($id) {
            $audit->log('user', $id, 'logout', userId: $id);
        }

        return response()->json(['message' => 'Anda sudah keluar.']);
    }

    private function payload(User $user): array
    {
        $companies = CompanyUser::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->with(['company', 'role'])
            ->get()
            ->filter(fn ($m) => $m->company->status === 'active')
            ->map(fn ($m) => [
                'public_id' => $m->company->public_id,
                'nama' => $m->company->nama,
                'role' => $m->role->nama,
            ])
            ->values();

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin_platform' => (bool) $user->is_admin_platform,
            'companies' => $companies,
        ];
    }
}
