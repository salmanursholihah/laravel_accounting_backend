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
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->date('tanggal');
            $table->decimal('jumlah', 19, 4)->comment('Positif = sesuai saldo normal akun');
            $table->unsignedBigInteger('journal_id')->nullable()->comment('Terisi setelah jurnal saldo awal diposting');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'account_id', 'tanggal'], 'idx_ob_lookup');

            $table->foreign(['account_id', 'company_id'], 'fk_ob_account')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_ob_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_ob_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
