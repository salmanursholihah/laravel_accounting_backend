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
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->unsignedTinyInteger('bulan')->comment('1-12 urutan dalam tahun buku');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('ditutup_oleh')->nullable();
            $table->dateTime('ditutup_pada')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'fiscal_year_id', 'bulan'], 'uq_period_month');
            $table->unique(['id', 'company_id'], 'uq_period_id_company');
            $table->index(['company_id', 'tanggal_mulai', 'tanggal_selesai'], 'idx_period_dates');

            $table->foreign(['fiscal_year_id', 'company_id'], 'fk_period_fy')->references(['id', 'company_id'])->on('fiscal_years')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `accounting_periods` ADD CONSTRAINT `chk_period_month` CHECK (bulan BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE `accounting_periods` ADD CONSTRAINT `chk_period_dates` CHECK (tanggal_selesai >= tanggal_mulai)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
