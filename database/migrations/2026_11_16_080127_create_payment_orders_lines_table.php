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
        Schema::create('payment_orders_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('payment_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('purchase_invoice_id');
            $table->decimal('nominal', 19, 4);

            $table->unique(['payment_order_id', 'line_no'], 'uq_payment_orders_l_no');

            $table->foreign(['company_id'], 'fk_payment_orders_l_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['payment_order_id', 'company_id'], 'fk_payment_orders_l_hdr')->references(['id', 'company_id'])->on('payment_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['purchase_invoice_id', 'company_id'], 'fk_payment_orders_l_inv')->references(['id', 'company_id'])->on('purchase_invoices')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_orders_lines');
    }
};
