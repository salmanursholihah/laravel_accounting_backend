<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6A.2. Approval berlaku untuk dua aksi: posting dan void.
 * - approval_rules.aksi: aturan berlaku untuk post atau void (doc_type '*' = semua dokumen).
 * - approval_requests.aksi + alasan_void: void yang menunggu persetujuan membawa alasannya.
 * - approval_requests.aktif_key: "company:doc_type:doc_id:aksi" selama pending, NULL setelah diputuskan.
 *   UNIQUE di MySQL mengizinkan banyak NULL, jadi satu dokumen/aksi hanya bisa punya SATU pengajuan pending.
 * Hanya menambah kolom (kompatibel mundur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_rules', function (Blueprint $table) {
            $table->enum('aksi', ['post', 'void'])->default('post')->after('doc_type');
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->enum('aksi', ['post', 'void'])->default('post')->after('doc_id');
            $table->string('alasan_void', 500)->nullable()->after('alasan_tolak');
            $table->string('aktif_key', 120)->nullable()->after('alasan_void');
            $table->unique('aktif_key', 'uq_areq_aktif');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropUnique('uq_areq_aktif');
            $table->dropColumn(['aksi', 'alasan_void', 'aktif_key']);
        });

        Schema::table('approval_rules', function (Blueprint $table) {
            $table->dropColumn('aksi');
        });
    }
};
