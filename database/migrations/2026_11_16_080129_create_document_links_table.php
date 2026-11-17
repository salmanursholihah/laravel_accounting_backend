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
        Schema::create('document_links', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('target_type', 60);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('target_line_id')->nullable();
            $table->decimal('qty', 18, 4)->nullable();
            $table->decimal('nominal', 19, 4)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->index(['company_id', 'source_type', 'source_id'], 'idx_dl_source');
            $table->index(['company_id', 'target_type', 'target_id'], 'idx_dl_target');

            $table->foreign(['company_id'], 'fk_document_links_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_links');
    }
};
