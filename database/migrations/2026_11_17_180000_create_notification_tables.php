<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('timezone', 64)->nullable();
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();
            $table->unsignedSmallInteger('course_end_delay_minutes')->default(5);
            $table->boolean('task_day_before')->default(true);
            $table->boolean('task_same_day')->default(true);
            $table->time('task_reminder_time')->default('08:00:00');
            $table->boolean('digest_enabled')->default(false);
            $table->time('digest_time')->default('07:00:00');
            $table->json('categories')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_check CHECK (course_end_delay_minutes <= 120 AND ((quiet_start IS NULL AND quiet_end IS NULL) OR (quiet_start IS NOT NULL AND quiet_end IS NOT NULL AND quiet_start <> quiet_end)))');

        // Rappel logique : une ligne par personne, type, objet et échéance. dedup_key rend toute création rejouable.
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('type', 40);
            $table->string('title', 160);
            $table->string('body', 300)->nullable();
            $table->string('url', 255);
            $table->uuid('subject_uuid')->nullable();
            $table->dateTime('anchor_at')->nullable();
            $table->json('context')->nullable();
            $table->string('dedup_key', 190)->unique();
            $table->dateTime('scheduled_for');
            $table->dateTime('expires_at');
            $table->string('status', 20)->default('scheduled');
            $table->string('status_reason', 60)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_for'], 'user_notifications_due_index');
            $table->index(['user_id', 'status', 'delivered_at'], 'user_notifications_center_index');
            $table->index(['user_id', 'type', 'subject_uuid'], 'user_notifications_subject_index');
        });
        DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_status_check CHECK (status IN ('scheduled','delivered','cancelled','expired'))");

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('device_label', 80);
            $table->dateTime('last_accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at'], 'push_subscriptions_user_index');
        });

        // Envoi sur un canal externe. Une seule ligne par rappel et par cible : un rejeu ne crée jamais un second envoi.
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_notification_id')->constrained('user_notifications')->restrictOnDelete();
            $table->string('channel', 10);
            $table->foreignId('push_subscription_id')->nullable()->constrained('push_subscriptions')->restrictOnDelete();
            $table->string('target_key', 40);
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            $table->dateTime('claimed_at')->nullable();
            $table->string('last_error', 60)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['user_notification_id', 'target_key'], 'notification_deliveries_target_unique');
            $table->index(['status', 'next_attempt_at'], 'notification_deliveries_due_index');
        });
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_check CHECK (channel IN ('email','push')
            AND status IN ('queued','processing','accepted','failed','uncertain','cancelled','expired'))");

        Schema::create('notification_cursors', function (Blueprint $table): void {
            $table->string('name', 40)->primary();
            $table->dateTime('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_cursors');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_preferences');
    }
};
