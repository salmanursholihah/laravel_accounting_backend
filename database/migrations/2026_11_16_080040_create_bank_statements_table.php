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
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->decimal('saldo_awal', 19, 4);
            $table->decimal('saldo_akhir', 19, 4);
            $table->enum('sumber', ['impor', 'manual', 'e_banking'])->default('manual');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_bank_statements_id');
            $table->index(['company_id', 'bank_account_id', 'periode_selesai'], 'idx_stmt_bank');

            $table->foreign(['company_id'], 'fk_bank_statements_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['bank_account_id', 'company_id'], 'fk_bank_statements_bank')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['file_id'], 'fk_bank_statements_file')->references(['id'])->on('files')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
