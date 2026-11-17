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
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->nullable()->comment('Diisi saat Posting/Open (ALG-01); NULL saat Draft');
            $table->date('tanggal');
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'void'])->default('draft');
            $table->char('kode_mata_uang', 3)->default('IDR');
            $table->decimal('kurs', 19, 6)->default(1);
            $table->unsignedBigInteger('rekening_asal_id');
            $table->unsignedBigInteger('rekening_tujuan_id');
            $table->decimal('nominal', 19, 4);
            $table->decimal('biaya_admin', 19, 4)->default(0);
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

            $table->unique(['public_id'], 'uq_bank_transfers_public');
            $table->unique(['company_id', 'nomor'], 'uq_bank_transfers_nomor');
            $table->unique(['id', 'company_id'], 'uq_bank_transfers_id');
            $table->index(['company_id', 'status', 'tanggal', 'id'], 'idx_bank_transfers_status');

            $table->foreign(['company_id'], 'fk_bank_transfers_co')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['journal_id', 'company_id'], 'fk_bank_transfers_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_bank_transfers_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['rekening_asal_id', 'company_id'], 'fk_bank_transfers_asal')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['rekening_tujuan_id', 'company_id'], 'fk_bank_transfers_tujuan')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `bank_transfers` ADD CONSTRAINT `chk_bank_transfers_0` CHECK (rekening_asal_id <> rekening_tujuan_id)');
        DB::statement('ALTER TABLE `bank_transfers` ADD CONSTRAINT `chk_bank_transfers_1` CHECK (nominal > 0 AND biaya_admin >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
