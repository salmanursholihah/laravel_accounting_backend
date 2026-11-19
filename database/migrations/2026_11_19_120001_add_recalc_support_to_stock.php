<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 7A: hitung ulang HPP transaksi mundur (ALG-05 onBackdated).
 *  - stock_ledger.harga_tetap: 1 = harga/nilai baris ditetapkan dokumen (beli, retur, penyesuaian dengan harga) dan tidak
 *    diubah replay; 0 = mengikuti HPP rata-rata saat itu (jual, penyesuaian tanpa harga) dan DIHITUNG ULANG saat replay.
 *  - stock_recalc_runs: riwayat job (satu baris per percobaan), dasar pemeriksaan "hitung ulang tertunda" di Akhir Bulan (ALG-11).
 * Migrasi lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $t) {
            $t->boolean('harga_tetap')->default(false)->after('nilai')
                ->comment('1 = harga ditetapkan dokumen (tidak ikut hitung ulang); 0 = mengikuti HPP rata-rata');
        });

        // Isi untuk baris yang sudah ada (sebelum kolom ini): semua gerakan MASUK dianggap berharga tetap
        // (beli, saldo awal, penyesuaian berharga); semua gerakan KELUAR dianggap rata-rata (jual, penyesuaian minus).
        // Pembalik (reversed_of_id) tidak memakai kolom ini; nilainya mengikuti baris asal.
        DB::statement('UPDATE stock_ledger SET harga_tetap = 1 WHERE qty_masuk > 0 AND reversed_of_id IS NULL');

        Schema::create('stock_recalc_runs', function (Blueprint $t) {
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_0900_ai_ci';

            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->date('dari')->nullable();
            $t->enum('status', ['selesai', 'tanpa_perubahan', 'gagal']);
            $t->unsignedInteger('baris_berubah')->default(0);
            $t->unsignedInteger('jurnal_dibuat')->default(0);
            $t->string('pesan', 500)->nullable();
            $t->unsignedBigInteger('dijalankan_oleh')->nullable();
            $t->dateTime('created_at');

            $t->unique(['id', 'company_id'], 'uq_srr_id');
            $t->index(['company_id', 'status', 'created_at'], 'idx_srr_status');
            $t->index(['company_id', 'item_id', 'warehouse_id', 'id'], 'idx_srr_item');
            $t->foreign(['company_id'], 'fk_srr_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_srr_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['warehouse_id', 'company_id'], 'fk_srr_wh')->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_recalc_runs');
        Schema::table('stock_ledger', fn (Blueprint $t) => $t->dropColumn('harga_tetap'));
    }
};
