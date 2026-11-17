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
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('nama', 80)->comment('mis. Tunai, Net 30');
            $table->unsignedSmallInteger('jumlah_hari')->default(0);
            $table->decimal('diskon_persen', 7, 4)->default(0)->comment('Diskon pelunasan cepat');
            $table->unsignedSmallInteger('diskon_hari')->default(0);
            $table->boolean('is_aktif')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'nama'], 'uq_terms_name');
            $table->unique(['id', 'company_id'], 'uq_terms_id_company');

            $table->foreign(['company_id'], 'fk_terms_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
