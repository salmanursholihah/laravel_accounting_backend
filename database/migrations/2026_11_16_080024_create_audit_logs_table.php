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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('aksi', 40)->comment('create|update|post|void|login|...');
            $table->string('alasan', 500)->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->dateTime('created_at');

            $table->index(['company_id', 'entity_type', 'entity_id', 'created_at'], 'idx_audit_entity');
            $table->index(['company_id', 'user_id', 'created_at'], 'idx_audit_user');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_audit_logs_bu BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_audit_logs_bd BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END
SQL);

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_bu');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_bd');
        Schema::dropIfExists('audit_logs');
    }
};
