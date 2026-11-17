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
        Schema::create('journals', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('nomor', 50)->comment('Dari number_sequences, tanpa celah');
            $table->date('tanggal');
            $table->unsignedBigInteger('period_id');
            $table->enum('tipe', ['general', 'auto', 'correction', 'opening', 'closing', 'reversal']);
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reversed_of_id')->nullable();
            $table->boolean('is_opening')->default(false);
            $table->boolean('is_closing')->default(false);
            $table->string('memo', 500)->nullable();
            $table->decimal('total_debit', 19, 4);
            $table->decimal('total_kredit', 19, 4);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at');

            $table->unique(['company_id', 'nomor'], 'uq_journals_number');
            $table->unique(['company_id', 'reversed_of_id'], 'uq_journals_reversed'); // Satu jurnal hanya dibalik sekali
            $table->unique(['id', 'company_id'], 'uq_journals_id_company');
            $table->index(['company_id', 'tanggal'], 'idx_journals_date');
            $table->index(['company_id', 'source_type', 'source_id'], 'idx_journals_source');
            $table->index(['period_id', 'company_id'], 'idx_journals_period');

            $table->foreign(['company_id'], 'fk_journals_company')->references(['id'])->on('companies')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['period_id', 'company_id'], 'fk_journals_period')->references(['id', 'company_id'])->on('accounting_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reversed_of_id', 'company_id'], 'fk_journals_reversed')->references(['id', 'company_id'])->on('journals')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['branch_id', 'company_id'], 'fk_journals_branch')->references(['id', 'company_id'])->on('branches')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE `journals` ADD CONSTRAINT `chk_journals_balanced` CHECK (total_debit = total_kredit AND total_debit > 0)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_journals_bu BEFORE UPDATE ON journals FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals is immutable: use a reversal journal';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_journals_bd BEFORE DELETE ON journals FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journals cannot be deleted: use a reversal journal';
END
SQL);

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journals_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_journals_bd');
        Schema::dropIfExists('journals');
    }
};
