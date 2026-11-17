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
        Schema::create('payment_gateway_transactions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('provider', 40);
            $table->string('order_id', 80);
            $table->unsignedBigInteger('sales_invoice_id');
            $table->decimal('nominal', 19, 4);
            $table->string('metode', 40)->nullable();
            $table->enum('status', ['menunggu', 'dibayar', 'kedaluwarsa', 'batal', 'ditolak'])->default('menunggu');
            $table->json('payload_json')->nullable();
            $table->unsignedBigInteger('sales_receipt_id')->nullable()->comment('Terisi oleh worker setelah webhook (ALG-12)');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['provider', 'order_id'], 'uq_pgt_order');
            $table->index(['company_id', 'sales_invoice_id'], 'idx_pgt_invoice');

            $table->foreign(['company_id'], 'fk_payment_gateway_transactions_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_invoice_id', 'company_id'], 'fk_payment_gateway_transactions_inv')->references(['id', 'company_id'])->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_receipt_id', 'company_id'], 'fk_payment_gateway_transactions_rc')->references(['id', 'company_id'])->on('sales_receipts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_transactions');
    }
};
