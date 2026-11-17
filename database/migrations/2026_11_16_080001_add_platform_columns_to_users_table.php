<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel users bawaan Laravel dipertahankan (name, email, password, remember_token, timestamps).
     * Kolom 2FA (two_factor_*) sudah ada dari migration Fortify, jadi tidak ditambah lagi.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->unique('uq_users_public')->after('id')->comment('ULID untuk URL/API');
            $table->enum('status', ['active', 'suspended'])->default('active')->after('password');
            $table->boolean('is_admin_platform')->default(false)->after('status')->comment('Super Admin Platform, tanpa akses data keuangan');
            $table->dateTime('last_login')->nullable()->after('is_admin_platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_public');
            $table->dropColumn(['public_id', 'status', 'is_admin_platform', 'last_login']);
        });
    }
};
