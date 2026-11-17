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
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedSmallInteger('tahun');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('ditutup_oleh')->nullable();
            $table->dateTime('ditutup_pada')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'tahun'], 'uq_fy_year');
            $table->unique(['id', 'company_id'], 'uq_fy_id_company');

            $table->foreign(['company_id'], 'fk_fy_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `fiscal_years` ADD CONSTRAINT `chk_fy_dates` CHECK (tanggal_selesai > tanggal_mulai)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
