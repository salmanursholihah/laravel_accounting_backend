<?php

namespace App\Modules\CashBank\Documents;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\Periods\PeriodService;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Tax\TaxCalculator;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\CashBank\BankAccounts\BankAccountService;
use App\Modules\CashBank\CashBankException;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Siklus Draft dokumen Kas & Bank bergaya header + baris (Penerimaan, Pembayaran, Pencatatan Beban).
 * Hanya Draft yang boleh diubah/dihapus; Posted/Void tidak pernah (Tahap 2 bagian 2). Penomoran dan jurnal
 * baru terjadi saat Posting lewat orkestrator (ALG-02), bukan di sini.
 *
 * Aturan nominal: nominal baris adalah harga SEBELUM pajak (qty 1). Pajak dihitung ALG-03 sehingga
 * total = nominal + pajak (+ pembulatan ke rupiah bila ada pajak). Tanpa kode pajak, total = jumlah nominal apa adanya.
 */
abstract class LineDocumentService
{
    public function __construct(
        protected CurrentCompany $current,
        protected AuditLogger $audit,
        protected TaxCalculator $taxes,
        protected PeriodService $periods,
        protected BankAccountService $banks,
    ) {
    }

    abstract public function docType(): string;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<Model> */
    abstract protected function lineClass(): string;

    abstract protected function lineFk(): string;

    /** Kolom rekening di header: rekening_tujuan_id | rekening_sumber_id | rekening_pembayaran_id. */
    abstract protected function bankColumn(): string;

    /** 'in' = uang masuk ke rekening (Penerimaan); 'out' = uang keluar (Pembayaran, Beban). */
    abstract public function side(): string;

    /** Aturan validasi HTTP; field 'rekening_id' dipetakan ke bankColumn(). */
    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'contact_id' => ['nullable', 'integer'],
            'rekening_id' => ['required', 'integer'],
            'memo' => ['nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.deskripsi' => ['nullable', 'string', 'max:500'],
            'lines.*.nominal' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'lines.*.tax_code_id' => ['nullable', 'integer'],
        ];
    }

    /** Jenis pajak yang boleh dipakai di baris dokumen ini. */
    protected function allowedTaxKinds(): array
    {
        return $this->side() === 'in' ? ['ppn', 'ppnbm'] : ['ppn'];
    }

    /** Kolom header khusus dokumen (mis. dapat_dikreditkan). */
    protected function extraHeader(array $data, ?Model $existing): array
    {
        return [];
    }

    /** Pemeriksaan khusus dokumen; boleh mengubah/menambah $header. */
    protected function validateExtra(array $data, array &$header): void
    {
    }

    public function find(int $id): Model
    {
        $doc = ($this->modelClass())::query()->findOrFail($id);
        $doc->load('lines');

        return $doc;
    }

    public function create(array $data, User $user): Model
    {
        [$header, $lines] = $this->prepare($data, null);

        return DB::transaction(function () use ($header, $lines, $user) {
            $doc = ($this->modelClass())::create($header + [
                'status' => 'draft', 'version' => 1,
                'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->writeLines($doc, $lines);
            $this->audit->log($this->docType(), (int) $doc->getKey(), 'create', null, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function update(int $id, array $data, User $user): Model
    {
        return DB::transaction(function () use ($id, $data, $user) {
            $doc = ($this->modelClass())::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($doc);

            if (isset($data['version']) && (int) $data['version'] !== (int) $doc->version) {
                throw CashBankException::conflict();
            }

            [$header, $lines] = $this->prepare($data, $doc);
            $before = $this->snapshot($doc->load('lines'));

            $doc->forceFill($header + ['version' => (int) $doc->version + 1, 'updated_by' => $user->getKey()])->save();
            ($this->lineClass())::query()->where($this->lineFk(), $doc->getKey())->delete();
            $this->writeLines($doc, $lines);

            $this->audit->log($this->docType(), (int) $doc->getKey(), 'update', $before, $this->snapshot($doc, $lines));

            return $doc->load('lines');
        });
    }

    public function delete(int $id, User $user): void
    {
        DB::transaction(function () use ($id) {
            $doc = ($this->modelClass())::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($doc);

            $this->audit->log($this->docType(), (int) $doc->getKey(), 'delete', $this->snapshot($doc->load('lines')), null);
            ($this->lineClass())::query()->where($this->lineFk(), $doc->getKey())->delete();
            $doc->delete();
        });
    }

    /** @return array{0: array, 1: list<array>} header dan baris tervalidasi */
    protected function prepare(array $data, ?Model $existing): array
    {
        $tanggal = \Carbon\Carbon::parse($data['tanggal'])->toDateString();
        $this->periods->assertPostable($tanggal);

        $contactId = $data['contact_id'] ?? null;
        if ($contactId !== null) {
            $ok = DB::table('contacts')->where('company_id', $this->current->id())->where('id', $contactId)->where('is_aktif', true)->exists();
            if (! $ok) {
                throw new CashBankException('Kontak tidak ditemukan atau nonaktif.', 'contact_invalid');
            }
        }

        $header = [
            'tanggal' => $tanggal,
            'contact_id' => $contactId,
            'memo' => $data['memo'] ?? null,
            'kode_mata_uang' => $this->current->company()->mata_uang_dasar,
            'kurs' => '1',
        ];

        $bank = null;
        if (! empty($data['rekening_id'])) {
            $bank = $this->banks->assertActive((int) $data['rekening_id']);
            $header[$this->bankColumn()] = $bank->getKey();
        } else {
            $header[$this->bankColumn()] = null;
        }

        $lines = $this->prepareLines($data['lines'] ?? [], $tanggal, $bank?->account_id);
        $header['total'] = $this->totalOf($tanggal, $lines);

        $header += $this->extraHeader($data, $existing);
        $this->validateExtra($data, $header);

        if ($this->bankColumn() !== null && $header[$this->bankColumn()] === null && ! $this->bankOptional($data)) {
            throw new CashBankException('Rekening kas/bank wajib diisi.', 'bank_required');
        }

        return [$header, $lines];
    }

    /** Pencatatan Beban yang dibayar lewat utang tidak punya rekening. */
    protected function bankOptional(array $data): bool
    {
        return false;
    }

    protected function prepareLines(array $raw, string $tanggal, ?int $bankAccountId): array
    {
        if ($raw === []) {
            throw new CashBankException('Dokumen harus punya minimal satu baris.', 'no_lines');
        }

        $out = [];
        foreach (array_values($raw) as $i => $l) {
            $n = $i + 1;
            $nominal = Money::round((string) ($l['nominal'] ?? '0'));
            if (Money::cmp($nominal, '0') <= 0) {
                throw new CashBankException("Baris {$n}: nominal harus lebih dari 0.", 'line_amount');
            }

            $account = Account::query()->find((int) ($l['account_id'] ?? 0));
            if (! $account) {
                throw new CashBankException("Baris {$n}: akun tidak ditemukan.", 'account_not_found');
            }
            if (! $account->isPostable()) {
                throw new CashBankException("Baris {$n}: akun {$account->kode} adalah akun induk atau nonaktif.", 'account_not_postable');
            }
            if ($bankAccountId !== null && (int) $account->getKey() === $bankAccountId) {
                throw new CashBankException("Baris {$n}: akun baris tidak boleh sama dengan akun rekening kas/bank dokumen ini.", 'account_same_as_bank');
            }

            $taxId = $l['tax_code_id'] ?? null;
            if ($taxId !== null) {
                $tax = TaxCode::query()->find((int) $taxId);
                if (! $tax) {
                    throw new CashBankException("Baris {$n}: kode pajak tidak ditemukan.", 'tax_not_found');
                }
                if (! in_array($tax->jenis, $this->allowedTaxKinds(), true)) {
                    throw new CashBankException("Baris {$n}: kode pajak {$tax->kode} ({$tax->jenis}) belum didukung untuk dokumen ini.", 'tax_kind_unsupported');
                }
            }

            $out[] = [
                'line_no' => $n,
                'account_id' => (int) $account->getKey(),
                'deskripsi' => $l['deskripsi'] ?? null,
                'nominal' => $nominal,
                'tax_code_id' => $taxId !== null ? (int) $taxId : null,
            ];
        }

        return $out;
    }

    /** Total dokumen: dengan pajak lewat ALG-03 (dibulatkan rupiah), tanpa pajak jumlah nominal. */
    protected function totalOf(string $tanggal, array $lines): string
    {
        if (collect($lines)->whereNotNull('tax_code_id')->isEmpty()) {
            return Money::round(Money::sum(array_column($lines, 'nominal')));
        }

        return $this->taxes->calculate([
            'tanggal' => $tanggal,
            'lines' => array_map(fn ($l) => ['qty' => '1', 'harga' => $l['nominal'], 'tax_code_id' => $l['tax_code_id']], $lines),
        ])['total'];
    }

    protected function writeLines(Model $doc, array $lines): void
    {
        foreach ($lines as $l) {
            ($this->lineClass())::query()->create($l + [
                'company_id' => $doc->company_id,
                $this->lineFk() => $doc->getKey(),
            ]);
        }
    }

    protected function assertDraft(Model $doc): void
    {
        if ($doc->status !== 'draft') {
            throw CashBankException::notDraft((string) $doc->status);
        }
    }

    protected function snapshot(Model $doc, ?array $lines = null): array
    {
        return [
            'tanggal' => $doc->tanggal?->toDateString(),
            'contact_id' => $doc->contact_id,
            'rekening_id' => $doc->{$this->bankColumn()},
            'total' => (string) $doc->total,
            'memo' => $doc->memo,
            'lines' => $lines ?? $doc->lines->map(fn ($l) => $l->only(['line_no', 'account_id', 'nominal', 'tax_code_id']))->all(),
        ];
    }
}
