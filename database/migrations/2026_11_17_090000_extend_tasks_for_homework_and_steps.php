<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('personal_subject_id')->nullable();
            $table->unsignedBigInteger('institution_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('author_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->foreign(['personal_subject_id', 'owner_id'], 'tasks_personal_subject_fk')->references(['id', 'owner_id'])->on('personal_subjects')->restrictOnDelete();
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'tasks_group_fk')->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
            $table->foreign(['subject_id', 'institution_id'], 'tasks_subject_fk')->references(['id', 'institution_id'])->on('subjects')->restrictOnDelete();
            $table->unique(['author_membership_id', 'client_key'], 'tasks_author_client_unique');
            $table->index(['group_id', 'deleted_at', 'due_at'], 'tasks_group_due_index');
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_scope_fields_check CHECK (
            (scope = 'personal' AND institution_id IS NULL AND academic_year_id IS NULL AND group_id IS NULL AND subject_id IS NULL AND author_membership_id IS NULL)
            OR (scope = 'group' AND institution_id IS NOT NULL AND academic_year_id IS NOT NULL AND group_id IS NOT NULL AND subject_id IS NOT NULL
                AND author_membership_id IS NOT NULL AND personal_subject_id IS NULL AND source_check_in_id IS NULL))");

        Schema::create('task_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('todo');
            $table->dateTime('personal_target_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['task_id', 'owner_id'], 'task_progress_owner_unique');
            $table->index(['owner_id', 'status'], 'task_progress_owner_status_index');
        });
        DB::statement("ALTER TABLE task_progress ADD CONSTRAINT task_progress_status_check CHECK (status IN ('todo','in_progress','done','abandoned'))");

        Schema::create('task_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('position');
            $table->uuid('client_key');
            $table->timestamps();
            $table->unique(['task_id', 'owner_id', 'client_key'], 'task_steps_client_unique');
            $table->index(['task_id', 'owner_id', 'position'], 'task_steps_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_steps');
        Schema::dropIfExists('task_progress');
        DB::statement('ALTER TABLE tasks DROP CHECK tasks_scope_fields_check');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign('tasks_personal_subject_fk');
            $table->dropForeign('tasks_group_fk');
            $table->dropForeign('tasks_subject_fk');
            $table->dropUnique('tasks_author_client_unique');
            $table->dropIndex('tasks_group_due_index');
            $table->dropConstrainedForeignId('author_membership_id');
            $table->dropColumn(['personal_subject_id', 'institution_id', 'academic_year_id', 'group_id', 'subject_id', 'content_hash']);
        });
    }
};
