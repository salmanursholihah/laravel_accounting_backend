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
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->boolean('hanya_miliknya')->default(false)->comment('Mis. posting hanya dokumen miliknya');
            $table->decimal('nilai_batas', 19, 4)->nullable()->comment('Batas diskon (persen) atau ambang approval (nominal)');
            $table->primary(['role_id', 'permission_id']);

            $table->foreign(['role_id'], 'fk_rp_role')->references(['id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['permission_id'], 'fk_rp_permission')->references(['id'])->on('permissions')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
