<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->unsignedBigInteger('company_id');
            $table->boolean('izinkan_stok_minus')->default(false);
            $table->enum('metode_persediaan', ['average', 'fifo'])->default('average')->comment('Tidak boleh diganti setelah ada transaksi stok');
            $table->decimal('toleransi_kirim_persen', 7, 4)->default(0);
            $table->enum('aturan_awal_penyusutan', ['acquisition_month', 'next_month'])->default('acquisition_month');
            $table->enum('tanggal_void_periode_terkunci', ['today', 'next_open_period'])->default('today');
            $table->enum('kebijakan_draft_akhir_bulan', ['warn', 'block'])->default('warn');
            $table->enum('mode_pajak', ['pkp', 'non_pkp'])->default('non_pkp');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');
            $table->primary(['company_id']);

            $table->foreign(['company_id'], 'fk_settings_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
