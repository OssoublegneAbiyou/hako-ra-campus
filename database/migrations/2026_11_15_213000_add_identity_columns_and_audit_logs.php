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
            $table->string('email')->nullable()->change();
            $table->string('login_identifier', 40)->nullable()->unique();
            $table->string('activation_secret_hash')->nullable();
            $table->timestamp('activation_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('adult_declared_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_login_method_check CHECK (email IS NOT NULL OR login_identifier IS NOT NULL)');

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 10);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id'], 'audit_subject_index');
            $table->index(['institution_id', 'created_at'], 'audit_institution_index');
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_actor_check CHECK (
            actor_type IN ('user','system') AND (actor_type <> 'user' OR actor_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('ALTER TABLE users DROP CHECK users_login_method_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['login_identifier']);
            $table->dropColumn(['login_identifier', 'activation_secret_hash', 'activation_expires_at', 'activated_at', 'adult_declared_at', 'password_changed_at']);
        });
    }
};
