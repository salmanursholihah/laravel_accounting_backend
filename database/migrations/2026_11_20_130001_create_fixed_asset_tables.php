<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 7D: Aset Tetap. Hanya penambahan tabel baru (kompatibel mundur). Ref: ERD domain H, ALG-08. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 30);
            $table->string('nama', 150);
            $table->unsignedBigInteger('akun_aset_id');
            $table->unsignedBigInteger('akun_akumulasi_id');
            $table->unsignedBigInteger('akun_beban_id');
            $table->string('metode_default', 20)->default('garis_lurus');
            $table->unsignedSmallInteger('umur_default_bulan')->nullable();
            $table->boolean('is_aktif')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_asset_cat_id');
            $table->unique(['company_id', 'kode'], 'uq_asset_cat_kode');
            $table->foreign(['company_id'], 'fk_asset_cat_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_aset_id', 'company_id'], 'fk_asset_cat_aset')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_akumulasi_id', 'company_id'], 'fk_asset_cat_akum')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['akun_beban_id', 'company_id'], 'fk_asset_cat_beban')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kode', 30);
            $table->string('nama', 150);
            $table->unsignedBigInteger('category_id');
            $table->date('tanggal_perolehan');
            $table->decimal('harga_perolehan', 19, 4);
            $table->decimal('nilai_residu', 19, 4)->default(0);
            $table->unsignedSmallInteger('umur_bulan');
            $table->string('metode', 20)->default('garis_lurus');
            $table->date('tanggal_mulai_susut')->comment('Selalu tanggal 1; dihitung saat dibuat dari Preferensi aturan_awal_penyusutan');
            $table->enum('status', ['aktif', 'dilepas'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_fixed_assets_id');
            $table->unique(['company_id', 'kode'], 'uq_fixed_assets_kode');
            $table->index(['company_id', 'status', 'tanggal_mulai_susut'], 'idx_fixed_assets_susut');
            $table->foreign(['company_id'], 'fk_fixed_assets_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['category_id', 'company_id'], 'fk_fixed_assets_cat')->references(['id', 'company_id'])->on('asset_categories')->restrictOnDelete()->restrictOnUpdate();
        });

        // Satu baris per (periode, kategori): bukti penyusutan sebuah kategori sudah diproses dan jurnalnya.
        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('journal_id')->nullable()->comment('Null bila total penyusutan 0');
            $table->unsignedInteger('jumlah_aset');
            $table->decimal('total', 19, 4);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['id', 'company_id'], 'uq_dep_run_id');
            $table->unique(['company_id', 'period_id', 'category_id'], 'uq_dep_run');
            $table->foreign(['company_id'], 'fk_dep_run_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['period_id', 'company_id'], 'fk_dep_run_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['category_id', 'company_id'], 'fk_dep_run_cat')->references(['id', 'company_id'])->on('asset_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_dep_run_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('depreciation_schedules', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('asset_id');
            $table->enum('jenis', ['komersial', 'fiskal'])->default('komersial');
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedSmallInteger('bulan_ke');
            $table->decimal('nilai_penyusutan', 19, 4);
            $table->decimal('akumulasi', 19, 4);
            $table->decimal('nilai_buku', 19, 4);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');

            $table->unique(['company_id', 'asset_id', 'jenis', 'period_id'], 'uq_dep_sched');
            $table->index(['company_id', 'period_id'], 'idx_dep_sched_period');
            $table->foreign(['company_id'], 'fk_dep_sched_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['asset_id', 'company_id'], 'fk_dep_sched_asset')->references(['id', 'company_id'])->on('fixed_assets')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['period_id', 'company_id'], 'fk_dep_sched_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['run_id', 'company_id'], 'fk_dep_sched_run')->references(['id', 'company_id'])->on('depreciation_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_dep_sched_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_schedules');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
