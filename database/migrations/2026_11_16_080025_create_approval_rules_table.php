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
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('doc_type', 40);
            $table->json('kondisi_json')->nullable()->comment('mis. {"amount_gte": 5000000}');
            $table->unsignedBigInteger('approver_role_id');
            $table->boolean('is_aktif')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['company_id', 'doc_type', 'is_aktif'], 'idx_ar_company_type');

            $table->foreign(['company_id'], 'fk_ar_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['approver_role_id'], 'fk_ar_role')->references(['id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};
