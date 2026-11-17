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
        Schema::create('price_adjustment_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('price_adjustment_id');
            $table->unsignedBigInteger('item_id')->comment('FK ke items di Tahap 9C');
            $table->unsignedBigInteger('customer_category_id')->nullable();
            $table->decimal('harga', 19, 4)->nullable();
            $table->decimal('diskon_persen', 7, 4)->default(0);
            $table->decimal('qty_minimum', 18, 4)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'item_id'], 'idx_pal_item');

            $table->foreign(['company_id'], 'fk_price_adjustment_lines_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['price_adjustment_id', 'company_id'], 'fk_price_adjustment_lines_hdr')->references(['id', 'company_id'])->on('price_adjustments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['customer_category_id', 'company_id'], 'fk_price_adjustment_lines_cat')->references(['id', 'company_id'])->on('customer_categories')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_adjustment_lines');
    }
};
