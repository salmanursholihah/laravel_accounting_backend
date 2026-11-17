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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id')->comment('Ekstensi 1:1 dari contacts');
            $table->unsignedBigInteger('supplier_category_id')->nullable();
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedBigInteger('akun_utang_override_id')->nullable();
            $table->string('no_rekening', 60)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_suppliers_id');
            $table->unique(['company_id', 'contact_id'], 'uq_suppliers_contact');

            $table->foreign(['company_id'], 'fk_suppliers_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_suppliers_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['supplier_category_id', 'company_id'], 'fk_suppliers_cat')->references(['id', 'company_id'])->on('supplier_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['payment_term_id', 'company_id'], 'fk_suppliers_term')->references(['id', 'company_id'])->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_utang_override_id', 'company_id'], 'fk_suppliers_akun')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
