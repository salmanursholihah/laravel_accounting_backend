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
        Schema::create('period_close_logs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id');
            $table->json('hasil_cek_json')->comment('Hasil checklist ALG-11');
            $table->enum('status', ['passed', 'warning', 'failed', 'closed']);
            $table->unsignedBigInteger('dijalankan_oleh');
            $table->dateTime('created_at');

            $table->index(['company_id', 'period_id', 'created_at'], 'idx_pcl_period');

            $table->foreign(['period_id', 'company_id'], 'fk_pcl_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('period_close_logs');
    }
};
