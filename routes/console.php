<?php

use App\Jobs\RunNotificationCycle;
use App\Jobs\RuntimeHeartbeat;
use App\Support\BackupCipher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Cache::put('campus:runtime:scheduler', now()->utc()->toIso8601String(), now()->addMinutes(5));
    RuntimeHeartbeat::dispatch();
})->name('campus-runtime-heartbeat')->everyMinute()->withoutOverlapping();

Schedule::command('campus:purge-import-files')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('campus:purge-tasks')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('campus:sync:prune')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('campus:retention:run')->dailyAt('04:00')->withoutOverlapping();
// Sauvegarde quotidienne, seulement si un dossier et une clé sont configurés : jamais de sauvegarde en clair ni dans l'instance par défaut.
Schedule::command('campus:backup create')->dailyAt('02:00')->withoutOverlapping()
    ->when(fn () => is_string(config('campus.backup.directory')) && config('campus.backup.directory') !== '' && BackupCipher::configuredKey() !== null);
Schedule::command('campus:opportunities:archive-closed')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('campus:attachments:maintain')->everyTenMinutes()->withoutOverlapping();
Schedule::job(new RunNotificationCycle)->name('campus-notifications')->everyMinute()->withoutOverlapping();
