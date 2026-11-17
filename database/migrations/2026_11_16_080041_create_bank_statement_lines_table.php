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
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_statement_id');
            $table->unsignedSmallInteger('line_no');
            $table->date('tanggal');
            $table->string('deskripsi', 500)->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('kredit', 19, 4)->default(0);
            $table->decimal('saldo', 19, 4)->nullable();
            $table->enum('status_cocok', ['belum', 'otomatis', 'manual', 'dikecualikan'])->default('belum');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_bank_statement_lines_id');
            $table->unique(['bank_statement_id', 'line_no'], 'uq_stmtline_no');
            $table->index(['company_id', 'status_cocok', 'tanggal'], 'idx_stmtline_match');

            $table->foreign(['company_id'], 'fk_bank_statement_lines_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['bank_statement_id', 'company_id'], 'fk_bank_statement_lines_hdr')->references(['id', 'company_id'])->on('bank_statements')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
