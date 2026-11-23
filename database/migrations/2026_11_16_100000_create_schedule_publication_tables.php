<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedInteger('version');
            $table->string('kind', 20);
            $table->unsignedInteger('source_version')->nullable();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->uuid('request_key');
            $table->char('content_hash', 64);
            $table->json('summary');
            $table->dateTime('published_at');
            $table->unique(['institution_id', 'academic_year_id', 'version'], 'publications_version_unique');
            $table->unique(['institution_id', 'academic_year_id', 'request_key'], 'publications_request_unique');
            $table->foreign(['academic_year_id', 'institution_id'], 'publications_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE schedule_publications ADD CONSTRAINT publications_kind_check CHECK (
            version >= 1 AND ((kind = 'publish' AND source_version IS NULL) OR (kind = 'rollback' AND source_version IS NOT NULL AND source_version < version)))");

        Schema::create('schedule_publication_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_publication_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('course_occurrence_id')->constrained('course_occurrences')->restrictOnDelete();
            $table->string('change', 20);
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->unique(['schedule_publication_id', 'course_occurrence_id'], 'publication_change_unique');
            $table->index(['course_occurrence_id', 'schedule_publication_id'], 'publication_change_occurrence');
        });
        DB::statement("ALTER TABLE schedule_publication_changes ADD CONSTRAINT publication_change_check CHECK (`change` IN ('added','updated','cancelled','restored','removed'))");

        Schema::create('published_occurrences', function (Blueprint $table): void {
            $table->uuid('course_occurrence_id')->primary();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedInteger('publication_version');
            $table->char('snapshot_hash', 64);
            $table->string('title');
            $table->string('status', 30);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedBigInteger('teacher_membership_id')->nullable();
            $table->json('snapshot');
            $table->foreign(['course_occurrence_id', 'institution_id', 'academic_year_id'], 'published_occurrence_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('course_occurrences')->restrictOnDelete();
            $table->index(['institution_id', 'academic_year_id', 'starts_at'], 'published_time_index');
            $table->index(['teacher_membership_id', 'starts_at'], 'published_teacher_index');
        });

        Schema::create('published_occurrence_group', function (Blueprint $table): void {
            $table->uuid('course_occurrence_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->primary(['course_occurrence_id', 'group_id']);
            $table->foreign('course_occurrence_id')->references('course_occurrence_id')->on('published_occurrences')->restrictOnDelete();
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'published_group_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
            $table->index(['group_id', 'course_occurrence_id'], 'published_group_index');
        });

        Schema::create('domain_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 80);
            $table->string('dedup_key', 190)->unique();
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->json('payload');
            $table->dateTime('occurred_at');
            $table->dateTime('processed_at')->nullable();
            $table->index(['processed_at', 'id'], 'events_pending_index');
            $table->index(['type', 'occurred_at'], 'events_type_index');
        });

        Schema::create('teacher_unavailabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->uuid('course_occurrence_id');
            $table->foreignId('teacher_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->string('decision', 20)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedTinyInteger('open_slot')->nullable()->storedAs("CASE WHEN status = 'pending' THEN 1 ELSE NULL END");
            $table->timestamps();
            $table->foreign(['course_occurrence_id', 'institution_id', 'academic_year_id'], 'unavailability_occurrence_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('course_occurrences')->restrictOnDelete();
            $table->unique(['course_occurrence_id', 'teacher_membership_id', 'open_slot'], 'unavailability_open_unique');
            $table->index(['institution_id', 'academic_year_id', 'status'], 'unavailability_perimeter_index');
        });
        DB::statement("ALTER TABLE teacher_unavailabilities ADD CONSTRAINT unavailability_state_check CHECK (
            status IN ('pending','accepted','declined')
            AND (status = 'pending' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL AND decision IN ('decline','accept_cancel','accept_other'))))");
    }

    public function down(): void
    {
        foreach (['teacher_unavailabilities', 'domain_events', 'published_occurrence_group', 'published_occurrences', 'schedule_publication_changes', 'schedule_publications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
