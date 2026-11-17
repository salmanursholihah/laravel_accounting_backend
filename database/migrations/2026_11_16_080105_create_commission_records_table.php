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
        Schema::create('commission_records', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sales_invoice_id');
            $table->unsignedBigInteger('salesperson_id');
            $table->decimal('nominal', 19, 4);
            $table->enum('status', ['dihitung', 'dibayar', 'batal'])->default('dihitung');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'salesperson_id', 'status'], 'idx_cr_sales');

            $table->foreign(['company_id'], 'fk_commission_records_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_invoice_id', 'company_id'], 'fk_commission_records_inv')->references(['id', 'company_id'])->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['salesperson_id', 'company_id'], 'fk_commission_records_sales')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_records');
    }
};
