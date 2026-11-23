<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->unique(['id', 'institution_id', 'academic_year_id', 'role'], 'memberships_teacher_context'));
        Schema::table('personal_subjects', fn (Blueprint $t) => $t->unique(['id', 'owner_id']));
        Schema::create('course_series', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('scope', 20);
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->foreign(['academic_year_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['owner_id', 'request_key']);
            $t->unique(['institution_id', 'academic_year_id', 'request_key'], 'series_institution_request');
            $t->unique(['id', 'owner_id']);
            $t->unique(['id', 'institution_id', 'academic_year_id'], 'series_context');
        });
        DB::statement("ALTER TABLE course_series ADD CONSTRAINT series_scope CHECK ((scope='personal' AND owner_id IS NOT NULL AND institution_id IS NULL AND academic_year_id IS NULL) OR (scope='institution' AND owner_id IS NULL AND institution_id IS NOT NULL AND academic_year_id IS NOT NULL))");
        Schema::create('course_series_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('course_series_id')->constrained('course_series')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->json('rules');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 1000);
            $t->timestamp('created_at');
            $t->unique(['course_series_id', 'version']);
            $t->index(['course_series_id', 'effective_from']);
        });
        Schema::create('course_occurrences', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('course_series_id');
            $t->string('scope', 20);
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('academic_year_id')->nullable();
            $t->foreign(['course_series_id', 'owner_id'])->references(['id', 'owner_id'])->on('course_series')->restrictOnDelete();
            $t->foreign(['course_series_id', 'institution_id', 'academic_year_id'], 'occurrence_series_context_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('course_series')->restrictOnDelete();
            $t->foreign('course_series_id')->references('id')->on('course_series')->restrictOnDelete();
            $t->string('title');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('personal_subject_id')->nullable();
            $t->foreign(['subject_id', 'institution_id'])->references(['id', 'institution_id'])->on('subjects')->restrictOnDelete();
            $t->foreign(['personal_subject_id', 'owner_id'])->references(['id', 'owner_id'])->on('personal_subjects')->restrictOnDelete();
            $t->unsignedBigInteger('teacher_membership_id')->nullable();
            $t->string('teacher_role', 40)->default('teacher');
            $t->foreign(['teacher_membership_id', 'institution_id', 'academic_year_id', 'teacher_role'], 'occurrence_teacher_fk')->references(['id', 'institution_id', 'academic_year_id', 'role'])->on('memberships')->restrictOnDelete();
            $t->date('original_date');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('timezone', 64);
            $t->string('room', 100)->nullable();
            $t->string('meeting_url', 2048)->nullable();
            $t->string('status', 30)->default('active');
            $t->boolean('is_exception')->default(false);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['course_series_id', 'original_date']);
            $t->unique(['id', 'institution_id', 'academic_year_id'], 'occurrence_context');
            $t->index(['owner_id', 'starts_at']);
            $t->index(['institution_id', 'academic_year_id', 'starts_at'], 'occurrence_institution_time');
            $t->index(['institution_id', 'teacher_membership_id', 'starts_at'], 'occurrence_teacher_time');
        });
        DB::statement("ALTER TABLE course_occurrences ADD CONSTRAINT occurrence_scope CHECK ((scope='personal' AND owner_id IS NOT NULL AND institution_id IS NULL AND academic_year_id IS NULL AND subject_id IS NULL AND teacher_membership_id IS NULL) OR (scope='institution' AND owner_id IS NULL AND institution_id IS NOT NULL AND academic_year_id IS NOT NULL AND subject_id IS NOT NULL AND personal_subject_id IS NULL))");
        DB::statement("ALTER TABLE course_occurrences ADD CONSTRAINT occurrence_dates CHECK (ends_at > starts_at AND status IN ('active','cancelled','calendar_excluded') AND teacher_role='teacher')");
        Schema::create('course_occurrence_group', function (Blueprint $t): void {
            $t->uuid('course_occurrence_id');
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('institution_id');
            $t->unsignedBigInteger('academic_year_id');
            $t->primary(['course_occurrence_id', 'group_id']);
            $t->foreign(['course_occurrence_id', 'institution_id', 'academic_year_id'], 'occurrence_group_context_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('course_occurrences')->restrictOnDelete();
            $t->foreign(['group_id', 'institution_id', 'academic_year_id'], 'occurrence_group_reference_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
            $t->index(['group_id', 'course_occurrence_id']);
        });
        Schema::create('course_occurrence_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('course_occurrence_id')->constrained('course_occurrences')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('before')->nullable();
            $t->json('after');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 1000);
            $t->timestamp('created_at');
            $t->unique(['course_occurrence_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_occurrence_revisions');
        Schema::dropIfExists('course_occurrence_group');
        Schema::dropIfExists('course_occurrences');
        Schema::dropIfExists('course_series_revisions');
        Schema::dropIfExists('course_series');
        Schema::table('personal_subjects', fn (Blueprint $t) => $t->dropUnique(['id', 'owner_id']));
        Schema::table('memberships', fn (Blueprint $t) => $t->dropUnique('memberships_teacher_context'));
    }
};
