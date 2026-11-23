<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_subjects', function (Blueprint $table): void {
            $table->decimal('grade_coefficient', 8, 3)->default(1);
        });
        DB::statement('ALTER TABLE personal_subjects ADD CONSTRAINT personal_subjects_coefficient_check CHECK (grade_coefficient > 0)');

        Schema::create('personal_grades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('personal_subject_id');
            $table->unsignedBigInteger('personal_period_id')->nullable();
            $table->string('title');
            $table->string('status', 20)->default('graded');
            $table->decimal('value', 9, 3)->nullable();
            $table->decimal('scale', 9, 3);
            $table->decimal('coefficient', 8, 3)->default(1);
            $table->date('assessed_on');
            $table->uuid('client_key');
            $table->char('content_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['owner_id', 'client_key'], 'personal_grades_client_unique');
            $table->index(['owner_id', 'personal_period_id', 'personal_subject_id'], 'personal_grades_lookup_index');
            $table->foreign(['personal_subject_id', 'owner_id'], 'personal_grades_subject_fk')->references(['id', 'owner_id'])->on('personal_subjects')->restrictOnDelete();
            $table->foreign(['personal_period_id', 'owner_id'], 'personal_grades_period_fk')->references(['id', 'owner_id'])->on('personal_periods')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE personal_grades ADD CONSTRAINT personal_grades_value_check CHECK (scale > 0 AND coefficient > 0
            AND status IN ('graded','pending','absent','exempt','not_submitted')
            AND ((status = 'graded' AND value IS NOT NULL AND value >= 0 AND value <= scale) OR (status <> 'graded' AND value IS NULL)))");

        Schema::create('subject_coefficients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('subject_id');
            $table->decimal('coefficient', 8, 3);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['academic_year_id', 'subject_id'], 'subject_coefficients_unique');
            $table->foreign(['academic_year_id', 'institution_id'], 'subject_coefficients_year_fk')->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['subject_id', 'institution_id'], 'subject_coefficients_subject_fk')->references(['id', 'institution_id'])->on('subjects')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE subject_coefficients ADD CONSTRAINT subject_coefficients_check CHECK (coefficient > 0)');

        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('period_id');
            $table->foreignId('author_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->string('title');
            $table->decimal('scale', 9, 3);
            $table->decimal('coefficient', 8, 3)->default(1);
            $table->date('assessed_on');
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->uuid('client_key');
            $table->char('content_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['author_membership_id', 'client_key'], 'assessments_client_unique');
            $table->index(['group_id', 'status', 'period_id'], 'assessments_group_index');
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'assessments_group_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
            $table->foreign(['subject_id', 'institution_id'], 'assessments_subject_fk')->references(['id', 'institution_id'])->on('subjects')->restrictOnDelete();
            $table->foreign(['period_id', 'institution_id', 'academic_year_id'], 'assessments_period_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('periods')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_value_check CHECK (scale > 0 AND coefficient > 0 AND status IN ('draft','published','withdrawn'))");

        Schema::create('assessment_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('assessment_id')->constrained('assessments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->decimal('value', 9, 3)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['assessment_id', 'user_id'], 'assessment_results_unique');
            $table->index(['user_id', 'assessment_id'], 'assessment_results_user_index');
        });
        DB::statement("ALTER TABLE assessment_results ADD CONSTRAINT assessment_results_value_check CHECK (status IN ('graded','pending','absent','exempt','not_submitted')
            AND ((status = 'graded' AND value IS NOT NULL AND value >= 0) OR (status <> 'graded' AND value IS NULL)))");

        // Historique institutionnel des résultats : jamais modifié ni supprimé par l'application.
        Schema::create('assessment_result_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_result_id')->constrained('assessment_results')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->decimal('value', 9, 3)->nullable();
            $table->decimal('scale', 9, 3);
            $table->foreignId('actor_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->dateTime('created_at');
            $table->unique(['assessment_result_id', 'version'], 'result_revisions_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_result_revisions');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('subject_coefficients');
        Schema::dropIfExists('personal_grades');
        DB::statement('ALTER TABLE personal_subjects DROP CHECK personal_subjects_coefficient_check');
        Schema::table('personal_subjects', fn (Blueprint $table) => $table->dropColumn('grade_coefficient'));
    }
};
