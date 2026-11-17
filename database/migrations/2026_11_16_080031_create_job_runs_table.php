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
        Schema::create('job_runs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('job_type', 80)->comment('recalc_cost, recurring, depreciation, ...');
            $table->string('scope_key', 120)->nullable()->comment('mis. item_id:warehouse_id');
            $table->enum('status', ['running', 'success', 'failed', 'skipped']);
            $table->json('detail_json')->nullable();
            $table->dateTime('mulai_pada');
            $table->dateTime('selesai_pada')->nullable();

            $table->index(['company_id', 'job_type', 'mulai_pada'], 'idx_jobrun_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
