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
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('doc_type', 40);
            $table->string('period_key', 10)->default('-')->comment('"2026", "2026-11", atau "-"');
            $table->string('prefix', 20);
            $table->string('format', 100)->default('{PREFIX}/{YYYY}/{MM}/{SEQ:5}');
            $table->enum('reset', ['none', 'yearly', 'monthly'])->default('monthly');
            $table->unsignedBigInteger('nomor_berikut')->default(1);
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'doc_type', 'period_key'], 'uq_sequence');

            $table->foreign(['company_id'], 'fk_seq_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
