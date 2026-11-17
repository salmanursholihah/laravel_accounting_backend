<?php

namespace App\Modules\Accounting\Tax;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UC-10 (Pajak). Aturan:
 * - tarif berversi per tanggal berlaku; tarif berubah = versi baru, versi lama ditutup sehari sebelumnya;
 * - versi yang sudah dipakai dokumen tidak boleh diubah tarif/DPP/tanggal mulainya;
 * - perusahaan non-PKP: semua kode PPN nonaktif dan tidak bisa diaktifkan.
 */
class TaxCodeService
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    public function create(array $data, ?User $user = null): TaxCode
    {
        $this->assertRate($data);
        $this->assertAccounts($data);

        $dari = Carbon::parse($data['berlaku_dari'])->startOfDay();
        $sampai = isset($data['berlaku_sampai']) ? Carbon::parse($data['berlaku_sampai'])->startOfDay() : null;
        if ($sampai && $sampai->lt($dari)) {
            throw new TaxException('Tanggal berlaku sampai tidak boleh lebih awal dari berlaku dari.');
        }

        return DB::transaction(function () use ($data, $dari, $sampai) {
            // kunci versi-versi kode ini supaya dua permintaan paralel tidak membuat versi tumpang tindih
            $versions = TaxCode::query()->where('kode', $data['kode'])->orderBy('berlaku_dari')->lockForUpdate()->get();

            if ($versions->isNotEmpty() && $versions->first()->jenis !== $data['jenis']) {
                throw new TaxException("Kode {$data['kode']} sudah dipakai untuk jenis pajak {$versions->first()->jenis}.");
            }

            foreach ($versions as $v) {
                if ($v->berlaku_dari->equalTo($dari)) {
                    throw new TaxException("Kode {$data['kode']} sudah punya versi yang berlaku dari {$dari->toDateString()}.");
                }
            }

            // Versi terbuka (tanpa tanggal akhir) yang dimulai lebih awal ditutup otomatis sehari sebelum versi baru.
            $toClose = $versions->first(fn (TaxCode $v) => $v->berlaku_sampai === null && $v->berlaku_dari->lt($dari));
            foreach ($versions as $v) {
                if ($v === $toClose) {
                    continue;
                }
                if ($this->overlaps($dari, $sampai, $v)) {
                    throw new TaxException("Periode berlaku bertabrakan dengan versi {$data['kode']} yang berlaku dari {$v->berlaku_dari->toDateString()}.");
                }
            }
            if ($toClose) {
                // versi baru yang punya tanggal akhir tidak boleh menyisakan lubang/tumpang tindih dengan versi sesudahnya (sudah dicek di atas)
                $before = ['berlaku_sampai' => $toClose->berlaku_sampai?->toDateString()];
                $toClose->update(['berlaku_sampai' => $dari->copy()->subDay()->toDateString()]);
                $this->audit->log('tax_code', (int) $toClose->getKey(), 'close_version', $before, ['berlaku_sampai' => $toClose->berlaku_sampai->toDateString()]);
            }

            $company = $this->current->company();
            $aktif = (bool) ($data['is_aktif'] ?? true);
            if ($data['jenis'] === 'ppn' && ! $company->is_pkp) {
                $aktif = false; // non-PKP: PPN selalu nonaktif
            }

            $tax = TaxCode::create([
                'kode' => $data['kode'],
                'nama' => $data['nama'],
                'jenis' => $data['jenis'],
                'tarif' => $data['tarif'],
                'dpp_pembilang' => $data['dpp_pembilang'] ?? 1,
                'dpp_penyebut' => $data['dpp_penyebut'] ?? 1,
                'akun_penjualan_id' => $data['akun_penjualan_id'] ?? null,
                'akun_pembelian_id' => $data['akun_pembelian_id'] ?? null,
                'berlaku_dari' => $dari->toDateString(),
                'berlaku_sampai' => $sampai?->toDateString(),
                'is_aktif' => $aktif,
            ]);

            $this->audit->log('tax_code', (int) $tax->getKey(), 'create', null, $this->snapshot($tax));

            return $tax;
        });
    }

    public function update(TaxCode $tax, array $data, ?User $user = null): TaxCode
    {
        $this->assertAccounts($data);
        $tax->fill(array_intersect_key($data, array_flip([
            'nama', 'tarif', 'dpp_pembilang', 'dpp_penyebut', 'akun_penjualan_id', 'akun_pembelian_id',
            'berlaku_dari', 'berlaku_sampai', 'is_aktif',
        ])));
        $dirty = $tax->getDirty();

        if (! $dirty) {
            return $tax;
        }

        $rateKeys = array_intersect(array_keys($dirty), ['tarif', 'dpp_pembilang', 'dpp_penyebut', 'berlaku_dari']);
        if ($rateKeys && $this->isUsed($tax)) {
            throw new TaxException('Versi pajak ini sudah dipakai dokumen; tarif, DPP, dan tanggal mulainya tidak bisa diubah. Buat versi baru dengan tanggal berlaku baru.');
        }

        $this->assertRate($tax->only(['tarif', 'dpp_pembilang', 'dpp_penyebut']));

        if (($dirty['is_aktif'] ?? false) && $tax->jenis === 'ppn' && ! $this->current->company()->is_pkp) {
            throw new TaxException('Perusahaan non-PKP tidak bisa mengaktifkan kode PPN.');
        }

        return DB::transaction(function () use ($tax, $dirty) {
            $others = TaxCode::query()->where('kode', $tax->kode)->where('id', '!=', $tax->getKey())->lockForUpdate()->get();
            $dari = Carbon::parse($tax->berlaku_dari)->startOfDay();
            $sampai = $tax->berlaku_sampai ? Carbon::parse($tax->berlaku_sampai)->startOfDay() : null;
            if ($sampai && $sampai->lt($dari)) {
                throw new TaxException('Tanggal berlaku sampai tidak boleh lebih awal dari berlaku dari.');
            }
            foreach ($others as $o) {
                if ($this->overlaps($dari, $sampai, $o)) {
                    throw new TaxException("Periode berlaku bertabrakan dengan versi yang berlaku dari {$o->berlaku_dari->toDateString()}.");
                }
            }

            $before = array_intersect_key($tax->getOriginal(), $dirty);
            $tax->save();
            $this->audit->log('tax_code', (int) $tax->getKey(), 'update', $before, array_intersect_key($tax->fresh()->getAttributes(), $dirty));

            return $tax->fresh();
        });
    }

    public function delete(TaxCode $tax): void
    {
        if ($this->isUsed($tax)) {
            throw new TaxException('Kode pajak sudah dipakai dokumen dan tidak bisa dihapus; nonaktifkan saja.');
        }

        DB::transaction(function () use ($tax) {
            try {
                $tax->delete();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23000') { // FK dari tabel lain (barang, baris dokumen, dst.)
                    throw new TaxException('Kode pajak masih dirujuk data lain dan tidak bisa dihapus; nonaktifkan saja.');
                }
                throw $e;
            }
            $this->audit->log('tax_code', (int) $tax->getKey(), 'delete', $this->snapshot($tax), null);
        });
    }

    /**
     * Versi tarif yang berlaku pada $tanggal untuk KODE yang sama dengan baris $taxCodeId (ALG-03: taxCodeAt).
     * Baris dokumen menyimpan id satu versi; perhitungan memakai versi yang sesuai tanggal dokumen.
     */
    public function resolveAt(int $taxCodeId, string $tanggal): TaxCode
    {
        $ref = TaxCode::query()->findOrFail($taxCodeId);

        return $this->resolveByKode($ref->kode, $tanggal);
    }

    public function resolveByKode(string $kode, string $tanggal): TaxCode
    {
        $t = Carbon::parse($tanggal)->toDateString();

        $tax = TaxCode::query()
            ->where('kode', $kode)
            ->where('berlaku_dari', '<=', $t)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $t))
            ->first();

        if (! $tax) {
            throw new TaxException("Kode pajak {$kode} tidak punya tarif yang berlaku pada {$t}.");
        }

        return $tax;
    }

    /** Non-PKP: nonaktifkan semua kode PPN (dipanggil listener TaxModeChanged). Kembali ke PKP tidak mengaktifkan ulang otomatis. */
    public function deactivatePpn(): int
    {
        $ids = TaxCode::query()->where('jenis', 'ppn')->where('is_aktif', true)->pluck('id')->all();
        if (! $ids) {
            return 0;
        }

        TaxCode::query()->whereIn('id', $ids)->update(['is_aktif' => false, 'updated_at' => now()]);
        $this->audit->log('tax_code', null, 'deactivate_ppn_non_pkp', ['ids' => $ids], ['is_aktif' => false]);

        return count($ids);
    }

    public function isUsed(TaxCode $tax): bool
    {
        return Schema::hasTable('document_taxes')
            && DB::table('document_taxes')->where('company_id', $tax->company_id)->where('tax_code_id', $tax->getKey())->exists();
    }

    private function overlaps(Carbon $dari, ?Carbon $sampai, TaxCode $v): bool
    {
        $vSampai = $v->berlaku_sampai;

        return ($vSampai === null || $dari->lte($vSampai)) && ($sampai === null || $v->berlaku_dari->lte($sampai));
    }

    private function assertRate(array $data): void
    {
        $tarif = (string) ($data['tarif'] ?? '0');
        if (! is_numeric($tarif) || bccomp($tarif, '0', 6) < 0 || bccomp($tarif, '1', 6) > 0) {
            throw new TaxException('Tarif harus berupa pecahan antara 0 dan 1 (mis. 0.12 untuk 12%).');
        }
        $pem = (int) ($data['dpp_pembilang'] ?? 1);
        $pen = (int) ($data['dpp_penyebut'] ?? 1);
        if ($pem <= 0 || $pen <= 0 || $pem > $pen) {
            throw new TaxException('Pecahan DPP harus positif dan pembilang tidak boleh lebih besar dari penyebut.');
        }
    }

    private function assertAccounts(array $data): void
    {
        foreach (['akun_penjualan_id', 'akun_pembelian_id'] as $key) {
            if (empty($data[$key])) {
                continue;
            }
            $acc = Account::query()->find($data[$key]); // global scope: akun perusahaan lain = tidak ditemukan
            if (! $acc || ! $acc->isPostable()) {
                throw new TaxException('Akun pajak harus akun aktif milik perusahaan ini dan bukan akun induk.');
            }
        }
    }

    private function snapshot(TaxCode $t): array
    {
        return [
            'kode' => $t->kode, 'jenis' => $t->jenis, 'tarif' => (string) $t->tarif,
            'dpp' => "{$t->dpp_pembilang}/{$t->dpp_penyebut}",
            'berlaku_dari' => $t->berlaku_dari?->toDateString(), 'berlaku_sampai' => $t->berlaku_sampai?->toDateString(),
            'is_aktif' => $t->is_aktif,
        ];
    }
}
