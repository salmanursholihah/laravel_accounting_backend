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
        Schema::create('document_taxes', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('doc_type', 60);
            $table->unsignedBigInteger('doc_id');
            $table->unsignedBigInteger('line_id')->nullable();
            $table->unsignedBigInteger('tax_code_id');
            $table->decimal('dpp', 19, 4);
            $table->decimal('tarif', 9, 6);
            $table->decimal('nominal', 19, 4);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'doc_type', 'doc_id'], 'idx_dt_doc');

            $table->foreign(['company_id'], 'fk_document_taxes_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tax_code_id', 'company_id'], 'fk_document_taxes_tax')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_taxes');
    }
};
