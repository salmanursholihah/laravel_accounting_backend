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
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->unsignedBigInteger('salesperson_id')->nullable();
            $table->string('nama_tim', 100)->nullable();
            $table->decimal('nilai_target', 19, 4);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->foreign(['company_id'], 'fk_sales_targets_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['salesperson_id', 'company_id'], 'fk_sales_targets_sales')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
