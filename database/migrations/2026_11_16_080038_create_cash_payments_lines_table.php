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
        Schema::create('cash_payments_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('cash_payment_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('deskripsi', 500)->nullable();
            $table->decimal('nominal', 19, 4)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();

            $table->unique(['cash_payment_id', 'line_no'], 'uq_cash_payments_l_no');

            $table->foreign(['company_id'], 'fk_cash_payments_l_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['cash_payment_id', 'company_id'], 'fk_cash_payments_l_hdr')->references(['id', 'company_id'])->on('cash_payments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['account_id', 'company_id'], 'fk_cash_payments_l_acc')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tax_code_id', 'company_id'], 'fk_cash_payments_l_tax')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_payments_lines');
    }
};
