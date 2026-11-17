<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_payment_allocations', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('purchase_payment_id');
            $table->unsignedBigInteger('purchase_invoice_id');
            $table->decimal('nominal_dialokasikan', 19, 4);
            $table->unsignedBigInteger('purchase_down_payment_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'purchase_invoice_id'], 'idx_ppa_invoice');
            $table->index(['company_id', 'purchase_payment_id'], 'idx_ppa_payment');

            $table->foreign(['company_id'], 'fk_purchase_payment_allocations_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['purchase_payment_id', 'company_id'], 'fk_ppa_pay')->references(['id', 'company_id'])->on('purchase_payments')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['purchase_invoice_id', 'company_id'], 'fk_ppa_inv')->references(['id', 'company_id'])->on('purchase_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['purchase_down_payment_id', 'company_id'], 'fk_ppa_dp')->references(['id', 'company_id'])->on('purchase_down_payments')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `purchase_payment_allocations` ADD CONSTRAINT `chk_ppa_nominal` CHECK (nominal_dialokasikan > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_allocations');
    }
};
