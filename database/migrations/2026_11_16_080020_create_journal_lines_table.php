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
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('journal_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('account_id');
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('kredit', 19, 4)->default(0);
            $table->string('memo', 500)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable()->comment('Subledger piutang/utang');
            $table->date('tanggal')->comment('Denormalisasi untuk indeks histori akun');

            $table->unique(['journal_id', 'line_no'], 'uq_jl_line');
            $table->unique(['id', 'company_id'], 'uq_jl_id_company');
            $table->index(['company_id', 'account_id', 'tanggal'], 'idx_jl_account_date');
            $table->index(['company_id', 'contact_id', 'tanggal'], 'idx_jl_contact_date');

            $table->foreign(['journal_id', 'company_id'], 'fk_jl_journal')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['account_id', 'company_id'], 'fk_jl_account')->references(['id', 'company_id'])->on('accounts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['contact_id', 'company_id'], 'fk_jl_contact')->references(['id', 'company_id'])->on('contacts')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `journal_lines` ADD CONSTRAINT `chk_jl_side` CHECK (debit >= 0 AND kredit >= 0 AND (debit = 0 OR kredit = 0) AND (debit > 0 OR kredit > 0))');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_journal_lines_bu BEFORE UPDATE ON journal_lines FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines is immutable';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_journal_lines_bd BEFORE DELETE ON journal_lines FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_lines cannot be deleted';
END
SQL);

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_lines_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journal_lines_bd');
        Schema::dropIfExists('journal_lines');
    }
};
