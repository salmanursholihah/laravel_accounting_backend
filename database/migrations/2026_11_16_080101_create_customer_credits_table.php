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
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('sales_return_id')->nullable();
            $table->decimal('nominal', 19, 4);
            $table->decimal('sisa_kredit', 19, 4);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'contact_id'], 'idx_credit_contact');

            $table->foreign(['company_id'], 'fk_customer_credits_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_customer_credits_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['sales_return_id', 'company_id'], 'fk_customer_credits_ret')->references(['id', 'company_id'])->on('sales_returns')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `customer_credits` ADD CONSTRAINT `chk_cc_sisa` CHECK (sisa_kredit >= 0 AND sisa_kredit <= nominal)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
