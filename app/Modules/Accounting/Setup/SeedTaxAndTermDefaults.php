<?php

namespace App\Modules\Accounting\Setup;

use App\Modules\Accounting\ChartOfAccounts\SystemAccountService;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\PaymentTerms\PaymentTermService;
use App\Modules\Accounting\Tax\TaxCode;
use App\Modules\Accounting\Tax\TaxCodeService;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Tenancy\CurrentCompany;

/**
 * Menyalin config/tax_defaults.php ke perusahaan: kode pajak berversi dan syarat pembayaran umum.
 * Idempotent: kode pajak dan syarat yang sudah ada dilewati, jadi aman dijalankan ulang untuk perusahaan lama.
 * Lewat TaxCodeService/PaymentTermService supaya semua aturan (versi tidak tumpang tindih, non-PKP = PPN nonaktif,
 * akun harus postable, audit) berlaku sama seperti input manual.
 */
class SeedTaxAndTermDefaults
{
    public function __construct(
        private CurrentCompany $current,
        private SystemAccountService $accounts,
        private TaxCodeService $taxes,
        private PaymentTermService $terms,
    ) {
    }

    /** @return array{tax_versions:int, payment_terms:int} jumlah baris baru */
    public function handle(Company $company): array
    {
        return $this->current->runAs($company, function () {
            return [
                'tax_versions' => $this->seedTaxCodes(),
                'payment_terms' => $this->seedPaymentTerms(),
            ];
        });
    }

    private function seedTaxCodes(): int
    {
        $created = 0;

        foreach (config('tax_defaults.tax_codes', []) as $def) {
            $jual = isset($def['akun_penjualan']) ? $this->accounts->resolve($def['akun_penjualan'])->getKey() : null;
            $beli = isset($def['akun_pembelian']) ? $this->accounts->resolve($def['akun_pembelian'])->getKey() : null;

            foreach ($def['versi'] as $v) {
                $exists = TaxCode::query()->where('kode', $def['kode'])->where('berlaku_dari', $v['berlaku_dari'])->exists();
                if ($exists) {
                    continue;
                }

                $this->taxes->create([
                    'kode' => $def['kode'],
                    'nama' => $def['nama'],
                    'jenis' => $def['jenis'],
                    'tarif' => $v['tarif'],
                    'dpp_pembilang' => $v['dpp'][0],
                    'dpp_penyebut' => $v['dpp'][1],
                    'akun_penjualan_id' => $jual,
                    'akun_pembelian_id' => $beli,
                    'berlaku_dari' => $v['berlaku_dari'],
                    'berlaku_sampai' => $v['berlaku_sampai'] ?? null,
                ]);
                $created++;
            }
        }

        return $created;
    }

    private function seedPaymentTerms(): int
    {
        $created = 0;

        foreach (config('tax_defaults.payment_terms', []) as $def) {
            if (PaymentTerm::query()->where('nama', $def['nama'])->exists()) {
                continue;
            }
            $this->terms->create($def);
            $created++;
        }

        return $created;
    }
}
