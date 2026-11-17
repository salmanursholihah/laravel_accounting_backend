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
        Schema::create('roles', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->comment('NULL = role bawaan sistem');
            $table->string('nama', 80);
            $table->boolean('is_sistem')->default(false)->comment('1 = tidak bisa dihapus/diubah');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['company_id', 'nama'], 'idx_roles_company_name');

            $table->foreign(['company_id'], 'fk_roles_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
