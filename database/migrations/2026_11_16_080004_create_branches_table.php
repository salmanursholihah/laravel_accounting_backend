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
        Schema::create('branches', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 20);
            $table->string('nama', 150);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_aktif')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'kode'], 'uq_branches_code');
            $table->unique(['id', 'company_id'], 'uq_branches_id_company');

            $table->foreign(['company_id'], 'fk_branches_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
