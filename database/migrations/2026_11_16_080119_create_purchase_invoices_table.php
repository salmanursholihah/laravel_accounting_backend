<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->nullable()->comment('Diisi saat Posting/Open (ALG-01); NULL saat Draft');
            $table->date('tanggal');
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->char('kode_mata_uang', 3)->default('IDR');
            $table->decimal('kurs', 19, 6)->default(1);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('diskon', 19, 4)->default(0);
            $table->decimal('dpp', 19, 4)->default(0);
            $table->decimal('total_pajak', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->string('no_faktur_pemasok', 80);
            $table->date('jatuh_tempo')->nullable();
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->string('no_faktur_pajak', 40)->nullable();
            $table->boolean('dapat_dikreditkan')->default(false);
            $table->boolean('harga_termasuk_pajak')->default(false);
            $table->decimal('total_dibayar', 19, 4)->default(0);
            $table->decimal('sisa_tagihan', 19, 4)->default(0);
            $table->enum('status_pembayaran', ['belum', 'sebagian', 'lunas'])->default('belum');
            $table->string('memo', 500)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('diposting_oleh')->nullable();
            $table->dateTime('diposting_pada')->nullable();
            $table->unsignedBigInteger('void_oleh')->nullable();
            $table->dateTime('void_pada')->nullable();
            $table->string('alasan_void', 500)->nullable();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedInteger('version')->default(1)->comment('Locking optimistik draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');

            $table->unique(['public_id'], 'uq_purchase_invoices_public');
            $table->unique(['company_id', 'nomor'], 'uq_purchase_invoices_nomor');
            $table->unique(['id', 'company_id'], 'uq_purchase_invoices_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_purchase_invoices_status');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_purchase_invoices_contact');
            $table->unique(['company_id', 'contact_id', 'no_faktur_pemasok'], 'uq_pinv_supplier_no'); // Cegah input ganda faktur pemasok
            $table->index(['company_id', 'contact_id', 'status_pembayaran', 'jatuh_tempo'], 'idx_pinv_open');

            $table->foreign(['company_id'], 'fk_purchase_invoices_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_purchase_invoices_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_purchase_invoices_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_purchase_invoices_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['payment_term_id', 'company_id'], 'fk_purchase_invoices_term')->references(['id', 'company_id'])->on('payment_terms')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `purchase_invoices` ADD CONSTRAINT `chk_purchase_invoices_0` CHECK (total_dibayar >= 0 AND sisa_tagihan >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
