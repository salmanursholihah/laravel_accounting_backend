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
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_reconciliation_id');
            $table->unsignedBigInteger('bank_statement_line_id')->nullable();
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->enum('tipe_cocok', ['otomatis', 'manual', 'transaksi_baru']);
            $table->decimal('skor', 5, 4)->nullable()->comment('Skor ALG-09');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'bank_statement_line_id'], 'uq_ri_stmt');
            $table->unique(['company_id', 'journal_line_id'], 'uq_ri_jl');

            $table->foreign(['company_id'], 'fk_reconciliation_items_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['bank_reconciliation_id', 'company_id'], 'fk_reconciliation_items_rec')->references(['id', 'company_id'])->on('bank_reconciliations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['bank_statement_line_id', 'company_id'], 'fk_reconciliation_items_stmt')->references(['id', 'company_id'])->on('bank_statement_lines')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_line_id', 'company_id'], 'fk_reconciliation_items_jl')->references(['id', 'company_id'])->on('journal_lines')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
    }
};
