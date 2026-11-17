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
        Schema::create('ecommerce_orders', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('ecommerce_connection_id');
            $table->string('platform_order_id', 120);
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'ecommerce_connection_id', 'platform_order_id'], 'uq_ecom_order');

            $table->foreign(['company_id'], 'fk_ecommerce_orders_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['ecommerce_connection_id'], 'fk_ecommerce_orders_conn')->references(['id'])->on('ecommerce_connections')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_order_id', 'company_id'], 'fk_ecommerce_orders_so')->references(['id', 'company_id'])->on('sales_orders')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_orders');
    }
};
