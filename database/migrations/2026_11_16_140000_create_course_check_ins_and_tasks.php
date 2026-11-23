<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_check_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('course_occurrence_id')->constrained('course_occurrences')->restrictOnDelete();
            $table->string('status', 20);
            $table->text('summary')->nullable();
            $table->text('work_to_do')->nullable();
            $table->text('points_to_review')->nullable();
            $table->unsignedTinyInteger('understanding')->nullable();
            $table->string('course_title');
            $table->dateTime('course_starts_at');
            $table->dateTime('course_ends_at');
            $table->char('content_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'course_occurrence_id'], 'check_ins_owner_occurrence_unique');
            $table->index(['owner_id', 'status', 'course_ends_at'], 'check_ins_owner_status_index');
        });
        DB::statement("ALTER TABLE course_check_ins ADD CONSTRAINT check_ins_state_check CHECK (
            status IN ('completed','nothing','deferred','ignored')
            AND (understanding IS NULL OR understanding BETWEEN 1 AND 4)
            AND (status = 'completed' OR (summary IS NULL AND work_to_do IS NULL AND points_to_review IS NULL AND understanding IS NULL)))");

        Schema::create('course_check_in_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_check_in_id')->constrained('course_check_ins')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->json('changed_fields');
            $table->dateTime('created_at');
            $table->unique(['course_check_in_id', 'version'], 'check_in_events_version_unique');
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 20)->default('personal');
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->string('priority', 20)->default('normal');
            $table->dateTime('due_at')->nullable();
            $table->foreignUuid('source_occurrence_id')->nullable()->constrained('course_occurrences')->restrictOnDelete();
            $table->foreignId('source_check_in_id')->nullable()->unique()->constrained('course_check_ins')->restrictOnDelete();
            $table->uuid('client_key');
            $table->unsignedInteger('lock_version')->default(1);
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'client_key'], 'tasks_owner_client_unique');
            $table->index(['owner_id', 'status', 'due_at'], 'tasks_owner_due_index');
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_state_check CHECK (
            ((scope = 'personal' AND owner_id IS NOT NULL) OR (scope = 'group' AND owner_id IS NULL))
            AND status IN ('todo','in_progress','done','abandoned') AND priority IN ('low','normal','high'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('course_check_in_events');
        Schema::dropIfExists('course_check_ins');
    }
};
