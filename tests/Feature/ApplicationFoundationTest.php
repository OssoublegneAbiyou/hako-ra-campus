<?php

namespace Tests\Feature;

use App\Jobs\RuntimeHeartbeat;
use App\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class ApplicationFoundationTest extends TestCase
{
    public function test_home_serves_the_french_inertia_page_without_registration(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('lang="fr"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('locale', 'fr')
                ->where('dashboard', null)
                ->has('currentTime'));
        $this->get('/register')->assertNotFound();
    }

    public function test_rendering_a_page_never_writes_its_data_into_the_view_cache(): void
    {
        $marker = 'marqueur-fictif-'.bin2hex(random_bytes(8));
        $before = count(glob(config('view.compiled').'/*'));
        $this->get('/')->assertOk();
        $before = max($before, count(glob(config('view.compiled').'/*')));

        $this->get('/?trace='.$marker)->assertOk()->assertSee($marker, false);
        $this->get('/connexion?trace='.$marker)->assertOk();

        $this->assertCount($before, glob(config('view.compiled').'/*'));
        foreach (glob(config('view.compiled').'/*') as $file) {
            $this->assertStringNotContainsString($marker, (string) file_get_contents($file), basename($file));
        }
    }

    public function test_instants_stay_in_utc_while_display_uses_an_iana_timezone(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Le champ nom est obligatoire.', validator([], ['name' => 'required'])->errors()->first('name'));
        $this->assertContains(config('app.display_timezone'), timezone_identifiers_list());
        $this->assertSame('2026-11-15T10:00:00+00:00', now()->parse('2026-11-15 10:00:00')->toIso8601String());
    }

    public function test_database_worker_processes_a_real_queued_job(): void
    {
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Cache::forget('campus:runtime:worker');
        Cache::forget('campus:runtime:scheduler');
        RuntimeHeartbeat::dispatch();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNull(Cache::get('campus:runtime:worker'));

        $this->artisan('queue:work', [
            'connection' => 'database', '--once' => true, '--tries' => 1, '--timeout' => 30,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull(Cache::get('campus:runtime:worker'));
    }

    public function test_scheduler_records_a_heartbeat_and_dispatches_to_database(): void
    {
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Cache::forget('campus:runtime:worker');
        Cache::forget('campus:runtime:scheduler');
        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertNotNull(Cache::get('campus:runtime:scheduler'));
        // Deux tâches par minute : le battement de cœur et le cycle de rappels (lot 13), toutes deux exécutées par la file, sans onglet ouvert.
        $queued = DB::table('jobs')->pluck('payload')->implode(' ');
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertStringContainsString('RuntimeHeartbeat', $queued);
        $this->assertStringContainsString('RunNotificationCycle', $queued);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertSame([0, 0], [DB::table('jobs')->count(), DB::table('failed_jobs')->count()]);
        // Quatre positions de reprise : cours, tâches, synthèse quotidienne, fiches suivies (lot 18).
        $this->assertSame(4, DB::table('notification_cursors')->count());
        // Ce test s'exécute hors transaction : la position des rappels écrite par le cycle réel est retirée pour ne pas influencer les autres tests.
        DB::table('notification_cursors')->delete();
        Cache::forget('campus:runtime:worker');
        Cache::forget('campus:runtime:scheduler');
    }

    public function test_private_storage_is_not_publicly_served(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        Storage::disk('local')->put('foundation-probe.txt', 'donnée fictive');
        try {
            $this->get('/storage/foundation-probe.txt')->assertNotFound();
        } finally {
            Storage::disk('local')->delete('foundation-probe.txt');
        }
    }

    public function test_logging_redacts_nested_secrets_and_exception_content(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'token=fictif Bearer fictif',
            context: [
                'nested' => ['password' => 'fictif', 'email' => 'personne@example.test'],
                'exception' => new \RuntimeException('contenu fictif à masquer'),
            ],
        );
        $safe = (new RedactSensitiveData)->process($record);
        $this->assertStringNotContainsString('fictif', $safe->message);
        $this->assertSame('[masqué]', $safe->context['nested']['password']);
        $this->assertSame('[masqué]', $safe->context['nested']['email']);
        $this->assertSame(\RuntimeException::class, $safe->context['exception']['type']);
        $this->assertArrayNotHasKey('message', $safe->context['exception']);
    }
}
