<?php

namespace Tests\Feature;

use App\Jobs\RunNotificationCycle;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\Backups;
use App\Services\HealthChecks;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use App\Services\NotificationStore;
use App\Support\AppVersion;
use App\Support\BackupCipher;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Lot 24 : sauvegarde chiffrée, restauration isolée, reprise après restauration, supervision, version en service. Données fictives uniquement.
 */
class OperationsTest extends TestCase
{
    use DatabaseTransactions;

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        // Coffre jetable, hors du dépôt, et clé propre au test.
        $this->vault = sys_get_temp_dir().DIRECTORY_SEPARATOR.'campus-test-coffre-'.Str::random(10);
        config(['campus.backup.directory' => $this->vault, 'campus.backup.key' => BackupCipher::generateKey(), 'campus.revision_file' => $this->vault.DIRECTORY_SEPARATOR.'REVISION']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);
        parent::tearDown();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function fakeBackup(string $name): void
    {
        File::ensureDirectoryExists($this->vault.DIRECTORY_SEPARATOR.$name);
        File::put($this->vault.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'manifest.json', '{}');
    }

    public function test_backups_are_encrypted_in_blocks_and_any_alteration_wrong_key_or_truncation_is_refused(): void
    {
        File::ensureDirectoryExists($this->vault);
        $cipher = new BackupCipher(BackupCipher::configuredKey());
        foreach (['vide' => '', 'court' => 'Contenu fictif de sauvegarde.', 'long' => str_repeat('Ligne fictive de sauvegarde, sans donnée réelle. ', 6000)] as $label => $content) {
            $plain = $this->vault.DIRECTORY_SEPARATOR.$label.'.txt';
            $encrypted = $plain.'.enc';
            $restored = $plain.'.restaure';
            File::put($plain, $content);
            $result = $cipher->encrypt($plain, $encrypted);
            $this->assertSame([hash('sha256', $content), strlen($content), hash_file('sha256', $encrypted)], [$result['plain_sha256'], $result['plain_bytes'], $result['cipher_sha256']], $label);
            // Le fichier chiffré ne contient pas le texte ; déchiffré, il est identique.
            $this->assertStringNotContainsString('fictif', (string) file_get_contents($encrypted));
            $this->assertSame([hash('sha256', $content), $content], [$cipher->decrypt($encrypted, $restored), file_get_contents($restored)], $label);
        }
        $encrypted = $this->vault.DIRECTORY_SEPARATOR.'long.txt.enc';
        $target = $this->vault.DIRECTORY_SEPARATOR.'refus.txt';
        $bytes = (string) file_get_contents($encrypted);
        $refused = function (string $file, ?BackupCipher $with = null) use ($cipher, $target): string {
            try {
                ($with ?? $cipher)->decrypt($file, $target);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return 'accepté';
        };
        // Autre clé, octet modifié, fichier tronqué, fichier qui n'est pas une sauvegarde : tous refusés.
        $this->assertSame('Sauvegarde altérée ou clé incorrecte : déchiffrement refusé.', $refused($encrypted, new BackupCipher(base64_decode(BackupCipher::generateKey()))));
        $altered = $bytes;
        $altered[70000] = chr(ord($altered[70000]) ^ 1);
        File::put($this->vault.DIRECTORY_SEPARATOR.'altere.enc', $altered);
        $this->assertSame('Sauvegarde altérée ou clé incorrecte : déchiffrement refusé.', $refused($this->vault.DIRECTORY_SEPARATOR.'altere.enc'));
        File::put($this->vault.DIRECTORY_SEPARATOR.'tronque.enc', substr($bytes, 0, 65536 + 17 + 33));
        $this->assertSame('Sauvegarde tronquée : la fin du fichier manque.', $refused($this->vault.DIRECTORY_SEPARATOR.'tronque.enc'));
        File::put($this->vault.DIRECTORY_SEPARATOR.'autre.enc', 'Ceci n’est pas une sauvegarde.');
        $this->assertSame('Ce fichier n’est pas une sauvegarde chiffrée de Campus.', $refused($this->vault.DIRECTORY_SEPARATOR.'autre.enc'));

        // La clé n'est jamais affichée : elle est écrite dans un fichier hors du dépôt, et refusée à l'intérieur.
        $keyFile = $this->vault.DIRECTORY_SEPARATOR.'cle.env';
        $this->artisan('campus:backup', ['action' => 'key', '--output' => $keyFile])->doesntExpectOutputToContain('CAMPUS_BACKUP_KEY=')->assertSuccessful();
        $this->assertMatchesRegularExpression('/^CAMPUS_BACKUP_KEY=[A-Za-z0-9+\/]{43}=\R$/', (string) file_get_contents($keyFile));
        $this->artisan('campus:backup', ['action' => 'key', '--output' => $keyFile])->assertFailed();
        $this->artisan('campus:backup', ['action' => 'key', '--output' => base_path('cle-fictive.env')])->expectsOutputToContain('hors du dépôt')->assertFailed();
        $this->assertFileDoesNotExist(base_path('cle-fictive.env'));
    }

    public function test_a_backup_needs_a_vault_and_a_key_keeps_thirty_days_and_refuses_unsafe_restores(): void
    {
        $backups = app(Backups::class);
        // Trente-quatre jours de sauvegardes quotidiennes : celles de plus de trente jours partent, les trois plus récentes restent toujours.
        foreach (range(0, 33) as $daysAgo) {
            $this->fakeBackup('campus-'.now()->utc()->subDays($daysAgo)->format('Ymd').'-020000');
        }
        File::ensureDirectoryExists($this->vault.DIRECTORY_SEPARATOR.'autre-dossier');
        $this->assertCount(34, $backups->names());
        $removed = $backups->prune();
        $this->assertSame(['campus-20261017-020000', 'campus-20261016-020000', 'campus-20261015-020000', 'campus-20261014-020000'], $removed);
        $this->assertSame(['campus-20261116-020000', 30], [$backups->names()[0], count($backups->names())]);
        $this->assertDirectoryExists($this->vault.DIRECTORY_SEPARATOR.'autre-dossier');
        config(['campus.retention.backup_days' => 1]);
        $this->assertCount(27, $backups->prune());
        $this->assertSame(['campus-20261116-020000', 'campus-20261115-020000', 'campus-20261114-020000'], $backups->names());

        // Noms et destinations dangereux : refusés avant toute lecture ou écriture.
        foreach (['../autre', 'campus-2026', 'latest; rm'] as $name) {
            $this->artisan('campus:backup', ['action' => 'verify', 'name' => $name])->expectsOutputToContain('Nom de sauvegarde invalide.')->assertFailed();
        }
        $this->artisan('campus:backup', ['action' => 'verify', 'name' => 'campus-20261116-020000'])->expectsOutputToContain('Manifeste altéré ou signé avec une autre clé')->assertFailed();
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'campus-20261116-020000', '--database' => 'hako_ra_campus_test', '--storage' => $this->vault.DIRECTORY_SEPARATOR.'fichiers'])
            ->expectsOutputToContain('Manifeste altéré')->assertFailed();
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'latest'])->expectsOutputToContain('--database=<base isolée, vide>')->assertFailed();

        // Sans coffre ou sans clé : aucune sauvegarde, message clair, rien de planifié.
        config(['campus.backup.key' => null]);
        $this->artisan('campus:backup', ['action' => 'create'])->expectsOutputToContain('Clé de sauvegarde non configurée')->assertFailed();
        config(['campus.backup.key' => 'trop-courte']);
        $this->assertNull(BackupCipher::configuredKey());
        config(['campus.backup.key' => BackupCipher::generateKey(), 'campus.backup.directory' => null]);
        $this->artisan('campus:backup', ['action' => 'create'])->expectsOutputToContain('Dossier des sauvegardes non configuré')->assertFailed();
        $this->assertSame([], $backups->names());
        $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command ?? '', 'campus:backup create'));
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));
        config(['campus.backup.directory' => $this->vault]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_a_real_backup_of_the_test_database_verifies_and_restores_its_files_in_an_isolated_folder(): void
    {
        $dump = env('CAMPUS_MYSQLDUMP_PATH') ?: (is_file('C:/wamp64/bin/mysql/mysql8.4.7/bin/mysqldump.exe') ? 'C:/wamp64/bin/mysql/mysql8.4.7/bin/mysqldump.exe' : 'mysqldump');
        if ($dump === 'mysqldump' && trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where mysqldump 2>NUL' : 'command -v mysqldump 2>/dev/null')) === '') {
            $this->markTestSkipped('mysqldump est indisponible sur ce poste : exercice de sauvegarde non exécuté.');
        }
        config(['campus.backup.mysqldump' => $dump]);
        $created = app(Backups::class)->create();
        $manifest = $created['manifest'];
        $path = $this->vault.DIRECTORY_SEPARATOR.$created['name'];
        // Deux fichiers chiffrés et un manifeste signé ; aucun fichier en clair ne reste, ni dans le coffre ni dans le stockage de travail.
        $this->assertEqualsCanonicalizing(['base.sql.enc', 'fichiers.tar.enc', 'manifest.json'], array_map('basename', File::files($path)));
        $this->assertSame([], File::directories(storage_path('app/private/sauvegarde-travail')));
        $this->assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($path.DIRECTORY_SEPARATOR.'base.sql.enc'));
        $this->assertSame(['hako_ra_campus_test', 'non versionnée', 1], [$manifest['database_name'], $manifest['version'], $manifest['format']]);
        $this->assertArrayHasKey('users', $manifest['database']['tables']);
        $this->assertContains('2026_11_22_150000_create_privacy_tables', $manifest['migrations']);
        // Le manifeste ne contient ni clé ni mot de passe.
        $raw = (string) file_get_contents($path.DIRECTORY_SEPARATOR.'manifest.json');
        foreach ([(string) config('campus.backup.key'), base64_decode((string) config('campus.backup.key')), 'DB_PASSWORD', 'MYSQL_PWD', '"username"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }

        $this->artisan('campus:backup', ['action' => 'verify', 'name' => 'latest', '--deep' => true])->expectsOutputToContain('0 table(s) manquante(s), export complet')->assertSuccessful();
        $this->artisan('campus:backup', ['action' => 'list'])->expectsOutputToContain($created['name'])->assertSuccessful();
        $this->assertNotNull(app(Backups::class)->lastSuccess());
        // Fichiers restaurés dans un dossier isolé et conformes ; dossier non vide refusé.
        $isolated = $this->vault.DIRECTORY_SEPARATOR.'fichiers-restaures';
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'latest', '--files-only' => true, '--storage' => $isolated])->expectsOutputToContain('conformes au manifeste')
            ->expectsOutputToContain('Cet exercice ne vaut pas restauration complète')->assertSuccessful();
        File::put($isolated.DIRECTORY_SEPARATOR.'intrus.txt', 'x');
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'latest', '--files-only' => true, '--storage' => $isolated])->expectsOutputToContain('doit être vide')->assertFailed();
        // La base en service n'est jamais une destination ; une base inaccessible est refusée sans rien écrire.
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'latest', '--database' => 'hako_ra_campus_test', '--storage' => $this->vault.DIRECTORY_SEPARATOR.'f2'])->expectsOutputToContain('différente de la base en service')->assertFailed();
        $this->artisan('campus:backup', ['action' => 'restore', 'name' => 'latest', '--database' => 'base_fictive_inexistante', '--storage' => $this->vault.DIRECTORY_SEPARATOR.'f3'])->expectsOutputToContain('Base de destination inaccessible')->assertFailed();
        $this->assertDirectoryDoesNotExist($this->vault.DIRECTORY_SEPARATOR.'f3');

        // Un octet modifié dans l'export chiffré, ou le manifeste retouché : la sauvegarde est refusée.
        $encrypted = $path.DIRECTORY_SEPARATOR.'base.sql.enc';
        $bytes = (string) file_get_contents($encrypted);
        $bytes[2000] = chr(ord($bytes[2000]) ^ 1);
        File::put($encrypted, $bytes);
        $this->artisan('campus:backup', ['action' => 'verify', 'name' => 'latest'])->expectsOutputToContain('Fichier de sauvegarde manquant ou altéré : base.sql.enc.')->assertFailed();
        $this->assertSame(1, AuditLog::where('action', 'backup.created')->count() + 1);
    }

    public function test_r52_r53_after_a_restore_old_reminders_are_neutralised_and_only_still_valid_ones_resume_once(): void
    {
        $owner = $this->user('independant');
        $task = function (string $due, string $title) use ($owner): Task {
            $key = (string) Str::uuid();
            $this->freshDevice()->actingAs($owner)->post('/taches', ['client_key' => $key, 'title' => $title, 'due_at' => $due])->assertSessionHasNoErrors();
            Task::where('client_key', $key)->update(['created_at' => now()->subDay()]);

            return Task::where('client_key', $key)->firstOrFail();
        };
        $store = app(NotificationStore::class);
        $reminder = function (Task $task, string $at, string $suffix = '') use ($store, $owner): int {
            $store->create($owner->id, 'task_due', 'task.due.d1', 'Tâche à rendre demain', 'Tâche : '.$task->title, '/taches', 'task-due:d1:'.$owner->id.':'.$task->id.':'.$task->due_at->getTimestamp().$suffix,
                now()->toImmutable()->setDateTimeFrom($at), $task->due_at->toImmutable(), $task->id, $task->due_at->toImmutable());

            return (int) DB::table('user_notifications')->where('subject_uuid', $task->id)->orderByDesc('id')->value('id');
        };
        // État de la base au moment de la sauvegarde, lundi 16 novembre à 8 h.
        $stale = $reminder($task('2026-11-17T18:00', 'Tâche fictive échue avant la restauration'), '2026-11-16 18:00');
        $valid = $reminder($task('2026-11-20T18:00', 'Tâche fictive encore utile'), '2026-11-17 18:00');
        $sent = $reminder($task('2026-11-21T18:00', 'Tâche fictive déjà annoncée'), '2026-11-15 18:00');
        DB::table('user_notifications')->where('id', $sent)->update(['status' => 'delivered', 'delivered_at' => now()]);
        foreach ([['email', 'email', 'queued'], ['email', 'email-bis', 'processing'], ['email', 'email-ter', 'accepted']] as [$channel, $target, $status]) {
            DB::table('notification_deliveries')->insert(['user_notification_id' => $sent, 'channel' => $channel, 'target_key' => $target, 'status' => $status, 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $owner->id, 'ip_address' => '192.0.2.10', 'user_agent' => 'Navigateur fictif', 'payload' => '', 'last_activity' => time()]);
        Cache::put('campus:runtime:worker', now()->toIso8601String(), 300);

        // La sauvegarde est restaurée et remise en service le mercredi 18 novembre à 9 h.
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));
        $this->artisan('campus:restore:finalize')
            ->expectsOutputToContain('deliveries_cancelled : 2')->expectsOutputToContain('reminders_expired : 1')->expectsOutputToContain('reminders_kept : 1')->expectsOutputToContain('jobs_removed : 1')
            ->assertSuccessful();
        // Anciens envois externes : annulés, jamais renvoyés ; l'envoi déjà accepté reste tel quel.
        $this->assertSame(['accepted' => 1, 'cancelled' => 2], DB::table('notification_deliveries')->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status')->all());
        $this->assertSame(['restored_backup'], DB::table('notification_deliveries')->where('status', 'cancelled')->distinct()->pluck('last_error')->all());
        $this->assertSame([['expired', 'too_late'], ['scheduled', null]], [[DB::table('user_notifications')->where('id', $stale)->value('status'), DB::table('user_notifications')->where('id', $stale)->value('status_reason')],
            [DB::table('user_notifications')->where('id', $valid)->value('status'), DB::table('user_notifications')->where('id', $valid)->value('status_reason')]]);
        // Sessions fermées, file vidée, battement de l'ancienne instance effacé.
        $this->assertSame([0, 0, false], [DB::table('sessions')->count(), DB::table('jobs')->count(), Cache::has('campus:runtime:worker')]);
        $this->assertEquals(['deliveries_cancelled' => 2, 'reminders_expired' => 1, 'reminders_kept' => 1, 'jobs_removed' => 1], AuditLog::where('action', 'restore.finalized')->sole()->metadata);

        // Retour de la file : le rappel encore valide est remis une fois, dans le centre ; rien d'autre ne part, même en rejouant le cycle.
        $cycle = fn () => (new RunNotificationCycle)->handle(app(NotificationPlanner::class), app(NotificationDispatcher::class));
        $cycle();
        $this->assertSame(['delivered', 'expired', 'delivered'], [DB::table('user_notifications')->where('id', $valid)->value('status'), DB::table('user_notifications')->where('id', $stale)->value('status'), DB::table('user_notifications')->where('id', $sent)->value('status')]);
        $before = [DB::table('user_notifications')->where('status', 'delivered')->count(), DB::table('notification_deliveries')->where('status', 'accepted')->count(), DB::table('notification_deliveries')->whereIn('status', ['queued', 'processing'])->count()];
        $cycle();
        $this->travel(5)->minutes();
        $cycle();
        $this->assertSame($before, [DB::table('user_notifications')->where('status', 'delivered')->count(), DB::table('notification_deliveries')->where('status', 'accepted')->count(), DB::table('notification_deliveries')->whereIn('status', ['queued', 'processing'])->count()]);
        $this->assertSame(1, DB::table('user_notifications')->where('subject_uuid', DB::table('user_notifications')->where('id', $valid)->value('subject_uuid'))->where('status', 'delivered')->count());
    }

    public function test_each_component_is_supervised_on_its_own_and_a_page_that_answers_proves_nothing_about_reminders(): void
    {
        $health = app(HealthChecks::class);
        // L'accueil répond, mais ni la file ni le planificateur ne tournent : la supervision le dit, composant par composant.
        $this->freshDevice()->get('/')->assertOk();
        Cache::forget('campus:runtime:worker');
        Cache::forget('campus:runtime:scheduler');
        $report = $health->run();
        $this->assertSame(HealthChecks::CHECKS, array_keys($report['checks']));
        $this->assertSame(['ok', 'ok', 'alert', 'alert', 'alert', 'unconfigured', 'ok', 'unconfigured', 'unconfigured'], array_column($report['checks'], 'status'));
        $this->assertFalse($report['healthy']);

        // Battements reçus, sauvegarde récente : ces trois contrôles passent ; au-delà des seuils, ils retombent en alerte.
        Cache::put('campus:runtime:worker', now()->utc()->toIso8601String(), 300);
        Cache::put('campus:runtime:scheduler', now()->utc()->toIso8601String(), 300);
        File::ensureDirectoryExists($this->vault);
        File::put($this->vault.DIRECTORY_SEPARATOR.'derniere-reussite.json', json_encode(['name' => 'campus-20261116-020000', 'at' => now()->utc()->subHours(6)->toIso8601String()]));
        $this->assertSame(['ok', 'ok', 'ok'], array_column($health->run(['worker', 'scheduler', 'backup'])['checks'], 'status'));
        $this->travel(4)->minutes();
        $this->assertSame(['alert', 'alert', 'ok'], array_column($health->run(['worker', 'scheduler', 'backup'])['checks'], 'status'));
        $this->travel(21)->hours();
        $this->assertSame('alert', $health->run(['backup'])['checks']['backup']['status']);

        // File : un traitement en attente depuis trop longtemps, puis un traitement en échec.
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(30)->timestamp, 'created_at' => now()->subMinutes(30)->timestamp]);
        $this->assertStringContainsString('le plus ancien depuis 30 min', $health->run(['queue'])['checks']['queue']['detail']);
        $this->assertSame('alert', $health->run(['queue'])['checks']['queue']['status']);
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Erreur fictive', 'failed_at' => now()]);
        $this->assertSame('alert', $health->run(['queue'])['checks']['queue']['status']);
        config(['campus.monitoring.failed_jobs_max' => 1]);
        $this->assertSame('ok', $health->run(['queue'])['checks']['queue']['status']);

        // Envois : part d'échecs et plafond quotidien du service de courriel.
        config(['mail.default' => 'smtp']);
        $notification = DB::table('user_notifications')->insertGetId(['user_id' => $this->user('independant')->id, 'category' => 'task_due', 'type' => 'task.due.d1', 'title' => 'Rappel fictif', 'url' => '/taches', 'dedup_key' => 'fictif-supervision',
            'scheduled_for' => now(), 'expires_at' => now()->addDay(), 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 10) as $index) {
            DB::table('notification_deliveries')->insert(['user_notification_id' => $notification, 'channel' => 'email', 'target_key' => 'cible-'.$index, 'status' => $index <= 7 ? 'accepted' : 'failed', 'attempts' => 1, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame('alert', $health->run(['deliveries'])['checks']['deliveries']['status']);
        config(['campus.monitoring.delivery_failure_percent' => 40]);
        $this->assertSame('ok', $health->run(['deliveries'])['checks']['deliveries']['status']);
        config(['campus.monitoring.mail_daily_limit' => 8]);
        $this->assertStringContainsString('88 % du plafond quotidien', $health->run(['deliveries'])['checks']['deliveries']['detail']);
        $this->assertSame('alert', $health->run(['deliveries'])['checks']['deliveries']['status']);
        // Aucun contrôle ne renvoie de donnée de compte.
        $this->assertStringNotContainsString('example.test', json_encode($health->run(), JSON_UNESCAPED_UNICODE));

        // Commande : code d'échec dès qu'un composant n'est pas sain ; sortie lisible.
        $this->artisan('campus:health', ['--only' => ['database', 'disk']])->expectsOutputToContain('OK             Base de données')->assertSuccessful();
        $this->artisan('campus:health')->expectsOutputToContain('NON CONFIGURÉ  Antivirus des fichiers')->assertFailed();
    }

    public function test_the_external_probe_is_closed_without_a_token_and_answers_per_component(): void
    {
        // Sans jeton configuré, ou avec un jeton trop court ou faux : introuvable.
        $this->freshDevice()->get('/sante')->assertNotFound();
        config(['campus.monitoring.token' => 'court']);
        $this->freshDevice()->withHeaders(['X-Monitoring-Token' => 'court'])->get('/sante')->assertNotFound();
        $token = Str::random(40);
        config(['campus.monitoring.token' => $token]);
        $this->flushHeaders();
        $this->freshDevice()->get('/sante')->assertNotFound();
        $this->freshDevice()->get('/sante?jeton='.$token)->assertNotFound();
        $this->freshDevice()->withHeaders(['X-Monitoring-Token' => $token.'x'])->get('/sante/database')->assertNotFound();
        // Avec le jeton : un état par composant, 200 si sain, 503 sinon ; une sonde distincte par adresse.
        $probe = fn (string $path) => $this->freshDevice()->withHeaders(['X-Monitoring-Token' => $token])->getJson($path);
        $probe('/sante/database')->assertOk()->assertJsonPath('healthy', true)->assertJsonPath('checks.database.status', 'ok')->assertJsonMissingPath('checks.worker')->assertHeader('Cache-Control', 'no-store, private');
        Cache::forget('campus:runtime:worker');
        $probe('/sante/worker')->assertStatus(503)->assertJsonPath('checks.worker.status', 'alert');
        $probe('/sante/inconnu')->assertNotFound();
        $all = $probe('/sante')->assertStatus(503);
        $this->assertSame(HealthChecks::CHECKS, array_keys($all->json('checks')));
        $this->assertSame(['version', 'checked_at', 'healthy', 'checks'], array_keys($all->json()));
        $this->flushHeaders();
    }

    public function test_the_version_in_service_is_shown_and_the_post_release_check_tells_what_is_not_running(): void
    {
        $this->assertSame('non versionnée', AppVersion::current());
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.version', 'non versionnée'));
        File::ensureDirectoryExists($this->vault);
        File::put((string) config('campus.revision_file'), "2026.11.23-a1b2c3d\n");
        $this->assertSame('2026.11.23-a1b2c3d', AppVersion::current());
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.version', '2026.11.23-a1b2c3d'));
        // Un contenu inattendu dans le fichier n'est jamais affiché.
        File::put((string) config('campus.revision_file'), '<script>alert(1)</script>');
        $this->assertSame('non versionnée', AppVersion::current());
        File::put((string) config('campus.revision_file'), '2026.11.23-a1b2c3d');

        // File synchrone des tests : le battement est traité aussitôt ; le planificateur, lui, ne tourne pas.
        Cache::forget('campus:runtime:scheduler');
        $this->artisan('campus:release:check', ['--wait' => 5])
            ->expectsOutputToContain('OK       Version en service identifiée — 2026.11.23-a1b2c3d')->expectsOutputToContain('OK       Aucune migration en attente')
            ->expectsOutputToContain('OK       Stockage privé inscriptible')->expectsOutputToContain('OK       Processus de file actif')
            ->expectsOutputToContain('ÉCHEC    Planificateur actif')->expectsOutputToContain('À faire à la main')->assertFailed();
        Cache::put('campus:runtime:scheduler', now()->utc()->toIso8601String(), 300);
        $this->artisan('campus:release:check', ['--wait' => 5])->expectsOutputToContain('Contrôles techniques réussis. Le contrôle manuel reste à faire.')->assertSuccessful();
    }
}
