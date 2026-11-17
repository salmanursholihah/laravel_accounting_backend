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
        Schema::create('purchase_orders_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('item_id')->nullable()->comment('FK ke items ditambahkan di Tahap 9C');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('deskripsi', 500)->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable()->comment('FK ke units di Tahap 9C');
            $table->decimal('harga', 19, 4)->default(0);
            $table->decimal('diskon_persen', 7, 4)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('jumlah', 19, 4)->default(0);
            $table->unsignedBigInteger('warehouse_id')->nullable()->comment('FK ke warehouses di Tahap 9C');
            $table->decimal('qty_terpenuhi', 18, 4)->default(0);
            $table->decimal('qty_sisa', 18, 4)->default(0);
            $table->decimal('qty_diterima', 18, 4)->default(0);
            $table->decimal('qty_ditagih', 18, 4)->default(0);

            $table->unique(['purchase_order_id', 'line_no'], 'uq_purchase_orders_l_no');
            $table->index(['company_id', 'item_id'], 'idx_purchase_orders_l_item');

            $table->foreign(['company_id'], 'fk_purchase_orders_l_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['purchase_order_id', 'company_id'], 'fk_purchase_orders_l_hdr')->references(['id', 'company_id'])->on('purchase_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['account_id', 'company_id'], 'fk_purchase_orders_l_acc')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tax_code_id', 'company_id'], 'fk_purchase_orders_l_tax')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders_lines');
    }
};
