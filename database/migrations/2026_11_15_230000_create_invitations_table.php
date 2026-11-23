<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20);
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('role', 40);
            $table->string('email')->nullable();
            $table->string('local_identifier', 100)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->dateTime('expires_at');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('inviter_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('review_reason', 40)->nullable();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->timestamps();
            $table->index(['institution_id', 'academic_year_id', 'status'], 'invitations_perimeter_index');
            $table->index(['status', 'expires_at'], 'invitations_expiry_index');
            $table->foreign(['academic_year_id', 'institution_id'], 'invitations_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['group_id', 'institution_id', 'academic_year_id'], 'invitations_group_fk')
                ->references(['id', 'institution_id', 'academic_year_id'])->on('groups')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_scope_check CHECK (
            (scope = 'institution' AND institution_id IS NOT NULL AND academic_year_id IS NOT NULL AND role IN ('learner','teacher','institution_admin'))
            OR (scope = 'platform' AND institution_id IS NULL AND academic_year_id IS NULL AND role = 'catalogue_editor'))");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_state_check CHECK (
            status IN ('pending','pending_review','accepted','revoked','rejected')
            AND (role = 'learner' OR (group_id IS NULL AND local_identifier IS NULL))
            AND (status <> 'accepted' OR (membership_id IS NOT NULL AND accepted_by IS NOT NULL))
            AND (status <> 'pending_review' OR accepted_by IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
