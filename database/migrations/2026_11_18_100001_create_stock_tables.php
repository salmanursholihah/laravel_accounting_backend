<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 9C: inti stok (Tahap 5 domain G, Tahap 4 bagian 5). Tabel master (satuan, merek, kategori, gudang, barang),
 * saldo ringkas (stock_balances), buku besar stok (stock_ledger), dan Penyesuaian Persediaan (header + baris).
 * Menyambung FK komposit yang ditunda di migrasi lama (kolom item_id/unit_id/warehouse_id) lewat ALTER di akhir.
 */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->charset = 'utf8mb4';
        $t->collation = 'utf8mb4_0900_ai_ci';
    }

    private function master(string $name, callable $extra, bool $hasParent = false): void
    {
        Schema::create($name, function (Blueprint $t) use ($name, $extra) {
            $this->base($t);
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('kode', 30);
            $t->string('nama', 150);
            $extra($t);
            $t->boolean('is_aktif')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('updated_at');

            $t->unique(['id', 'company_id'], "uq_{$name}_id");
            $t->unique(['company_id', 'kode'], "uq_{$name}_kode");
            $t->foreign(['company_id'], "fk_{$name}_co")->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function up(): void
    {
        $this->master('units', fn () => null);
        $this->master('brands', fn () => null);

        $this->master('warehouses', function (Blueprint $t) {
            $t->string('alamat', 500)->nullable();
            $t->unsignedBigInteger('penanggung_jawab_id')->nullable()->comment('contacts.id');
            $t->boolean('is_default')->default(false);
        });
        Schema::table('warehouses', function (Blueprint $t) {
            $t->foreign(['penanggung_jawab_id', 'company_id'], 'fk_warehouses_pic')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
        });

        $this->master('item_categories', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('akun_persediaan_id')->nullable();
            $t->unsignedBigInteger('akun_penjualan_id')->nullable();
            $t->unsignedBigInteger('akun_hpp_id')->nullable();
        });
        Schema::table('item_categories', function (Blueprint $t) {
            $t->foreign(['parent_id', 'company_id'], 'fk_item_categories_parent')->references(['id', 'company_id'])->on('item_categories')->restrictOnDelete()->restrictOnUpdate();
            foreach (['persediaan', 'penjualan', 'hpp'] as $k) {
                $t->foreign(["akun_{$k}_id", 'company_id'], "fk_item_categories_{$k}")->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            }
        });

        Schema::create('items', function (Blueprint $t) {
            $this->base($t);
            $t->id();
            $t->char('public_id', 26);
            $t->unsignedBigInteger('company_id');
            $t->string('kode', 40);
            $t->string('nama', 200);
            $t->enum('tipe', ['barang', 'jasa'])->default('barang')->comment('Jasa tidak punya stok');
            $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->unsignedBigInteger('unit_id')->comment('Satuan dasar');
            $t->decimal('harga_jual', 19, 4)->default(0);
            $t->decimal('harga_beli_standar', 19, 4)->default(0);
            $t->string('barcode', 60)->nullable();
            $t->unsignedBigInteger('tax_code_jual_id')->nullable();
            $t->unsignedBigInteger('tax_code_beli_id')->nullable();
            $t->unsignedBigInteger('akun_persediaan_id')->nullable()->comment('Override akun (urutan: barang -> kategori -> default)');
            $t->unsignedBigInteger('akun_penjualan_id')->nullable();
            $t->unsignedBigInteger('akun_hpp_id')->nullable();
            $t->boolean('is_aktif')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('updated_at');

            $t->unique(['public_id'], 'uq_items_public');
            $t->unique(['id', 'company_id'], 'uq_items_id');
            $t->unique(['company_id', 'kode'], 'uq_items_kode');
            $t->index(['company_id', 'barcode'], 'idx_items_barcode');
            $t->index(['company_id', 'nama'], 'idx_items_nama');

            $t->foreign(['company_id'], 'fk_items_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['category_id', 'company_id'], 'fk_items_cat')->references(['id', 'company_id'])->on('item_categories')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['brand_id', 'company_id'], 'fk_items_brand')->references(['id', 'company_id'])->on('brands')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['unit_id', 'company_id'], 'fk_items_unit')->references(['id', 'company_id'])->on('units')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['tax_code_jual_id', 'company_id'], 'fk_items_taxj')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['tax_code_beli_id', 'company_id'], 'fk_items_taxb')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
            foreach (['persediaan', 'penjualan', 'hpp'] as $k) {
                $t->foreign(["akun_{$k}_id", 'company_id'], "fk_items_{$k}")->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            }
        });

        Schema::create('item_unit_conversions', function (Blueprint $t) {
            $this->base($t);
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('unit_id');
            $t->decimal('rasio_ke_dasar', 18, 6)->comment('1 unit ini = rasio x satuan dasar (dus -> pcs = 12)');

            $t->unique(['item_id', 'unit_id'], 'uq_iuc_item_unit');
            $t->foreign(['company_id'], 'fk_iuc_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_iuc_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['unit_id', 'company_id'], 'fk_iuc_unit')->references(['id', 'company_id'])->on('units')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `item_unit_conversions` ADD CONSTRAINT `chk_iuc_rasio` CHECK (rasio_ke_dasar > 0)');

        // saldo ringkas per barang per gudang (ALG-05); satu-satunya penulis: modul stok
        Schema::create('stock_balances', function (Blueprint $t) {
            $this->base($t);
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty_saldo', 18, 4)->default(0);
            $t->decimal('nilai_saldo', 19, 4)->default(0);
            $t->decimal('hpp_rata_rata', 19, 4)->default(0);
            $t->decimal('stok_minimum', 18, 4)->default(0);
            $t->boolean('perlu_hitung_ulang')->default(false);
            $t->date('hitung_ulang_dari')->nullable();
            $t->dateTime('updated_at');

            $t->primary(['company_id', 'item_id', 'warehouse_id']);
            $t->index(['company_id', 'warehouse_id'], 'idx_sb_wh');
            $t->foreign(['company_id'], 'fk_sb_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_sb_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['warehouse_id', 'company_id'], 'fk_sb_wh')->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
        });

        // buku besar stok: sumber semua laporan stok. Pembalikan = baris baru (reversed_of_id), bukan hapus.
        Schema::create('stock_ledger', function (Blueprint $t) {
            $this->base($t);
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('tanggal');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->decimal('qty_masuk', 18, 4)->default(0);
            $t->decimal('qty_keluar', 18, 4)->default(0);
            $t->decimal('harga_satuan', 19, 4)->default(0);
            $t->decimal('nilai', 19, 4)->default(0)->comment('Nilai gerakan (selalu positif); arah dari qty_masuk/qty_keluar');
            $t->decimal('qty_saldo', 18, 4)->default(0);
            $t->decimal('nilai_saldo', 19, 4)->default(0);
            $t->string('source_type', 60);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('reversed_of_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');

            $t->unique(['company_id', 'reversed_of_id'], 'uq_sl_reversed');
            $t->unique(['id', 'company_id'], 'uq_sl_id');
            $t->index(['company_id', 'item_id', 'warehouse_id', 'tanggal', 'id'], 'idx_sl_card');
            $t->index(['company_id', 'source_type', 'source_id'], 'idx_sl_source');

            $t->foreign(['company_id'], 'fk_sl_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_sl_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['warehouse_id', 'company_id'], 'fk_sl_wh')->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['reversed_of_id', 'company_id'], 'fk_sl_rev')->references(['id', 'company_id'])->on('stock_ledger')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `stock_ledger` ADD CONSTRAINT `chk_sl_satu_arah` CHECK ((qty_masuk > 0 AND qty_keluar = 0) OR (qty_keluar > 0 AND qty_masuk = 0))');

        // Penyesuaian Persediaan (header + baris)
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $this->base($t);
            $t->id();
            $t->char('public_id', 26);
            $t->unsignedBigInteger('company_id');
            $t->string('nomor', 50)->nullable()->comment('Diisi saat Posting (ALG-01); NULL saat Draft');
            $t->date('tanggal');
            $t->enum('status', ['draft', 'pending_approval', 'posted', 'void'])->default('draft');
            $t->unsignedBigInteger('warehouse_id');
            $t->string('alasan', 500)->nullable();
            $t->unsignedBigInteger('opname_result_id')->nullable()->comment('FK ditambahkan saat Stok Opname dibangun (Step 7)');
            $t->string('memo', 500)->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('diposting_oleh')->nullable();
            $t->dateTime('diposting_pada')->nullable();
            $t->unsignedBigInteger('void_oleh')->nullable();
            $t->dateTime('void_pada')->nullable();
            $t->string('alasan_void', 500)->nullable();
            $t->string('source_type', 60)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('updated_at');

            $t->unique(['public_id'], 'uq_stock_adjustments_public');
            $t->unique(['company_id', 'nomor'], 'uq_stock_adjustments_nomor');
            $t->unique(['id', 'company_id'], 'uq_stock_adjustments_id');
            $t->index(['company_id', 'status', 'tanggal', 'id'], 'idx_stock_adjustments_status');

            $t->foreign(['company_id'], 'fk_stock_adjustments_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['warehouse_id', 'company_id'], 'fk_stock_adjustments_wh')->references(['id', 'company_id'])->on('warehouses')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['journal_id', 'company_id'], 'fk_stock_adjustments_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['branch_id', 'company_id'], 'fk_stock_adjustments_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('stock_adjustments_lines', function (Blueprint $t) {
            $this->base($t);
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('stock_adjustment_id');
            $t->unsignedSmallInteger('line_no');
            $t->unsignedBigInteger('item_id');
            $t->string('deskripsi', 500)->nullable();
            $t->decimal('qty_selisih', 18, 4)->comment('Bertanda: + masuk, - keluar (satuan dasar)');
            $t->decimal('harga_satuan', 19, 4)->nullable()->comment('Opsional untuk selisih +; kosong = HPP rata-rata terakhir');
            $t->decimal('nilai', 19, 4)->default(0)->comment('Diisi saat Posting dari hasil mutasi stok');

            $t->unique(['stock_adjustment_id', 'line_no'], 'uq_sal_no');
            $t->foreign(['company_id'], 'fk_sal_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['stock_adjustment_id', 'company_id'], 'fk_sal_hdr')->references(['id', 'company_id'])->on('stock_adjustments')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['item_id', 'company_id'], 'fk_sal_item')->references(['id', 'company_id'])->on('items')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `stock_adjustments_lines` ADD CONSTRAINT `chk_sal_qty` CHECK (qty_selisih <> 0)');

        // menyambung FK komposit yang ditunda (kolom sudah ada, nullable; NULL tidak diperiksa FK)
        $targets = ['item_id' => ['items', 'item'], 'unit_id' => ['units', 'unit'], 'warehouse_id' => ['warehouses', 'wh'], 'gudang_id' => ['warehouses', 'wh'], 'gudang_asal_id' => ['warehouses', 'wh']];
        $tables = [
            'sales_quotations_lines', 'sales_orders_lines', 'sales_deliveries_lines', 'sales_invoices_lines', 'sales_returns_lines',
            'price_adjustment_lines', 'purchase_orders_lines', 'goods_receipts_lines', 'purchase_invoices_lines', 'purchase_returns_lines',
            'supplier_prices', 'sales_deliveries', 'goods_receipts',
        ];
        foreach ($tables as $table) {
            foreach ($targets as $col => [$ref, $short]) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, function (Blueprint $t) use ($table, $col, $ref, $short) {
                        $t->foreign([$col, 'company_id'], "fk_{$table}_{$short}9c")->references(['id', 'company_id'])->on($ref)->restrictOnDelete()->restrictOnUpdate();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        $targets = ['item_id' => 'item', 'unit_id' => 'unit', 'warehouse_id' => 'wh', 'gudang_id' => 'wh', 'gudang_asal_id' => 'wh'];
        foreach (['sales_quotations_lines', 'sales_orders_lines', 'sales_deliveries_lines', 'sales_invoices_lines', 'sales_returns_lines', 'price_adjustment_lines', 'purchase_orders_lines', 'goods_receipts_lines', 'purchase_invoices_lines', 'purchase_returns_lines', 'supplier_prices', 'sales_deliveries', 'goods_receipts'] as $table) {
            foreach ($targets as $col => $short) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropForeign("fk_{$table}_{$short}9c"));
                }
            }
        }
        foreach (['stock_adjustments_lines', 'stock_adjustments', 'stock_ledger', 'stock_balances', 'item_unit_conversions', 'items', 'item_categories', 'warehouses', 'brands', 'units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
