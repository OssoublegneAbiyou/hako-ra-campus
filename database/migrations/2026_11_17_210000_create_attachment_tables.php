<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->restrictOnDelete();
            $table->foreignId('course_check_in_id')->nullable()->constrained('course_check_ins')->restrictOnDelete();
            // Visibilité explicite, fixée au dépôt : un fichier personnel ne devient jamais collectif par un changement de son parent.
            $table->string('visibility', 10);
            $table->string('display_name', 200);
            $table->char('stored_name', 64)->unique();
            $table->string('mime', 40);
            $table->string('extension', 5);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 12)->default('quarantine');
            $table->string('scan_detail', 120)->nullable();
            $table->unsignedSmallInteger('scan_attempts')->default(0);
            $table->dateTime('scan_started_at')->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->uuid('client_key');
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->dateTime('file_removed_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'client_key'], 'attachments_client_unique');
            $table->index(['owner_id', 'deleted_at'], 'attachments_owner_index');
            $table->index(['scan_status', 'updated_at'], 'attachments_scan_index');
        });
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_state_check CHECK (
            ((task_id IS NOT NULL AND course_check_in_id IS NULL) OR (task_id IS NULL AND course_check_in_id IS NOT NULL))
            AND visibility IN ('personal','group') AND (visibility = 'personal' OR task_id IS NOT NULL)
            AND mime IN ('application/pdf','image/jpeg','image/png') AND extension IN ('pdf','jpg','jpeg','png') AND size_bytes > 0
            AND scan_status IN ('quarantine','scanning','clean','rejected','error'))");

        Schema::create('attachment_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 12)->default('open');
            $table->unsignedTinyInteger('open_slot')->nullable()->default(1);
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['attachment_id', 'reporter_id', 'open_slot'], 'attachment_reports_open_unique');
            $table->index(['institution_id', 'status'], 'attachment_reports_queue_index');
        });
        DB::statement("ALTER TABLE attachment_reports ADD CONSTRAINT attachment_reports_check CHECK (status IN ('open','upheld','dismissed')
            AND ((status = 'open' AND open_slot IS NOT NULL AND open_slot = 1) OR (status <> 'open' AND open_slot IS NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_reports');
        Schema::dropIfExists('attachments');
    }
};
