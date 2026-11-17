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
        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->nullable()->comment('Diisi saat Posting/Open (ALG-01); NULL saat Draft');
            $table->date('tanggal');
            $table->enum('status', ['draft', 'open', 'partial', 'closed', 'canceled'])->default('draft');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->char('kode_mata_uang', 3)->default('IDR');
            $table->decimal('kurs', 19, 6)->default(1);
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('gudang_asal_id')->nullable()->comment('FK ke warehouses di Tahap 9C');
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('no_resi', 80)->nullable();
            $table->enum('status_faktur', ['belum', 'sebagian', 'sudah'])->default('belum');
            $table->string('memo', 500)->nullable();
            $table->string('alasan_batal', 500)->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedInteger('version')->default(1)->comment('Locking optimistik draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['public_id'], 'uq_sales_deliveries_public');
            $table->unique(['company_id', 'nomor'], 'uq_sales_deliveries_nomor');
            $table->unique(['id', 'company_id'], 'uq_sales_deliveries_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_sales_deliveries_status');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_sales_deliveries_contact');

            $table->foreign(['company_id'], 'fk_sales_deliveries_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_sales_deliveries_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_sales_deliveries_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_order_id', 'company_id'], 'fk_sales_deliveries_so')->references(['id', 'company_id'])->on('sales_orders')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['shipping_method_id', 'company_id'], 'fk_sales_deliveries_ship')->references(['id', 'company_id'])->on('shipping_methods')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_deliveries');
    }
};
