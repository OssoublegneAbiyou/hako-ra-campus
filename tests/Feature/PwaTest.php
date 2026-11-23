<?php

namespace Tests\Feature;

use App\Models\CourseSeries;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\OfflineSnapshot;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $name, string $letter = 'A'): Membership
    {
        return $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail();
    }

    private function as(string $name, ?string $letter = null): static
    {
        return $this->freshDevice()->actingAs($this->user($name))->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    private function snapshot(string $name): array
    {
        return $this->as($name)->getJson('/hors-ligne/instantane')->assertOk()->json();
    }

    private function course(string $owner, ?string $letter, array $overrides): void
    {
        $this->as($owner, $letter)->post('/planning/series', array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif', 'kind' => 'once', 'valid_from' => '2026-11-18', 'valid_until' => '2026-11-18',
            'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [3], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides))->assertSessionHasNoErrors();
    }

    public function test_the_manifest_icons_and_shell_are_public_original_and_promise_no_native_application(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['HAKO-RA Campus', 'Campus', '/', '/', 'standalone', 'fr'], [$manifest['name'], $manifest['short_name'], $manifest['start_url'], $manifest['scope'], $manifest['display'], $manifest['lang']]);
        $this->assertStringContainsString('ce n’est pas une application native', $manifest['description']);
        // Icônes originales, aux dimensions annoncées, dont une version « maskable ».
        $this->assertEqualsCanonicalizing(['any', 'any', 'maskable'], array_column($manifest['icons'], 'purpose'));
        foreach ($manifest['icons'] as $icon) {
            [$width, $height, $type] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame([$icon['sizes'], IMAGETYPE_PNG], [$width.'x'.$height, $type]);
        }
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));
        // Aucun raccourci ni adresse du manifest ne pointe vers une page privée ou un fichier externe.
        $this->assertArrayNotHasKey('shortcuts', $manifest);
        $this->assertArrayNotHasKey('share_target', $manifest);
        // La page d'accueil annonce le manifest ; le service worker du push seul n'existe plus (fusionné).
        $this->get('/')->assertOk()->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)->assertSee('<meta name="theme-color" content="#1f6555">', false);
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileDoesNotExist(public_path('push-sw.js'));
        // La coque hors ligne est statique et neutre : aucune donnée, aucun appel réseau, aucun jeton.
        foreach (['offline.html', 'offline.js'] as $file) {
            $content = (string) file_get_contents(public_path($file));
            foreach (['example.test', 'csrf', 'XSRF', 'Bearer', 'password', 'fetch(', 'XMLHttpRequest', 'document.cookie'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content, $file.' : '.$forbidden);
            }
        }
        // Le cœur commun de la file hors connexion (lot 20) est public lui aussi : aucune donnée, aucun secret.
        foreach (['example.test', 'Bearer', 'password', 'localStorage'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) file_get_contents(public_path('offline-core.js')), 'offline-core.js : '.$forbidden);
        }
        $this->get('/')->assertSee('<script src="/offline-core.js?v='.filemtime(public_path('offline-core.js')).'" defer></script>', false);
        // Page « Hors connexion et installation » : publique, explicite sur les limites.
        $this->freshDevice()->get('/hors-ligne')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Offline')->where('ttlDays', 7)->where('windowDays', 14)->where('auth.accountKey', null));
        config(['campus.offline.ttl_days' => 3]);
        $this->freshDevice()->get('/hors-ligne')->assertInertia(fn (Assert $page) => $page->where('ttlDays', 3));
    }

    public function test_the_service_worker_and_storage_rules_pass_their_javascript_tests(): void
    {
        $process = new Process(['node', '--test', 'tests/js/offline-policy.test.mjs', 'tests/js/sw.test.mjs', 'tests/js/offline-core.test.mjs'], base_path());
        $process->setTimeout(120);
        $process->run();
        if ($process->getExitCode() === 127 || str_contains($process->getErrorOutput(), 'not recognized')) {
            $this->markTestSkipped('Node.js est indisponible sur ce poste : npm run test:js doit être exécuté séparément.');
        }
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('pass 22', $process->getOutput());
        $this->assertStringContainsString('fail 0', $process->getOutput());
    }

    public function test_r47_every_response_to_a_signed_in_account_forbids_storage_and_carries_an_opaque_account_key(): void
    {
        // Pages, données, exports : toute réponse à un compte connecté interdit sa conservation par le navigateur, un intermédiaire ou un service worker.
        foreach ([['apprenant-double', 'A', '/'], ['apprenant-double', 'A', '/planning'], ['apprenant-double', null, '/taches'], ['apprenant-double', null, '/notes'], ['apprenant-double', null, '/profil'],
            ['apprenant-double', null, '/opportunites/suivi'], ['apprenant-double', null, '/hors-ligne'], ['apprenant-double', null, '/hors-ligne/instantane'], ['admin-a', 'A', '/administration/etablissement'],
            ['admin-a', 'A', '/administration/etablissement/membres/export'], ['enseignant-a', 'A', '/notes/evaluations']] as [$name, $letter, $url]) {
            $control = (string) $this->as($name, $letter)->get($url)->assertOk()->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $control, $url);
            $this->assertStringContainsString('private', $control, $url);
            $this->assertStringNotContainsString('public', $control, $url);
        }
        // Requête Inertia (navigation interne) : même règle.
        $version = $this->as('apprenant-double')->get('/taches')->viewData('page')['version'] ?? '';
        $this->assertStringContainsString('no-store', (string) $this->as('apprenant-double')->get('/taches', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])->headers->get('Cache-Control'));

        // Clé de compte : opaque, stable pour un compte, différente d'un compte à l'autre, absente pour un visiteur.
        $first = $this->as('apprenant-double')->get('/')->viewData('page')['props']['auth']['accountKey'];
        $again = $this->as('apprenant-double', 'B')->get('/taches')->viewData('page')['props']['auth']['accountKey'];
        $second = $this->as('polyvalent')->get('/')->viewData('page')['props']['auth']['accountKey'];
        $this->assertSame($first, $again);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
        $this->assertSame([$first, $second], [OfflineSnapshot::accountKey($this->user('apprenant-double')), OfflineSnapshot::accountKey($this->user('polyvalent'))]);
        // Dérivée d'un secret du serveur : elle ne révèle pas le numéro du compte et ne peut pas être devinée pour un autre compte.
        $this->assertNotSame(md5((string) $this->user('apprenant-double')->id), $first);
        $this->assertNull($this->freshDevice()->get('/')->viewData('page')['props']['auth']['accountKey']);
        // L'instantané de chaque compte porte sa propre clé : l'appareil refuse d'enregistrer celui d'un autre compte.
        $this->assertSame([$first, $second], [$this->snapshot('apprenant-double')['accountKey'], $this->snapshot('polyvalent')['accountKey']]);
        // Sans session : pas d'instantané.
        $this->freshDevice()->get('/hors-ligne/instantane')->assertRedirect('/connexion');
        $this->freshDevice()->getJson('/hors-ligne/instantane')->assertStatus(401);
        DB::table('users')->where('id', $this->user('polyvalent')->id)->update(['status' => 'suspended']);
        // Compte suspendu : la session est fermée et renvoyée à la connexion (règle du lot 04) ; aucun instantané n'est servi.
        $this->as('polyvalent')->getJson('/hors-ligne/instantane')->assertRedirect('/connexion');
    }

    public function test_the_offline_snapshot_holds_only_what_the_account_may_read_now_within_fourteen_days(): void
    {
        $teacher = $this->member('enseignant-a');
        $group = Group::where('code', 'A-G1')->firstOrFail();
        $subject = Subject::where('institution_id', $teacher->institution_id)->firstOrFail();
        // Planning : un cours personnel dans la fenêtre, un autre au-delà de 14 jours, un cours publié du groupe, un brouillon non publié.
        $this->course('apprenant-double', null, ['title' => 'Révision fictive personnelle']);
        $this->course('apprenant-double', null, ['title' => 'Cours fictif trop lointain', 'valid_from' => '2026-12-05', 'valid_until' => '2026-12-05', 'weekdays' => [6]]);
        $this->course('admin-a', 'A', ['title' => 'Méthodologie fictive publiée', 'valid_from' => '2026-11-19', 'valid_until' => '2026-11-19', 'weekdays' => [4], 'group_ids' => [$group->id], 'subject_id' => $subject->id, 'teacher_membership_id' => $teacher->id]);
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();
        $this->course('admin-a', 'A', ['title' => 'Brouillon fictif non publié', 'valid_from' => '2026-11-20', 'valid_until' => '2026-11-20', 'weekdays' => [5], 'group_ids' => [$group->id], 'subject_id' => $subject->id, 'teacher_membership_id' => $teacher->id]);
        $this->assertSame(4, CourseSeries::count());
        // Tâches : une ouverte, une terminée. Notes : une personnelle. Opportunité favorite.
        $this->as('apprenant-double')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive ouverte', 'due_at' => '2026-11-20T18:00'])->assertSessionHasNoErrors();
        $done = (string) Str::uuid();
        $this->as('apprenant-double')->post('/taches', ['client_key' => $done, 'title' => 'Tâche fictive terminée'])->assertSessionHasNoErrors();
        DB::table('tasks')->where('client_key', $done)->update(['status' => 'done']);
        $personalSubject = PersonalSubject::factory()->create(['owner_id' => $this->user('apprenant-double')->id, 'name' => 'Mathématiques fictives']);
        $this->as('apprenant-double')->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Évaluation fictive', 'personal_subject_id' => $personalSubject->id, 'status' => 'graded', 'value' => '14', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $editor = $this->user('editeur')->id;
        $favorite = Opportunity::create(['category' => 'scholarship', 'title' => 'Bourse fictive favorite', 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/favorite',
            'conditions' => 'Conditions fictives de démonstration pour ce test.', 'countries' => [], 'levels' => [], 'fields' => [], 'languages' => [], 'status' => 'published', 'verified_at' => now(), 'verified_by' => $editor,
            'published_at' => now(), 'normalized_title' => 'bourse fictive favorite', 'normalized_organizer' => 'organisme fictif', 'source_hash' => hash('sha256', 'favorite'), 'created_by' => $editor]);
        $this->as('apprenant-double')->putJson('/opportunites/'.$favorite->id.'/favori')->assertOk();

        $snapshot = $this->snapshot('apprenant-double');
        $this->assertSame(['version', 'accountKey', 'displayName', 'generatedAt', 'timezone', 'windowDays', 'ttlDays', 'planning', 'tasks', 'checkIns', 'labels', 'grades', 'opportunities'], array_keys($snapshot));
        $this->assertSame([2, 'Apprenant fictif A et B', '2026-11-16T08:00:00+00:00', 14, 7], [$snapshot['version'], $snapshot['displayName'], $snapshot['generatedAt'], $snapshot['windowDays'], $snapshot['ttlDays']]);
        // Fenêtre de 14 jours, planning publié seulement.
        $this->assertSame([['Révision fictive personnelle', 'personal'], ['Méthodologie fictive publiée', 'institutional']], array_map(fn (array $course) => [$course['title'], $course['provenance']], $snapshot['planning']));
        $this->assertSame(['Tâche fictive ouverte'], array_column($snapshot['tasks'], 'title'));
        $this->assertSame([['Mes notes personnelles', 'Mathématiques fictives', '14,00']], array_map(fn (array $view) => [$view['label'], $view['subjects'][0]['name'], $view['subjects'][0]['average']], $snapshot['grades']));
        $this->assertSame([['Bourse fictive favorite', 'open']], array_map(fn (array $item) => [$item['title'], $item['state']], $snapshot['opportunities']));
        // Ni identifiant de compte, ni adresse électronique, ni jeton, ni lien de fichier, ni donnée d'administration.
        $raw = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        foreach (['@example.test', 'token', 'password', 'pieces-jointes', 'user_id', 'owner_id', 'membership', 'Brouillon fictif', 'trop lointain', 'Tâche fictive terminée', 'example.test/favorite'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, $forbidden);
        }
        // Un autre compte ne reçoit rien de celui-ci ; l'enseignant reçoit le cours qu'il donne ; l'administration ne reçoit aucune donnée de membres.
        $other = $this->snapshot('independant');
        $this->assertSame([[], [], [], []], [$other['planning'], $other['tasks'], $other['grades'], $other['opportunities']]);
        $this->assertSame(['Méthodologie fictive publiée'], array_column($this->snapshot('enseignant-a')['planning'], 'title'));
        $admin = json_encode($this->snapshot('admin-a'), JSON_UNESCAPED_UNICODE);
        foreach (['Apprenant fictif', 'MAT-001', 'Révision fictive personnelle'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $admin);
        }

        // Droits réévalués au retour en ligne : appartenance retirée, fiche suspendue — l'instantané suivant ne contient plus ces données.
        $this->member('apprenant-double')->revoke();
        Opportunity::whereKey($favorite->id)->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->travel(1)->days();
        $after = $this->snapshot('apprenant-double');
        $this->assertSame(['Révision fictive personnelle'], array_column($after['planning'], 'title'));
        $this->assertSame([['Bourse fictive favorite', 'suspended', null]], array_map(fn (array $item) => [$item['title'], $item['state'], $item['deadline']], $after['opportunities']));
        $this->assertSame('2026-11-17T08:00:00+00:00', $after['generatedAt']);
        // La fenêtre avance avec le temps : le cours du 5 décembre y entre quand il est à moins de 14 jours.
        $this->travel(6)->days();
        $this->assertContains('Cours fictif trop lointain', array_column($this->snapshot('apprenant-double')['planning'], 'title'));
    }
}
