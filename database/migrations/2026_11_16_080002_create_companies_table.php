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
        Schema::create('companies', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->char('public_id', 26);
            $table->string('nama', 190)->comment('Nama perusahaan');
            $table->string('npwp', 25)->nullable();
            $table->boolean('is_pkp')->default(false)->comment('Mode pajak PKP / non-PKP');
            $table->text('alamat')->nullable();
            $table->char('mata_uang_dasar', 3)->default('IDR');
            $table->unsignedTinyInteger('bulan_mulai_tahun_buku')->default(1);
            $table->date('tanggal_mulai_pembukuan')->comment('Tanggal mulai pembukuan');
            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['public_id'], 'uq_companies_public');
            $table->index(['status'], 'idx_companies_status');
        });

        DB::statement('ALTER TABLE `companies` ADD CONSTRAINT `chk_companies_fy_month` CHECK (bulan_mulai_tahun_buku BETWEEN 1 AND 12)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
