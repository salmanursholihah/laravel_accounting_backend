<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 30);
            $table->string('nama', 120);
            $table->enum('jenis', ['ppn', 'ppnbm', 'pph']);
            $table->decimal('tarif', 9, 6)->comment('Tarif, mis. 0.120000 untuk 12%');
            $table->unsignedSmallInteger('dpp_pembilang')->default(1);
            $table->unsignedSmallInteger('dpp_penyebut')->default(1)->comment('11/12 untuk DPP nilai lain');
            $table->unsignedBigInteger('akun_penjualan_id')->nullable();
            $table->unsignedBigInteger('akun_pembelian_id')->nullable();
            $table->date('berlaku_dari');
            $table->date('berlaku_sampai')->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'kode', 'berlaku_dari'], 'uq_tax_version');
            $table->unique(['id', 'company_id'], 'uq_tax_id_company');

            $table->foreign(['company_id'], 'fk_tax_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_penjualan_id', 'company_id'], 'fk_tax_sales_acc')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_pembelian_id', 'company_id'], 'fk_tax_purch_acc')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `tax_codes` ADD CONSTRAINT `chk_tax_dpp` CHECK (dpp_pembilang > 0 AND dpp_penyebut > 0 AND dpp_pembilang <= dpp_penyebut)');
        DB::statement('ALTER TABLE `tax_codes` ADD CONSTRAINT `chk_tax_dates` CHECK (berlaku_sampai IS NULL OR berlaku_sampai >= berlaku_dari)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
