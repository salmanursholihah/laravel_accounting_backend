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
        Schema::create('system_accounts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->unsignedBigInteger('company_id');
            $table->string('kunci_akun', 60)->comment('mis. piutang_usaha, hpp, ppn_keluaran');
            $table->unsignedBigInteger('account_id');
            $table->dateTime('updated_at');
            $table->primary(['company_id', 'kunci_akun']);

            $table->index(['account_id', 'company_id'], 'idx_sysacc_account');

            $table->foreign(['account_id', 'company_id'], 'fk_sysacc_account')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_accounts');
    }
};
