<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('doc_type', 40);
            $table->unsignedBigInteger('doc_id');
            $table->unsignedBigInteger('pengaju');
            $table->unsignedBigInteger('approver_role_id');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->string('alasan_tolak', 500)->nullable();
            $table->dateTime('diajukan_pada');
            $table->dateTime('diputuskan_pada')->nullable();

            $table->index(['company_id', 'status', 'approver_role_id'], 'idx_areq_inbox');
            $table->index(['company_id', 'doc_type', 'doc_id'], 'idx_areq_doc');

            $table->foreign(['company_id'], 'fk_areq_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['rule_id'], 'fk_areq_rule')->references(['id'])->on('approval_rules')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `approval_requests` ADD CONSTRAINT `chk_areq_self` CHECK (approver_id IS NULL OR approver_id <> pengaju)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
