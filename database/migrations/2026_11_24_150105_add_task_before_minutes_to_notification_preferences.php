<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rappel peu avant l'heure d'une tâche : nombre de minutes avant l'échéance, 0 pour ne pas en recevoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('task_before_minutes')->default(60)->after('task_reminder_time');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('task_before_minutes');
        });
    }
};
