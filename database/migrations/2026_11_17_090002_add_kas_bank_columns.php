<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran Kas & Bank: PPN Masukan hanya dikreditkan bila faktur pajak valid (Tahap 4 bagian 4), jadi header
 * menyimpan dapat_dikreditkan dan no_faktur_pajak. Baris penerimaan/pembayaran wajib bernominal positif dan berakun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->boolean('dapat_dikreditkan')->default(true)->after('total')->comment('PPN Masukan dikreditkan (faktur pajak valid)');
            $table->string('no_faktur_pajak', 50)->nullable()->after('dapat_dikreditkan');
        });

        DB::statement('ALTER TABLE `cash_receipts_lines` ADD CONSTRAINT `chk_cash_receipts_l_nominal` CHECK (nominal > 0)');
        DB::statement('ALTER TABLE `cash_payments_lines` ADD CONSTRAINT `chk_cash_payments_l_nominal` CHECK (nominal > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `cash_payments_lines` DROP CHECK `chk_cash_payments_l_nominal`');
        DB::statement('ALTER TABLE `cash_receipts_lines` DROP CHECK `chk_cash_receipts_l_nominal`');

        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropColumn(['dapat_dikreditkan', 'no_faktur_pajak']);
        });
    }
};
