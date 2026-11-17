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
        Schema::create('accounts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 20)->comment('Kode akun, mis. 1-1200');
            $table->string('nama', 190);
            $table->enum('tipe', ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense', 'other_income', 'other_expense']);
            $table->string('sub_tipe', 40)->nullable()->comment('mis. cash_bank, receivable, payable, inventory, fixed_asset');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->enum('saldo_normal', ['debit', 'credit']);
            $table->boolean('is_header')->default(false)->comment('Akun induk, tidak bisa dipakai transaksi');
            $table->boolean('is_aktif')->default(true);
            $table->char('kode_mata_uang', 3)->default('IDR');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['company_id', 'kode'], 'uq_accounts_code');
            $table->unique(['id', 'company_id'], 'uq_accounts_id_company');
            $table->index(['parent_id', 'company_id'], 'idx_accounts_parent');
            $table->index(['company_id', 'tipe', 'is_aktif'], 'idx_accounts_type');

            $table->foreign(['company_id'], 'fk_accounts_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['parent_id', 'company_id'], 'fk_accounts_parent')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
