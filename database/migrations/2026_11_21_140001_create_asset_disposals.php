<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tahap 7D-2: Disposisi Aset Tetap (UC-57). Tabel baru + satu kolom tanggal_dilepas (kompatibel mundur). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->nullable()->comment('Diberikan saat Posting (ALG-01)');
            $table->date('tanggal');
            $table->unsignedBigInteger('asset_id');
            $table->decimal('nilai_pelepasan', 19, 4)->default(0)->comment('Hasil penjualan; 0 = dihapus/write-off');
            $table->unsignedBigInteger('akun_penerimaan_id')->nullable()->comment('Kas/Bank/Piutang; wajib bila nilai_pelepasan > 0');
            $table->string('alasan', 500);
            $table->string('memo', 500)->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'void'])->default('draft');
            // snapshot saat Posting
            $table->decimal('harga_perolehan', 19, 4)->nullable();
            $table->decimal('akumulasi', 19, 4)->nullable();
            $table->decimal('nilai_buku', 19, 4)->nullable();
            $table->decimal('laba_rugi', 19, 4)->nullable()->comment('nilai_pelepasan - nilai_buku; + laba, - rugi');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('diposting_oleh')->nullable();
            $table->dateTime('diposting_pada')->nullable();
            $table->unsignedBigInteger('void_oleh')->nullable();
            $table->dateTime('void_pada')->nullable();
            $table->string('alasan_void', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_asset_disposals_id');
            $table->unique(['company_id', 'nomor'], 'uq_asset_disposals_nomor');
            $table->index(['company_id', 'status', 'tanggal'], 'idx_asset_disposals_status');

            $table->foreign(['company_id'], 'fk_asset_disp_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['asset_id', 'company_id'], 'fk_asset_disp_asset')->references(['id', 'company_id'])->on('fixed_assets')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_penerimaan_id', 'company_id'], 'fk_asset_disp_akun')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_asset_disp_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
        });

        // Satu disposisi aktif (draft/pending/posted) per aset; yang di-void membebaskan aset. Dijaga database.
        Schema::table('asset_disposals', function (Blueprint $table) {
            $table->unsignedBigInteger('aktif_key')->nullable()->virtualAs("IF(`status` = 'void', NULL, `asset_id`)");
        });
        Schema::table('asset_disposals', function (Blueprint $table) {
            $table->unique(['company_id', 'aktif_key'], 'uq_asset_disp_aktif');
        });

        DB::statement('ALTER TABLE `asset_disposals` ADD CONSTRAINT `chk_asset_disp_nilai` CHECK (nilai_pelepasan >= 0)');

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->date('tanggal_dilepas')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('tanggal_dilepas');
        });
        Schema::dropIfExists('asset_disposals');
    }
};
