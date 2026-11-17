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
        Schema::create('integration_configs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('provider', 40)->comment('midtrans, ...');
            $table->enum('mode', ['sandbox', 'live'])->default('sandbox');
            $table->text('kredensial_terenkripsi')->nullable()->comment('Terenkripsi, tidak masuk log');
            $table->enum('status', ['inactive', 'active', 'error'])->default('inactive');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'provider', 'mode'], 'uq_integration');

            $table->foreign(['company_id'], 'fk_ic_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
