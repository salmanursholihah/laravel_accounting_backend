<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\ChartOfAccounts\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private const TIPE = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense', 'other_income', 'other_expense'];

    /** ?tree=1 untuk tampilan pohon; ?aktif=1 hanya akun aktif; ?postable=1 untuk pemilih akun di form transaksi. */
    public function index(Request $request): JsonResponse
    {
        $query = Account::query()->orderBy('kode');
        if ($request->boolean('aktif') || $request->boolean('postable')) {
            $query->where('is_aktif', true);
        }
        if ($request->boolean('postable')) {
            $query->where('is_header', false);
        }

        $rows = $query->get()->map(fn (Account $a) => $this->present($a));

        if ($request->boolean('tree')) {
            $byParent = $rows->groupBy(fn ($r) => $r['parent_id'] ?? 0);
            $build = function ($parentId) use (&$build, $byParent) {
                return ($byParent[$parentId] ?? collect())->map(fn ($r) => $r + ['children' => $build($r['id'])])->values();
            };

            return response()->json(['data' => $build(0)]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    public function store(Request $request, AccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z\-.]+$/'],
            'nama' => ['required', 'string', 'max:190'],
            'tipe' => ['required', Rule::in(self::TIPE)],
            'sub_tipe' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer'],
            'is_header' => ['sometimes', 'boolean'],
            'saldo_normal' => ['sometimes', 'in:debit,credit'],
        ]);

        return response()->json(['data' => $this->present($accounts->create($data, $request->user()))], 201);
    }

    public function update(Request $request, int $id, AccountService $accounts): JsonResponse
    {
        $account = Account::query()->findOrFail($id);

        $data = $request->validate([
            'kode' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9A-Za-z\-.]+$/'],
            'nama' => ['sometimes', 'string', 'max:190'],
            'tipe' => ['sometimes', Rule::in(self::TIPE)],
            'sub_tipe' => ['sometimes', 'nullable', 'string', 'max:40'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'is_header' => ['sometimes', 'boolean'],
            'is_aktif' => ['sometimes', 'boolean'],
            'saldo_normal' => ['sometimes', 'in:debit,credit'],
        ]);

        return response()->json(['data' => $this->present($accounts->update($account, $data, $request->user()))]);
    }

    public function destroy(int $id, AccountService $accounts): JsonResponse
    {
        $accounts->delete(Account::query()->findOrFail($id));

        return response()->json(['message' => 'Akun dihapus.']);
    }

    private function present(Account $a): array
    {
        return [
            'id' => $a->id, 'kode' => $a->kode, 'nama' => $a->nama, 'tipe' => $a->tipe, 'sub_tipe' => $a->sub_tipe,
            'parent_id' => $a->parent_id, 'saldo_normal' => $a->saldo_normal,
            'is_header' => $a->is_header, 'is_aktif' => $a->is_aktif,
        ];
    }
}
