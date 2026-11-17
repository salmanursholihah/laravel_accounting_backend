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
        Schema::create('sales_receipts', function (Blueprint $table) {
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
            $table->decimal('total', 19, 4)->default(0);
            $table->unsignedBigInteger('rekening_penerimaan_id');
            $table->string('metode', 30)->nullable();
            $table->decimal('nominal', 19, 4);
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

            $table->unique(['public_id'], 'uq_sales_receipts_public');
            $table->unique(['company_id', 'nomor'], 'uq_sales_receipts_nomor');
            $table->unique(['id', 'company_id'], 'uq_sales_receipts_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_sales_receipts_status');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_sales_receipts_contact');

            $table->foreign(['company_id'], 'fk_sales_receipts_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_sales_receipts_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_sales_receipts_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_sales_receipts_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['rekening_penerimaan_id', 'company_id'], 'fk_sales_receipts_rek')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `sales_receipts` ADD CONSTRAINT `chk_sales_receipts_0` CHECK (nominal > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_receipts');
    }
};
