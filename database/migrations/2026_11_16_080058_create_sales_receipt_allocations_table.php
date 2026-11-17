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
        Schema::create('sales_receipt_allocations', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sales_receipt_id');
            $table->unsignedBigInteger('sales_invoice_id');
            $table->decimal('nominal_dialokasikan', 19, 4);
            $table->unsignedBigInteger('sales_down_payment_id')->nullable()->comment('Terisi bila alokasi memotong uang muka');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'sales_invoice_id'], 'idx_sra_invoice');
            $table->index(['company_id', 'sales_receipt_id'], 'idx_sra_receipt');

            $table->foreign(['company_id'], 'fk_sales_receipt_allocations_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_receipt_id', 'company_id'], 'fk_sra_rc')->references(['id', 'company_id'])->on('sales_receipts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_invoice_id', 'company_id'], 'fk_sra_inv')->references(['id', 'company_id'])->on('sales_invoices')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_down_payment_id', 'company_id'], 'fk_sra_dp')->references(['id', 'company_id'])->on('sales_down_payments')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `sales_receipt_allocations` ADD CONSTRAINT `chk_sra_nominal` CHECK (nominal_dialokasikan > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_receipt_allocations');
    }
};
