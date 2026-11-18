<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pencatatan Beban (Tahap 5 domain C): header + baris. Dr Beban (+ PPN Masukan) · Cr Kas/Bank atau Utang Usaha. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->nullable()->comment('Diisi saat Posting (ALG-01); NULL saat Draft');
            $table->date('tanggal');
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'void'])->default('draft');
            $table->unsignedBigInteger('contact_id')->nullable()->comment('Penerima/pemasok; wajib bila dibayar lewat utang');
            $table->char('kode_mata_uang', 3)->default('IDR');
            $table->decimal('kurs', 19, 6)->default(1);
            $table->enum('jenis_pembayaran', ['kas_bank', 'utang'])->default('kas_bank');
            $table->unsignedBigInteger('rekening_pembayaran_id')->nullable();
            $table->decimal('total', 19, 4)->default(0);
            $table->boolean('dapat_dikreditkan')->default(true)->comment('PPN Masukan dikreditkan (faktur pajak valid)');
            $table->string('no_faktur_pajak', 50)->nullable();
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

            $table->unique(['public_id'], 'uq_expenses_public');
            $table->unique(['company_id', 'nomor'], 'uq_expenses_nomor');
            $table->unique(['id', 'company_id'], 'uq_expenses_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_expenses_status');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_expenses_contact');

            $table->foreign(['company_id'], 'fk_expenses_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_expenses_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_expenses_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_expenses_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['rekening_pembayaran_id', 'company_id'], 'fk_expenses_rek')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE `expenses` ADD CONSTRAINT `chk_expenses_pembayaran` CHECK ((jenis_pembayaran = 'kas_bank' AND rekening_pembayaran_id IS NOT NULL) OR (jenis_pembayaran = 'utang' AND contact_id IS NOT NULL))");

        Schema::create('expenses_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('expense_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('account_id');
            $table->string('deskripsi', 500)->nullable();
            $table->decimal('nominal', 19, 4)->default(0);
            $table->unsignedBigInteger('tax_code_id')->nullable();

            $table->unique(['expense_id', 'line_no'], 'uq_expenses_l_no');

            $table->foreign(['company_id'], 'fk_expenses_l_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['expense_id', 'company_id'], 'fk_expenses_l_hdr')->references(['id', 'company_id'])->on('expenses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['account_id', 'company_id'], 'fk_expenses_l_acc')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['tax_code_id', 'company_id'], 'fk_expenses_l_tax')->references(['id', 'company_id'])->on('tax_codes')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `expenses_lines` ADD CONSTRAINT `chk_expenses_l_nominal` CHECK (nominal > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses_lines');
        Schema::dropIfExists('expenses');
    }
};
