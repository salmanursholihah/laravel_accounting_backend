<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 9C (lanjutan): Saldo Awal Stok. Baris dengan journal_id NULL = draf; terisi saat jurnal saldo awal diposting
 * (nilainya ikut jurnal itu, gerakannya dicatat di stock_ledger dengan source_type 'opening_balance').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stock_lines', function (Blueprint $t) {
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_0900_ai_ci';

            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty', 18, 4)->comment('Satuan dasar');
            $t->decimal('harga_satuan', 19, 4)->comment('Harga pokok per satuan dasar');
            $t->unsignedBigInteger('journal_id')->nullable()->comment('Terisi setelah jurnal saldo awal diposting');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');

            $t->unique(['company_id', 'item_id', 'warehouse_id'], 'uq_osl_item_wh');
            $t->index(['company_id', 'journal_id'], 'idx_osl_journal');

            $t->foreign(['company_id'], 'fk_osl_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_osl_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['warehouse_id', 'company_id'], 'fk_osl_wh')->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['journal_id', 'company_id'], 'fk_osl_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `opening_stock_lines` ADD CONSTRAINT `chk_osl_nilai` CHECK (qty > 0 AND harga_satuan >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_lines');
    }
};
