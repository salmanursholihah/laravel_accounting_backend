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
        Schema::create('files', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('nama', 255);
            $table->string('path', 500)->comment('Object storage key, memuat company_id');
            $table->string('mime', 120);
            $table->unsignedBigInteger('ukuran_byte');
            $table->char('checksum', 64)->comment('SHA-256');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'checksum'], 'idx_files_company_checksum');

            $table->foreign(['company_id'], 'fk_files_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
