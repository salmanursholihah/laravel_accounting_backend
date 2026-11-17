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
        Schema::create('ecommerce_connections', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('platform', 40);
            $table->text('token_terenkripsi')->nullable();
            $table->string('toko_id', 80)->nullable();
            $table->enum('status', ['nonaktif', 'aktif', 'galat'])->default('nonaktif');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'platform', 'toko_id'], 'uq_ecom');

            $table->foreign(['company_id'], 'fk_ecommerce_connections_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ecommerce_connections');
    }
};
