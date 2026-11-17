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
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('event_type', 80)->comment('mis. InvoicePosted');
            $table->json('payload');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('dikirim_pada')->nullable();

            $table->index(['status', 'id'], 'idx_outbox_pending');
            $table->index(['company_id', 'event_type'], 'idx_outbox_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
