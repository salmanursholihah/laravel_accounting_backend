<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Http\Requests\StoreCompanyRequest;
use App\Modules\Platform\Models\CompanyUser;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /** UC-03: daftar perusahaan milik user (untuk layar Pilih Perusahaan). */
    public function mine(Request $request): JsonResponse
    {
        $rows = CompanyUser::query()
            ->where('user_id', $request->user()->getKey())
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

        return response()->json(['data' => $rows]);
    }

    /** UC-02 */
    public function store(StoreCompanyRequest $request, CreateCompany $action): JsonResponse
    {
        $company = $action->handle($request->user(), $request->validated());

        return response()->json(['data' => [
            'public_id' => $company->public_id,
            'nama' => $company->nama,
        ]], 201);
    }

    /** Memilih perusahaan aktif untuk sesi (SPA/cookie). Klien token memakai header X-Company-Id. */
    public function select(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'string', 'size:26']]);

        $membership = CompanyUser::query()
            ->where('user_id', $request->user()->getKey())
            ->where('status', 'active')
            ->whereHas('company', fn ($q) => $q->where('public_id', $data['company_id'])->where('status', 'active'))
            ->with('company')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Anda bukan anggota perusahaan ini.', 'code' => 'not_a_member'], 403);
        }

        if ($request->hasSession()) {
            $request->session()->put('active_company', $membership->company->public_id);
        }

        return response()->json(['data' => [
            'public_id' => $membership->company->public_id,
            'nama' => $membership->company->nama,
        ]]);
    }

    public function show(CurrentCompany $current): JsonResponse
    {
        $company = $current->company();
        $membership = $current->membership();

        return response()->json(['data' => [
            'public_id' => $company->public_id,
            'nama' => $company->nama,
            'is_pkp' => $company->is_pkp,
            'mata_uang_dasar' => $company->mata_uang_dasar,
            'tanggal_mulai_pembukuan' => $company->tanggal_mulai_pembukuan?->toDateString(),
            'role' => $membership?->role?->nama,
            'branch_id' => $membership?->branch_id,
        ]]);
    }

    public function permissions(Request $request, PermissionChecker $checker): JsonResponse
    {
        return response()->json(['data' => $checker->all($request->user())]);
    }
}
