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
        Schema::create('purchase_orders', function (Blueprint $table) {
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
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('diskon', 19, 4)->default(0);
            $table->decimal('dpp', 19, 4)->default(0);
            $table->decimal('total_pajak', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->date('tanggal_diharapkan')->nullable();
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedBigInteger('item_request_id')->nullable()->comment('FK ke item_requests di Tahap 9C');
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

            $table->unique(['public_id'], 'uq_purchase_orders_public');
            $table->unique(['company_id', 'nomor'], 'uq_purchase_orders_nomor');
            $table->unique(['id', 'company_id'], 'uq_purchase_orders_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_purchase_orders_status');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_purchase_orders_contact');

            $table->foreign(['company_id'], 'fk_purchase_orders_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_purchase_orders_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_purchase_orders_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['payment_term_id', 'company_id'], 'fk_purchase_orders_term')->references(['id', 'company_id'])->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
