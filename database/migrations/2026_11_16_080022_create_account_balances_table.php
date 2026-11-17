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
        Schema::create('account_balances', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('period_id');
            $table->decimal('debit', 19, 4)->default(0)->comment('Tanpa jurnal penutup');
            $table->decimal('kredit', 19, 4)->default(0);
            $table->decimal('debit_penutup', 19, 4)->default(0)->comment('Khusus jurnal penutup');
            $table->decimal('kredit_penutup', 19, 4)->default(0);
            $table->dateTime('updated_at');
            $table->primary(['company_id', 'account_id', 'period_id']);

            $table->index(['company_id', 'period_id'], 'idx_ab_period');

            $table->foreign(['account_id', 'company_id'], 'fk_ab_account')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['period_id', 'company_id'], 'fk_ab_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
