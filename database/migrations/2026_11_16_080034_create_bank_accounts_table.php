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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('account_id')->comment('1:1 dengan akun kas/bank di Akun Perkiraan');
            $table->string('nama_bank', 100)->nullable();
            $table->string('no_rekening', 50)->nullable();
            $table->string('atas_nama', 150)->nullable();
            $table->boolean('is_kas')->default(false);
            $table->boolean('is_aktif')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_bank_accounts_id');
            $table->unique(['company_id', 'account_id'], 'uq_bank_account');

            $table->foreign(['company_id'], 'fk_bank_accounts_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['account_id', 'company_id'], 'fk_bank_accounts_akun')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
