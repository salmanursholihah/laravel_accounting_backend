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
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('email', 190);
            $table->unsignedBigInteger('role_id');
            $table->char('token_hash', 64)->comment('Hash token, token asli hanya dikirim lewat email');
            $table->enum('status', ['pending', 'accepted', 'expired', 'revoked'])->default('pending');
            $table->dateTime('kedaluwarsa_pada');
            $table->unsignedBigInteger('diundang_oleh');
            $table->dateTime('created_at');

            $table->unique(['token_hash'], 'uq_invitation_token');
            $table->index(['company_id', 'status'], 'idx_invitation_company_status');

            $table->foreign(['company_id'], 'fk_inv_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['role_id'], 'fk_inv_role')->references(['id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diundang_oleh'], 'fk_inv_by')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
