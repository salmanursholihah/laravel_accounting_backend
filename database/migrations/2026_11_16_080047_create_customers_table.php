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
        Schema::create('customers', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id')->comment('Ekstensi 1:1 dari contacts (kode dan nama ada di contacts)');
            $table->unsignedBigInteger('customer_category_id')->nullable();
            $table->decimal('limit_kredit', 19, 4)->default(0);
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedTinyInteger('level_harga')->default(1);
            $table->unsignedBigInteger('salesperson_id')->nullable()->comment('contacts bertanda karyawan');
            $table->unsignedBigInteger('akun_piutang_override_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_customers_id');
            $table->unique(['company_id', 'contact_id'], 'uq_customers_contact');

            $table->foreign(['company_id'], 'fk_customers_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_customers_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['customer_category_id', 'company_id'], 'fk_customers_cat')->references(['id', 'company_id'])->on('customer_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['payment_term_id', 'company_id'], 'fk_customers_term')->references(['id', 'company_id'])->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['salesperson_id', 'company_id'], 'fk_customers_sales')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_piutang_override_id', 'company_id'], 'fk_customers_akun')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
