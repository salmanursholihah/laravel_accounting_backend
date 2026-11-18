<?php

namespace App\Modules\CashBank\Documents;

use App\Models\User;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\CashBankException;
use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transfer Bank (UC-25): Dr Bank Tujuan · Cr Bank Asal (+ Dr Beban Admin Bank, Cr Bank Asal).
 * Dokumen tanpa baris. Rupiah saja (kurs = 1); mata uang asing ditunda sesuai keputusan analisis.
 */
class BankTransferService
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
        private PeriodService $periods,
        private BankAccountService $banks,
    ) {
    }

    public function docType(): string
    {
        return 'bank_transfer';
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'rekening_asal_id' => ['required', 'integer'],
            'rekening_tujuan_id' => ['required', 'integer', 'different:rekening_asal_id'],
            'nominal' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'biaya_admin' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function find(int $id): BankTransfer
    {
        return BankTransfer::query()->findOrFail($id);
    }

    public function create(array $data, User $user): BankTransfer
    {
        $header = $this->prepare($data);

        return DB::transaction(function () use ($header, $user) {
            $doc = BankTransfer::create($header + [
                'status' => 'draft', 'version' => 1, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->audit->log($this->docType(), (int) $doc->getKey(), 'create', null, $this->snapshot($doc));

            return $doc;
        });
    }

    public function update(int $id, array $data, User $user): BankTransfer
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = BankTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($doc->status !== 'draft') {
                throw CashBankException::notDraft((string) $doc->status);
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw CashBankException::conflict();
            }

            $before = $this->snapshot($doc);
            $doc->forceFill($this->prepare($data) + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            $this->audit->log($this->docType(), (int) $doc->getKey(), 'update', $before, $this->snapshot($doc));

            return $doc;
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id) {
            $doc = BankTransfer::query()->lockForUpdate()->findOrFail($id);
            if ($doc->status !== 'draft') {
                throw CashBankException::notDraft((string) $doc->status);
            }
            $this->audit->log($this->docType(), (int) $doc->getKey(), 'delete', $this->snapshot($doc), null);
            $doc->delete();
        });
    }

    private function prepare(array $data): array
    {
        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);

        if ((int) $data['rekening_asal_id'] === (int) $data['rekening_tujuan_id']) {
            throw new CashBankException('Rekening asal dan tujuan tidak boleh sama.', 'same_bank_account');
        }
        $asal = $this->banks->assertActive((int) $data['rekening_asal_id']);
        $tujuan = $this->banks->assertActive((int) $data['rekening_tujuan_id']);

        $nominal = Money::round((string) $data['nominal']);
        $biaya = Money::round((string) ($data['biaya_admin'] ?? '0'));
        if (Money::cmp($nominal, '0') <= 0) {
            throw new CashBankException('Nominal transfer harus lebih dari 0.', 'amount_invalid');
        }
        if (Money::cmp($biaya, '0') < 0) {
            throw new CashBankException('Biaya admin tidak boleh negatif.', 'amount_invalid');
        }

        return [
            'tanggal' => $tanggal,
            'rekening_asal_id' => $asal->getKey(),
            'rekening_tujuan_id' => $tujuan->getKey(),
            'nominal' => $nominal,
            'biaya_admin' => $biaya,
            'memo' => $data['memo'] ?? null,
            'kode_mata_uang' => $this->current->company()->mata_uang_dasar,
            'kurs' => '1',
        ];
    }

    private function snapshot(BankTransfer $d): array
    {
        return [
            'tanggal' => $d->tanggal?->toDateString(), 'rekening_asal_id' => $d->rekening_asal_id,
            'rekening_tujuan_id' => $d->rekening_tujuan_id, 'nominal' => (string) $d->nominal,
            'biaya_admin' => (string) $d->biaya_admin, 'memo' => $d->memo,
        ];
    }
}
