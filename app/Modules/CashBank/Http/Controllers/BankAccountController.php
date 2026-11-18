<?php

namespace App\Modules\CashBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\History\BankHistoryService;
use App\Modules\CashBank\Models\BankAccount;
use App\Modules\Platform\Access\PermissionChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Master Rekening Kas/Bank. Pemilih rekening di form dokumen boleh dibaca siapa pun yang bisa membuat dokumen Kas & Bank. */
class BankAccountController extends Controller
{
    /** Fitur yang membutuhkan daftar rekening (Kasir hanya punya penerimaan/pembayaran/transfer_bank). */
    private const READ_FEATURES = ['penerimaan', 'pembayaran', 'transfer_bank', 'pencatatan_beban', 'histori_bank', 'akun_perkiraan', 'rekonsiliasi_bank'];

    public function index(Request $request, PermissionChecker $perm, BankHistoryService $service): JsonResponse
    {
        $user = $request->user();
        $allowed = collect(self::READ_FEATURES)->contains(fn ($f) => $perm->can($user, $f, 'view'));
        abort_unless($allowed, 403, 'Anda tidak punya akses ke fitur ini.');

        $request->validate(['as_of' => ['sometimes', 'date'], 'aktif' => ['sometimes', 'boolean']]);

        // saldo hanya untuk yang boleh melihat laporan kas/bank; pemilih form cukup daftar
        $withBalance = $perm->can($user, 'histori_bank', 'view') || $perm->can($user, 'akun_perkiraan', 'view');
        $data = $service->balances($request->input('as_of'), $request->boolean('aktif'));
        if (! $withBalance) {
            $data['rekening'] = array_map(fn ($r) => array_diff_key($r, ['saldo' => 1]), $data['rekening']);
            unset($data['total']);
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, BankAccountService $service, BankHistoryService $history): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required_without:akun_baru', 'nullable', 'integer'],
            'akun_baru' => ['required_without:account_id', 'nullable', 'array'],
            'akun_baru.kode' => ['required_with:akun_baru', 'string', 'max:20'],
            'akun_baru.nama' => ['required_with:akun_baru', 'string', 'max:190'],
            'akun_baru.parent_id' => ['nullable', 'integer'],
            'nama_bank' => ['nullable', 'string', 'max:100'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'atas_nama' => ['nullable', 'string', 'max:150'],
            'is_kas' => ['sometimes', 'boolean'],
        ]);

        $bank = $service->create($data, $request->user());

        return response()->json(['data' => $history->present($bank->load('account'))], 201);
    }

    public function update(Request $request, int $id, BankAccountService $service, BankHistoryService $history): JsonResponse
    {
        $data = $request->validate([
            'nama_bank' => ['nullable', 'string', 'max:100'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'atas_nama' => ['nullable', 'string', 'max:150'],
            'is_kas' => ['sometimes', 'boolean'],
            'is_aktif' => ['sometimes', 'boolean'],
        ]);

        $bank = $service->update(BankAccount::query()->findOrFail($id), $data, $request->user());

        return response()->json(['data' => $history->present($bank->load('account'))]);
    }

    public function destroy(int $id, BankAccountService $service): JsonResponse
    {
        $service->delete(BankAccount::query()->findOrFail($id));

        return response()->json(null, 204);
    }
}
