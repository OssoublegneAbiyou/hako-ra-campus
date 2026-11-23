<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->index();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended'))");

        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->char('country_code', 2)->default('CI');
            $table->string('timezone', 64)->default('Africa/Abidjan');
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('personal_spaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('timezone', 64)->default('Africa/Abidjan');
            $table->json('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->unique(['id', 'institution_id'], 'years_context_unique');
        });
        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT years_dates_check CHECK (ends_on > starts_on)');

        Schema::create('periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('parent_period_id')->nullable();
            $table->string('code', 40);
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('weight', 10, 4)->default(1);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['academic_year_id', 'code']);
            $table->unique(['id', 'institution_id', 'academic_year_id'], 'periods_context_unique');
            $table->foreign(['academic_year_id', 'institution_id'], 'periods_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['parent_period_id', 'institution_id', 'academic_year_id'], 'periods_parent_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('periods')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE periods ADD CONSTRAINT periods_dates_check CHECK (ends_on >= starts_on AND weight > 0)');

        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('code', 40);
            $table->string('name');
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['academic_year_id', 'code']);
            $table->unique(['id', 'institution_id', 'academic_year_id'], 'groups_context_unique');
            $table->foreign(['academic_year_id', 'institution_id'], 'groups_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->unique(['id', 'institution_id'], 'subjects_context_unique');
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('scope', 20);
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->string('role', 40);
            $table->string('status', 20)->default('active');
            $table->string('local_identifier', 100)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('scope_key', 100)->storedAs("CONCAT(scope, ':', COALESCE(institution_id, 0), ':', COALESCE(academic_year_id, 0))");
            $table->unsignedTinyInteger('current_slot')->nullable()->storedAs("CASE WHEN status <> 'closed' THEN 1 ELSE NULL END");
            $table->string('current_local_identifier', 100)->nullable()->storedAs("CASE WHEN status <> 'closed' AND role = 'learner' THEN local_identifier ELSE NULL END");
            $table->timestamps();
            $table->unique(['user_id', 'scope_key', 'role', 'current_slot'], 'memberships_current_unique');
            $table->unique(['institution_id', 'academic_year_id', 'current_local_identifier'], 'memberships_local_identifier_unique');
            $table->unique(['id', 'user_id', 'institution_id', 'academic_year_id', 'role'], 'memberships_context_unique');
            $table->index(['user_id', 'status', 'scope'], 'memberships_user_access_index');
            $table->index(['institution_id', 'academic_year_id', 'status'], 'memberships_institution_index');
            $table->foreign(['academic_year_id', 'institution_id'], 'memberships_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_scope_check CHECK (
            (scope = 'institution' AND institution_id IS NOT NULL AND academic_year_id IS NOT NULL AND role IN ('learner','teacher','institution_admin'))
            OR (scope = 'platform' AND institution_id IS NULL AND academic_year_id IS NULL AND role IN ('catalogue_editor','platform_admin') AND local_identifier IS NULL))");
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_state_check CHECK (
            status IN ('active','suspended','closed')
            AND (ends_at IS NULL OR ends_at >= starts_at)
            AND (status <> 'suspended' OR suspended_at IS NOT NULL)
            AND (status <> 'closed' OR ends_at IS NOT NULL OR revoked_at IS NOT NULL)
            AND (status <> 'active' OR revoked_at IS NULL)
            AND (local_identifier IS NULL OR CHAR_LENGTH(TRIM(local_identifier)) > 0))");

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('group_id');
            $table->string('membership_role', 40)->default('learner');
            $this->assignmentDates($table);
            $table->unique(['membership_id', 'group_id', 'current_slot'], 'enrollments_current_unique');
            $table->index(['user_id', 'institution_id', 'academic_year_id'], 'enrollments_user_context_index');
            $table->foreign(['membership_id', 'user_id', 'institution_id', 'academic_year_id', 'membership_role'], 'enrollments_membership_fk')
                ->references(['id', 'user_id', 'institution_id', 'academic_year_id', 'role'])->on('memberships')->restrictOnDelete();
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'enrollments_group_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
        });
        $this->assignmentChecks('enrollments', 'learner');

        Schema::create('teaching_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('membership_role', 40)->default('teacher');
            $this->assignmentDates($table);
            $table->unique(['membership_id', 'group_id', 'subject_id', 'current_slot'], 'teaching_current_unique');
            $table->index(['user_id', 'institution_id', 'academic_year_id'], 'teaching_user_context_index');
            $table->foreign(['membership_id', 'user_id', 'institution_id', 'academic_year_id', 'membership_role'], 'teaching_membership_fk')
                ->references(['id', 'user_id', 'institution_id', 'academic_year_id', 'role'])->on('memberships')->restrictOnDelete();
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'teaching_group_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
            $table->foreign(['subject_id', 'institution_id'], 'teaching_subject_fk')
                ->references(['id', 'institution_id'])->on('subjects')->restrictOnDelete();
        });
        $this->assignmentChecks('teaching_assignments', 'teacher');
    }

    private function assignmentDates(Blueprint $table): void
    {
        $table->string('status', 20)->default('active');
        $table->dateTime('starts_at');
        $table->dateTime('ends_at')->nullable();
        $table->dateTime('closed_at')->nullable();
        $table->unsignedTinyInteger('current_slot')->nullable()->storedAs("CASE WHEN status = 'active' THEN 1 ELSE NULL END");
        $table->timestamps();
    }

    private function assignmentChecks(string $table, string $role): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_state_check CHECK (
            membership_role = '{$role}' AND status IN ('active','closed')
            AND (ends_at IS NULL OR ends_at >= starts_at)
            AND (status <> 'closed' OR closed_at IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['teaching_assignments', 'enrollments', 'memberships', 'subjects', 'groups', 'periods', 'academic_years', 'personal_spaces', 'institutions'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE users DROP CHECK users_status_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('status'));
    }
};
