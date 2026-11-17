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
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->decimal('saldo_koran', 19, 4);
            $table->decimal('saldo_buku', 19, 4);
            $table->decimal('selisih', 19, 4);
            $table->enum('status', ['berjalan', 'selesai', 'dibuka_kembali'])->default('berjalan');
            $table->unsignedBigInteger('diselesaikan_oleh')->nullable();
            $table->dateTime('diselesaikan_pada')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_bank_reconciliations_id');
            $table->unique(['company_id', 'bank_account_id', 'periode_selesai'], 'uq_recon_period');

            $table->foreign(['company_id'], 'fk_bank_reconciliations_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['bank_account_id', 'company_id'], 'fk_bank_reconciliations_bank')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
