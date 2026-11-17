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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('provider', 40);
            $table->string('external_id', 120)->comment('mis. transaction_id Midtrans');
            $table->string('status_event', 40);
            $table->json('payload');
            $table->boolean('signature_valid');
            $table->enum('status_proses', ['received', 'done', 'failed'])->default('received');
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->dateTime('diterima_pada');
            $table->dateTime('diproses_pada')->nullable();

            $table->unique(['provider', 'external_id', 'status_event'], 'uq_webhook_idem');
            $table->index(['status_proses', 'diterima_pada'], 'idx_webhook_pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
