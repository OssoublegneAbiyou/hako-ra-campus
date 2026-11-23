<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\DataExport;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\Task;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\GoogleSignIn;
use App\Services\OfflineSnapshot;
use App\Support\BackupCipher;
use App\Support\OperationFingerprint;
use Database\Seeders\CampusDemoSeeder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Lot 23 : tests de régression de la revue de sécurité. Un test par risque important. Données fictives uniquement.
 */
class HardeningTest extends TestCase
{
    use DatabaseTransactions;

    // Sans accent ni caractère spécial : la recherche de ce texte vaut aussi bien dans une page que dans une réponse JSON, où les accents sont échappés.
    private const PRIVATE_TEXT = 'Contenu-prive-fictif-du-lot-23';

    private const SCRIPT = '<script>window.__xss=1</script><img src=x onerror="window.__xss=1">';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        Storage::fake('local');
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
        $membership = match (true) {
            $letter === 'P' => $this->user($name)->memberships()->where('scope', 'platform')->firstOrFail()->id,
            $letter !== null => $this->member($name, $letter)->id,
            default => null,
        };

        return $this->freshDevice()->actingAs($this->user($name))->withSession([...($membership ? [ActiveContext::SESSION_KEY => $membership] : []), 'auth.password_confirmed_at' => now()->unix()]);
    }

    private function task(string $owner, string $title): Task
    {
        $key = (string) Str::uuid();
        $this->as($owner)->post('/taches', ['client_key' => $key, 'title' => $title, 'description' => self::PRIVATE_TEXT])->assertSessionHasNoErrors();

        return Task::where('client_key', $key)->firstOrFail();
    }

    public function test_every_response_carries_the_security_headers_and_a_content_policy_limited_to_the_site(): void
    {
        $expected = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Content-Security-Policy' => SecurityHeaders::POLICY];
        $responses = [
            'page publique' => $this->freshDevice()->get('/'),
            'page connectée' => $this->as('independant')->get('/taches'),
            'données JSON' => $this->as('independant')->getJson('/hors-ligne/instantane'),
            'page introuvable' => $this->freshDevice()->get('/page-fictive-inexistante'),
            'redirection' => $this->freshDevice()->get('/taches'),
            'refus JSON' => $this->freshDevice()->postJson('/synchronisation/v1/operations', []),
        ];
        foreach ($responses as $label => $response) {
            foreach ($expected as $header => $value) {
                $this->assertSame($value, $response->headers->get($header), $label.' : '.$header);
            }
            $this->assertNull($response->headers->get('Strict-Transport-Security'), $label);
        }
        // La politique n'autorise ni script en ligne, ni origine tierce, ni cadre, ni objet.
        foreach (["script-src 'self';", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "connect-src 'self'"] as $rule) {
            $this->assertStringContainsString($rule, SecurityHeaders::POLICY);
        }
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*('unsafe-inline'|'unsafe-eval'|\*|https?:)/", SecurityHeaders::POLICY);
        // HTTPS imposé au navigateur seulement sur une requête déjà sécurisée.
        $this->assertSame('max-age=31536000; includeSubDomains', $this->freshDevice()->get('https://localhost/')->headers->get('Strict-Transport-Security'));

        // Un fichier téléchargé garde sa propre politique, plus stricte : rien ne s'y exécute.
        $task = $this->task('independant', 'Tâche fictive avec fichier');
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        $this->as('independant')->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('photo-fictive.png', (string) ob_get_clean())])->assertSessionHasNoErrors();
        DB::table('attachments')->update(['scan_status' => 'clean']);
        $download = $this->as('independant')->get('/pieces-jointes/'.DB::table('attachments')->value('id'))->assertOk();
        $this->assertSame(["default-src 'none'; sandbox", 'nosniff', 'DENY'], [$download->headers->get('Content-Security-Policy'), $download->headers->get('X-Content-Type-Options'), $download->headers->get('X-Frame-Options')]);
    }

    public function test_a_forged_host_header_is_refused_outside_local_and_test_environments(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://campus.example.test']);
        try {
            $this->freshDevice()->get('https://campus.example.test/connexion')->assertOk();
            // Nom de site forgé : la requête est refusée avant toute route, donc avant toute génération de lien.
            $this->freshDevice()->get('https://attaquant.example.test/connexion')->assertStatus(400);
            $this->freshDevice()->get('https://campus.example.test.attaquant.example.test/connexion')->assertStatus(400);
            $this->freshDevice()->get('https://sous-domaine.campus.example.test/connexion')->assertStatus(400);
        } finally {
            $this->app['env'] = 'testing';
            // Réglage statique du cadre : remis à zéro pour ne pas déborder sur les autres tests.
            Request::setTrustedHosts([]);
        }
    }

    public function test_each_rate_limit_counts_for_its_own_route_and_its_own_account(): void
    {
        // Soixante créations de tâches par minute : la soixante et unième est refusée…
        foreach (range(1, 60) as $index) {
            $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive '.$index])->assertSessionHasNoErrors();
        }
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive de trop'])->assertStatus(429);
        $this->assertSame(60, Task::count());
        // … sans bloquer les autres actions du même compte, même à plafond plus bas (défaut A43 : le compteur était commun à toutes les routes).
        $this->as('independant')->patch('/profil/fuseau', ['timezone' => 'Africa/Abidjan'])->assertSessionHasNoErrors()->assertStatus(302);
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'objection', 'message' => 'Demande fictive après de nombreuses actions.'])->assertSessionHasNoErrors();
        $this->as('independant')->delete('/confidentialite/compte', ['password' => 'mauvais', 'confirmation' => 'SUPPRIMER'])->assertSessionHasErrors('password');
        $this->as('independant')->getJson('/hors-ligne/instantane')->assertOk();
        // Ni un autre compte.
        $this->as('apprenant-double')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive d’un autre compte'])->assertSessionHasNoErrors();
        // La minute suivante, le compteur repart.
        $this->travel(61)->seconds();
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive, minute suivante'])->assertSessionHasNoErrors();
    }

    public function test_only_http_and_https_addresses_are_accepted_and_nothing_is_fetched(): void
    {
        $course = fn (string $url) => ['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif en ligne', 'kind' => 'once', 'valid_from' => '2026-11-18', 'valid_until' => '2026-11-18', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Africa/Abidjan', 'weekdays' => [3], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => [], 'meeting_url' => $url];
        foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'ftp://example.test/cours', '//example.test/cours', 'example.test/cours'] as $bad) {
            $this->as('independant')->post('/planning/series', $course($bad))->assertSessionHasErrors('meeting_url');
        }
        $this->as('independant')->post('/planning/series', $course('https://example.test/cours-fictif'))->assertSessionHasNoErrors();
        $this->assertSame(['https://example.test/cours-fictif'], DB::table('course_occurrences')->pluck('meeting_url')->all());

        // Fiche d'opportunité : mêmes schémas, sans identifiants dans l'adresse ; l'adresse n'est jamais appelée par le serveur.
        $payload = fn (string $url) => ['category' => 'scholarship', 'title' => 'Fiche fictive', 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => $url, 'application_url' => null, 'summary' => null,
            'conditions' => 'Conditions fictives publiées par l’organisme fictif : être inscrit dans un établissement.', 'countries' => [], 'modality' => 'unspecified', 'levels' => [], 'fields' => [], 'languages' => [], 'eligible_nationalities' => [],
            'eligible_residences' => [], 'funding' => 'unspecified', 'fees' => 'unspecified', 'fees_detail' => null, 'deadline_kind' => 'unknown', 'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null];
        foreach (['javascript:alert(1)', 'data:text/html,x', 'https://utilisateur:secret@example.test/', 'http://', 'https://example.test/<script>'] as $bad) {
            $this->as('editeur', 'P')->postJson('/administration/catalogue', $payload($bad))->assertStatus(422);
        }
        $this->as('editeur', 'P')->postJson('/administration/catalogue', $payload('https://example.test/fiche-fictive'))->assertOk();
        // Aucun client HTTP sortant n'existe dans le code de l'application pour ces adresses.
        $code = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code .= $file->getExtension() === 'php' && ! str_contains($file->getPathname(), 'WebPush') && $file->getFilename() !== 'GoogleSignIn.php' ? file_get_contents($file->getPathname()) : '';
        }
        // Seule exception, décidée par le porteur : la connexion par Google. Elle n'appelle qu'une adresse fixe de Google, écrite dans le code,
        // sans suivre de redirection, et jamais une adresse venue d'une personne ou de la réponse de Google.
        $google = (string) file_get_contents(app_path('Services/GoogleSignIn.php'));
        $this->assertSame(1, substr_count($google, 'Http::'));
        $this->assertStringContainsString('->withoutRedirecting()->post(self::TOKEN_ENDPOINT,', $google);
        $this->assertSame('https://oauth2.googleapis.com/token', GoogleSignIn::TOKEN_ENDPOINT);
        foreach (['curl_init', 'file_get_contents(', 'fopen(', 'get_headers(', '->get('] as $other) {
            $this->assertStringNotContainsString($other, $google, $other);
        }
        foreach (['Http::', 'curl_init', 'file_get_contents($url', 'file_get_contents($data', 'fopen($url', 'GuzzleHttp', 'get_headers('] as $client) {
            $this->assertStringNotContainsString($client, $code, $client);
        }
    }

    public function test_markup_typed_by_a_user_is_stored_as_text_and_never_emitted_as_html(): void
    {
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user('independant')->id, 'name' => 'Matière '.self::SCRIPT]);
        $task = $this->task('independant', 'Tâche '.self::SCRIPT);
        $this->as('independant')->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Note '.self::SCRIPT, 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '12', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->as('independant')->patch('/profil', ['name' => 'Nom '.self::SCRIPT])->assertSessionHasNoErrors();
        // Enregistré tel quel, comme du texte.
        $this->assertSame('Tâche '.self::SCRIPT, $task->fresh()->title);
        foreach (['/', '/taches', '/notes', '/profil'] as $url) {
            $html = $this->as('independant')->get($url)->assertOk()->getContent();
            // Dans la page, les données sont dans un bloc JSON dont les chevrons sont échappés : aucune balise saisie n'y figure telle quelle.
            $this->assertStringNotContainsString('<script>window.__xss', $html, $url);
            $this->assertStringNotContainsString('<img src=x onerror', $html, $url);
            $this->assertStringContainsString('window.__xss=1', $html, $url);
        }
        // Séquence qui, dans un bloc de script, fait avaler la suite du document par le navigateur : elle doit rester une simple donnée.
        // Vérifié avec un analyseur HTML5 : le bloc se ferme, le conteneur de l'application existe, les données se relisent à l'identique.
        $breakout = 'Tâche x<!--<script>y</script><!-- z';
        $this->task('independant', $breakout);
        $html = $this->as('independant')->get('/taches')->assertOk()->getContent();
        $this->assertStringNotContainsString('<!--<script>', $html);
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertNotNull($document->querySelector('#app'));
        $this->assertSame(1, $document->querySelectorAll('script[data-page]')->length);
        $page = json_decode($document->querySelector('script[data-page="app"]')->textContent, true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains($breakout, [...array_column($page['props']['tasks'], 'title'), ...array_column($page['props']['undated'], 'title')]);
        $this->assertSame('Nom '.self::SCRIPT, $page['props']['auth']['user']['name']);
        // Les réponses de données sont du JSON typé, jamais du HTML interprétable.
        $json = $this->as('independant')->getJson('/hors-ligne/instantane')->assertOk();
        $this->assertStringStartsWith('application/json', (string) $json->headers->get('Content-Type'));
        $this->assertSame('nosniff', $json->headers->get('X-Content-Type-Options'));
        // Aucun écran n'insère de HTML fourni par les données.
        $screens = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $screens .= file_get_contents($file->getPathname());
        }
        foreach (['dangerouslySetInnerHTML', '.innerHTML', 'document.write', 'eval('] as $sink) {
            $this->assertStringNotContainsString($sink, $screens.file_get_contents(public_path('offline.js')).file_get_contents(public_path('offline-core.js')), $sink);
        }
    }

    public function test_r58_no_other_account_not_even_the_platform_administration_reaches_private_data_by_any_direct_address(): void
    {
        $owner = $this->user('independant');
        $task = $this->task('independant', 'Tâche '.self::PRIVATE_TEXT);
        $subject = PersonalSubject::factory()->create(['owner_id' => $owner->id, 'name' => 'Matière '.self::PRIVATE_TEXT]);
        $this->as('independant')->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Note '.self::PRIVATE_TEXT, 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '12', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $grade = DB::table('personal_grades')->value('id');
        $this->as('independant')->post('/planning/series', ['request_key' => (string) Str::uuid(), 'title' => 'Cours '.self::PRIVATE_TEXT, 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16', 'start_time' => '06:00', 'end_time' => '07:00',
            'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []])->assertSessionHasNoErrors();
        $occurrence = DB::table('course_occurrences')->where('owner_id', $owner->id)->value('id');
        $this->as('independant')->put('/planning/occurrences/'.$occurrence.'/retour', ['action' => 'save', 'expected_version' => 0, 'summary' => 'Retour '.self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        $this->as('independant')->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('fichier-prive-fictif.png', (string) ob_get_clean())])->assertSessionHasNoErrors();
        DB::table('attachments')->update(['scan_status' => 'clean']);
        $attachment = DB::table('attachments')->value('id');
        $this->as('independant')->post('/confidentialite/exports')->assertSessionHasNoErrors();
        $export = DataExport::sole();
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'rectification', 'message' => 'Demande '.self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        $request = DB::table('privacy_requests')->where('type', 'rectification')->value('id');

        $intruders = [['apprenant-double', null], ['apprenant-double', 'A'], ['enseignant-a', 'A'], ['admin-a', 'A'], ['admin-b', 'B'], ['editeur', 'P'], ['plateforme', 'P']];
        foreach ($intruders as [$name, $context]) {
            $label = $name.'/'.($context ?? 'individuel');
            // Lecture directe : introuvable, sans indice d'existence.
            foreach (['/planning/occurrences/'.$occurrence.'/retour', '/planning/occurrences/'.$occurrence.'/historique', '/pieces-jointes/'.$attachment, '/confidentialite/exports/'.$export->id] as $url) {
                $this->as($name, $context)->get($url)->assertNotFound();
            }
            // Écriture directe : introuvable, et rien ne change.
            $this->as($name, $context)->patch('/taches/'.$task->id, ['expected_version' => 1, 'title' => 'Intrusion fictive', 'priority' => 'normal', 'status' => 'done'])->assertNotFound();
            $this->as($name, $context)->delete('/taches/'.$task->id, ['expected_version' => 1])->assertNotFound();
            // Suppression d'une note : réponse identique que la note existe ou non (suppression rejouable), et la note d'autrui reste en place.
            $this->as($name, $context)->deleteJson('/notes/'.$grade, ['expected_version' => 1])->assertOk()->assertJsonPath('replayed', true);
            $this->assertSame($this->as($name, $context)->deleteJson('/notes/'.Str::uuid(), ['expected_version' => 1])->json('replayed'), true);
            $this->assertSame(1, DB::table('personal_grades')->where('id', $grade)->count(), $label);
            $this->as($name, $context)->put('/planning/occurrences/'.$occurrence.'/retour', ['action' => 'save', 'expected_version' => 1, 'summary' => 'Intrusion fictive'])->assertNotFound();
            $this->as($name, $context)->delete('/pieces-jointes/'.$attachment)->assertNotFound();
            $this->as($name, $context)->post('/confidentialite/demandes/'.$request.'/annulation')->assertNotFound();
            // Synchronisation : l'opération d'un autre compte sur cette tâche est refusée, sans rien révéler.
            $payload = ['title' => 'Intrusion fictive', 'description' => null, 'due_at' => null, 'priority' => 'normal', 'personal_subject_id' => null, 'status' => 'done'];
            $this->as($name, $context)->postJson('/synchronisation/v1/operations', ['operation_id' => (string) Str::uuid(), 'account_key' => OfflineSnapshot::accountKey($this->user($name)), 'context' => 'personal', 'type' => 'task.update',
                'target_id' => $task->id, 'base_version' => 1, 'fingerprint' => OperationFingerprint::of('task.update', $task->id, 1, $payload), 'payload' => $payload])->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE')->assertJsonPath('current', null);
            // Aucune de ses pages ne contient le contenu privé : ni l'accueil, ni les listes, ni la copie hors connexion, ni l'export.
            foreach (['/', '/taches', '/notes', '/retours', '/planning?view=day&date=2026-11-16', '/hors-ligne/instantane', '/notifications'] as $url) {
                $this->assertStringNotContainsString(self::PRIVATE_TEXT, $this->as($name, $context)->get($url)->assertOk()->getContent(), $label.' '.$url);
            }
        }
        // Les écrans d'administration non plus : établissement, plateforme, catalogue, signalements de fichiers.
        foreach ([['admin-a', 'A', '/administration/etablissement'], ['admin-a', 'A', '/pieces-jointes/signalements'], ['admin-a', 'A', '/administration/etablissement/membres/export'], ['plateforme', 'P', '/administration/plateforme'], ['editeur', 'P', '/administration/catalogue']] as [$name, $context, $url]) {
            $response = $this->as($name, $context)->get($url)->assertOk();
            $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
            $this->assertStringNotContainsString(self::PRIVATE_TEXT, $content, $url);
            $this->assertStringNotContainsString('Indépendant fictif', $content, $url);
        }
        // La plateforme lit la demande qui lui est adressée, et seulement elle.
        $queue = $this->as('plateforme', 'P')->get('/administration/plateforme/demandes')->assertOk()->getContent();
        $this->assertSame(1, substr_count($queue, self::PRIVATE_TEXT));
        // Rien n'a changé.
        $this->assertSame(['Tâche '.self::PRIVATE_TEXT, 1, 'todo'], [$task->fresh()->title, $task->fresh()->lock_version, $task->fresh()->status]);
        $this->assertSame([1, 1, 1], [DB::table('personal_grades')->count(), DB::table('course_check_ins')->where('summary', 'Retour '.self::PRIVATE_TEXT)->count(), DB::table('attachments')->whereNull('deleted_at')->count()]);
    }

    public function test_r09_no_role_can_be_raised_by_its_holder_or_by_an_institution_administration(): void
    {
        // Une administration d'établissement ne peut ni se donner, ni donner, un rôle d'administration, d'édition ou de plateforme.
        foreach (['institution_admin', 'platform_admin', 'catalogue_editor'] as $role) {
            $this->as('admin-a', 'A')->post('/administration/invitations', ['role' => $role])->assertStatus(403);
        }
        // Un apprenant ou un enseignant ne peut inviter personne.
        $this->as('apprenant-double', 'A')->post('/administration/invitations', ['role' => 'learner'])->assertForbidden();
        $this->as('enseignant-a', 'A')->post('/administration/invitations', ['role' => 'learner'])->assertForbidden();
        // Choisir comme contexte l'appartenance d'un autre : introuvable. Un numéro d'établissement envoyé par le client n'ouvre rien.
        $this->as('apprenant-double')->post('/contexte', ['membership_id' => $this->member('admin-a')->id])->assertNotFound();
        $this->as('apprenant-double', 'A')->get('/administration/etablissement?institution_id='.$this->member('admin-b', 'B')->institution_id)->assertForbidden();
        $this->as('admin-a', 'A')->get('/administration/etablissement?institution_id='.$this->member('admin-b', 'B')->institution_id)->assertOk()->assertDontSee('Établissement fictif B');
        $this->as('admin-a', 'A')->get('/administration/plateforme')->assertForbidden();
        $this->assertSame(0, DB::table('invitations')->count());
        $this->assertSame(['learner', 'learner'], $this->user('apprenant-double')->memberships()->pluck('role')->all());
    }

    public function test_every_write_route_is_inside_the_session_and_csrf_group_and_only_identity_entry_points_are_open_to_guests(): void
    {
        // « connexion/google/creation » : création de compte après une identification Google, avec la même déclaration que l'inscription.
        $open = ['connexion', 'inscription', 'mot-de-passe-oublie', 'reinitialisation', 'activation', 'deconnexion', 'connexion/google/creation'];
        $writes = 0;
        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === [] || str_starts_with($route->uri(), '_')) {
                continue;
            }
            $writes++;
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $route->uri());
            if (! in_array($route->uri(), $open, true)) {
                $this->assertContains('auth', $middleware, 'Route d’écriture ouverte sans session : '.$route->uri());
            }
        }
        $this->assertGreaterThan(80, $writes);
        // Aucune exception à la vérification CSRF n'est déclarée, et la déconnexion ne se fait pas par un simple lien.
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString('validateCsrfTokens', $bootstrap);
        $this->assertStringNotContainsString('preventRequestForgery(except', $bootstrap);
        $this->assertSame(['POST'], Route::getRoutes()->getByName('logout')?->methods() ?? collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'deconnexion')->methods());
        // Cookie de session : inaccessible aux scripts, limité au site, chiffré, réservé à HTTPS en production.
        $this->assertSame([true, 'lax'], [config('session.http_only'), config('session.same_site')]);
        $this->assertStringContainsString("env('APP_ENV', 'production') === 'production' ? true : null", (string) file_get_contents(config_path('session.php')));
    }

    public function test_the_pre_opening_check_reports_what_must_be_corrected_without_printing_any_value(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'campus.privacy.contact' => 'droits@example.test']);
        $this->artisan('campus:security:check')
            ->expectsOutputToContain('À CORRIGER  Environnement « production »')
            ->expectsOutputToContain('À CORRIGER  Dérogation sans second facteur désactivée')
            ->expectsOutputToContain('OK          Notice : contact pour les droits renseigné')
            ->doesntExpectOutputToContain('droits@example.test')->doesntExpectOutputToContain('base64:')
            ->assertFailed();

        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://campus.example.test', 'session.secure' => true, 'session.encrypt' => true, 'campus.identity.privileged_without_mfa' => false, 'mail.default' => 'smtp',
            'queue.default' => 'database', 'campus.attachments.scanner' => 'clamd', 'campus.privacy.controller' => 'Organisme fictif', 'campus.privacy.hosting_region' => 'Région fictive',
            'campus.backup.directory' => sys_get_temp_dir(), 'campus.backup.key' => BackupCipher::generateKey(), 'campus.monitoring.token' => str_repeat('j', 40)]);
        try {
            // Des comptes fictifs dans la base d'une ouverture : bloquant. Une fois absents, le contrôle passe.
            $this->artisan('campus:security:check')->expectsOutputToContain('À CORRIGER  Aucun compte fictif (@example.test) dans cette base')->assertFailed();
            DB::table('users')->update(['email' => DB::raw("REPLACE(email, '@example.test', '@campus-fictif.test')")]);
            $this->artisan('campus:security:check')->expectsOutputToContain('Réglages bloquants conformes. Ce contrôle ne vaut pas autorisation d’ouvrir.')
                ->expectsOutputToContain('À VÉRIFIER  Contact du support renseigné')->assertSuccessful();
            config(['app.debug' => true]);
            $this->artisan('campus:security:check')->expectsOutputToContain('À CORRIGER  Mode de débogage désactivé')->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_no_secret_reaches_the_client_side_code_or_the_shared_page_data(): void
    {
        // Données partagées avec chaque page : le nom, une clé opaque, rien d'autre sur le compte.
        $props = $this->as('independant')->get('/')->viewData('page')['props'];
        $this->assertSame(['user', 'accountKey'], array_keys($props['auth']));
        $this->assertSame(['name'], array_keys($props['auth']['user']));
        $page = json_encode($props);
        $user = $this->user('independant');
        foreach ([$user->password, (string) $user->remember_token ?: 'jeton-absent', 'independant@example.test', (string) config('app.key'), (string) config('database.connections.mysql.password') ?: 'mot-de-passe-absent'] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
        // Fichiers servis au navigateur : aucune clé, aucun mot de passe, aucune variable d'environnement du serveur.
        $files = [...glob(public_path('build/assets/*.js')), public_path('sw.js'), public_path('offline.js'), public_path('offline-core.js'), public_path('manifest.webmanifest')];
        $this->assertGreaterThan(4, count($files));
        $key = substr((string) config('app.key'), 7);
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            foreach (array_filter([$key, 'APP_KEY', 'DB_PASSWORD', 'VAPID_PRIVATE', 'MAIL_PASSWORD', 'BEGIN PRIVATE KEY']) as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content, basename($file).' : '.$forbidden);
            }
        }
    }
}
