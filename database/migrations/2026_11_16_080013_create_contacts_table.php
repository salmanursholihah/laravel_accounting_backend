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
        Schema::create('contacts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 30);
            $table->string('nama', 190);
            $table->text('alamat')->nullable();
            $table->string('kota', 100)->nullable();
            $table->string('telepon', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('npwp', 25)->nullable();
            $table->boolean('is_pelanggan')->default(false);
            $table->boolean('is_pemasok')->default(false);
            $table->boolean('is_karyawan')->default(false);
            $table->boolean('is_aktif')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'kode'], 'uq_contacts_code');
            $table->unique(['id', 'company_id'], 'uq_contacts_id_company');
            $table->index(['company_id', 'nama'], 'idx_contacts_name');

            $table->foreign(['company_id'], 'fk_contacts_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
