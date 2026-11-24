<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CourseSeries;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Services\ActiveContext;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Lot 21 : accueil de prochaine action, parcours de démarrage, aide, états d'erreur. Données fictives uniquement.
 */
class HomeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Lundi 16 novembre 2026, 8 h à Abidjan.
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

    /**
     * @return array<string, mixed>|null
     */
    private function dashboard(string $name, ?string $letter = null): ?array
    {
        return $this->as($name, $letter)->get('/')->assertOk()->viewData('page')['props']['dashboard'];
    }

    /**
     * @param  list<array{key: string, done: bool}>  $steps
     * @return array<string, bool>
     */
    private function done(array $steps): array
    {
        return array_column($steps, 'done', 'key');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function course(string $owner, ?string $letter, array $overrides = []): CourseSeries
    {
        $data = array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif du jour', 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16',
            'start_time' => '10:00', 'end_time' => '11:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides);
        $this->as($owner, $letter)->post('/planning/series', $data)->assertSessionHasNoErrors();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail();
    }

    private function publish(): void
    {
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function opportunity(string $key, array $overrides): Opportunity
    {
        $membership = $this->user('editeur')->memberships()->where('scope', 'platform')->where('role', 'catalogue_editor')->firstOrFail();
        $editor = fn () => $this->freshDevice()->actingAs($this->user('editeur'))->withSession([ActiveContext::SESSION_KEY => $membership->id]);
        $id = $editor()->postJson('/administration/catalogue', array_replace(['category' => 'scholarship', 'title' => 'Fiche fictive '.$key, 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/'.$key,
            'application_url' => null, 'summary' => null, 'conditions' => 'Conditions fictives publiées par l’organisme fictif : être inscrit dans un établissement.', 'countries' => [], 'modality' => 'unspecified', 'levels' => [], 'fields' => [], 'languages' => [],
            'eligible_nationalities' => [], 'eligible_residences' => [], 'funding' => 'unspecified', 'fees' => 'unspecified', 'fees_detail' => null, 'deadline_kind' => 'unknown',
            'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null], $overrides))->assertOk()->json('id');
        $editor()->postJson('/administration/catalogue/'.$id.'/transition', ['action' => 'submit', 'expected_version' => 1])->assertOk();
        $editor()->postJson('/administration/catalogue/'.$id.'/transition', ['action' => 'verify', 'expected_version' => 2, 'attest' => true])->assertOk();

        return Opportunity::findOrFail($id);
    }

    public function test_a_visitor_gets_no_data_and_no_invented_figure_and_help_is_public(): void
    {
        $this->freshDevice()->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Home')->where('dashboard', null)->where('registrationEnabled', true)->where('auth.user', null));
        // Compte non vérifié ou suspendu : aucun bloc de données non plus.
        DB::table('users')->where('id', $this->user('polyvalent')->id)->update(['email_verified_at' => null]);
        $this->freshDevice()->actingAs($this->user('polyvalent'))->get('/')->assertInertia(fn (Assert $page) => $page->where('dashboard', null));

        // Aide publique. Sans adresse configurée, aucun contact n'est inventé.
        $this->freshDevice()->get('/aide')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help')->where('supportEmail', null)->where('offlineTtlDays', 7));
        config(['campus.support.email' => 'pas-une-adresse']);
        $this->freshDevice()->get('/aide')->assertInertia(fn (Assert $page) => $page->where('supportEmail', null));
        config(['campus.support.email' => 'support@example.test']);
        $this->as('independant')->get('/aide')->assertOk()->assertInertia(fn (Assert $page) => $page->where('supportEmail', 'support@example.test'));
    }

    public function test_the_individual_start_path_follows_real_objects_step_by_step(): void
    {
        $start = $this->dashboard('independant');
        $this->assertSame(['account', 'timezone', 'subject', 'course', 'task', 'grade', 'opportunity'], array_column($start['individualSteps'], 'key'));
        $this->assertSame(['account' => true, 'timezone' => false, 'subject' => false, 'course' => false, 'task' => false, 'grade' => false, 'opportunity' => false], $this->done($start['individualSteps']));
        // Rien n'est inventé : aucun cours, aucune tâche, aucune moyenne, aucune suggestion.
        $this->assertSame([[], [], [], [], ['hasProfile' => false, 'items' => []], null],
            [$start['today'], $start['pendingCheckIns'], $start['upcomingTasks'], $start['grades'], collect($start['opportunities'])->only(['hasProfile', 'items'])->all(), $start['institutionSteps']]);
        foreach ($start['individualSteps'] as $step) {
            $this->assertNotSame('', $step['action']);
            $this->assertStringStartsWith('/', $step['href']);
        }

        // Profil minimal : le fuseau.
        $this->as('independant')->patch('/profil/fuseau', ['timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
        $this->freshDevice()->patch('/profil/fuseau', ['timezone' => 'Europe/Paris'])->assertRedirect('/connexion');
        $this->as('independant')->get('/profil')->assertInertia(fn (Assert $page) => $page->where('timezone.current', 'Africa/Abidjan')->where('timezone.confirmed', false));
        $this->as('independant')->patch('/profil/fuseau', ['timezone' => 'Africa/Abidjan'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Fuseau horaire enregistré : Africa/Abidjan.');
        $this->as('independant')->get('/profil')->assertInertia(fn (Assert $page) => $page->where('timezone.confirmed', true));
        // Matière, cours du jour, tâche, note : par les routes réelles des écrans.
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user('independant')->id, 'name' => 'Mathématiques fictives']);
        $this->course('independant', null);
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive de démarrage', 'due_at' => '2026-11-17T18:00'])->assertSessionHasNoErrors();
        $this->as('independant')->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Évaluation fictive', 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '14', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $middle = $this->dashboard('independant');
        $this->assertSame(['account' => true, 'timezone' => true, 'subject' => true, 'course' => true, 'task' => true, 'grade' => true, 'opportunity' => false], $this->done($middle['individualSteps']));

        // L'accueil relie les modules, avec la provenance de chaque ligne et la couverture de la moyenne.
        $this->assertSame([['Cours fictif du jour', 'personal', '2026-11-16T10:00:00+00:00', false]], array_map(fn (array $row) => [$row['title'], $row['provenance'], $row['startsAt'], $row['cancelled']], $middle['today']));
        $this->assertSame([['Tâche fictive de démarrage', false, false]], array_map(fn (array $row) => [$row['title'], $row['collective'], $row['late']], $middle['upcomingTasks']));
        $this->assertSame([['label' => 'Mes notes personnelles', 'provenance' => 'personal', 'period' => 'Sans période', 'average' => '14,00', 'counted' => 1, 'total' => 1, 'missingCoefficients' => 0, 'subjects' => [['name' => 'Mathématiques fictives', 'average' => '14,00']]]], $middle['grades']);
        $this->assertSame('Africa/Abidjan', $middle['timezone']);

        // Opportunité : un favori termine le parcours.
        $record = $this->opportunity('bourse', ['title' => 'Bourse fictive de démarrage']);
        $this->as('independant')->putJson('/opportunites/'.$record->id.'/favori')->assertOk();
        $this->assertNotContains(false, $this->done($this->dashboard('independant')['individualSteps']));

        // Un autre compte ne voit rien de tout cela.
        $other = $this->dashboard('apprenant-double');
        $this->assertSame([[], [], []], [$other['today'], $other['upcomingTasks'], $other['grades']]);
        $this->assertFalse($this->done($other['individualSteps'])['task']);
        $this->assertStringNotContainsString('fictive de démarrage', json_encode($other, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_day_follows_the_personal_time_zone_and_a_finished_course_asks_for_a_check_in(): void
    {
        // Cours de 6 h à 7 h (terminé à 8 h) et cours de demain.
        $this->course('independant', null, ['title' => 'Cours fictif terminé', 'start_time' => '06:00', 'end_time' => '07:00']);
        $this->course('independant', null, ['title' => 'Cours fictif de demain', 'valid_from' => '2026-11-17', 'valid_until' => '2026-11-17', 'weekdays' => [2], 'start_time' => '00:30', 'end_time' => '01:30']);
        $dashboard = $this->dashboard('independant');
        $this->assertSame(['Cours fictif terminé'], array_column($dashboard['today'], 'title'));
        $this->assertSame([['Cours fictif terminé', 'personal', false]], array_map(fn (array $row) => [$row['title'], $row['provenance'], $row['deferred']], $dashboard['pendingCheckIns']));
        $this->assertSame(['version' => 0, 'status' => null], $dashboard['today'][0]['checkIn']);

        // À New York il est 3 h le 16 : la journée va de 5 h UTC le 16 à 5 h UTC le 17, et contient les deux cours.
        $this->as('independant')->patch('/profil/fuseau', ['timezone' => 'America/New_York'])->assertSessionHasNoErrors();
        $this->assertSame([['Cours fictif terminé', 'Cours fictif de demain'], 'America/New_York'], [array_column($this->dashboard('independant')['today'], 'title'), $this->dashboard('independant')['timezone']]);
        // À Denver il est 1 h le 16 : la journée commence à 7 h UTC. Le cours de 6 h UTC y était la veille au soir ; celui de 0 h 30 UTC le 17 y est ce soir.
        $this->as('independant')->patch('/profil/fuseau', ['timezone' => 'America/Denver'])->assertSessionHasNoErrors();
        $this->assertSame(['Cours fictif de demain'], array_column($this->dashboard('independant')['today'], 'title'));
    }

    public function test_opportunities_are_suggested_only_from_declared_criteria(): void
    {
        $match = $this->opportunity('physique', ['title' => 'Bourse fictive de physique', 'levels' => ['master'], 'fields' => ['Physique']]);
        $this->opportunity('droit', ['title' => 'Bourse fictive de droit', 'levels' => ['bachelor'], 'fields' => ['Droit']]);
        // Sans critères déclarés : aucune suggestion, même si des fiches existent.
        $this->assertSame(['hasProfile' => false, 'items' => []], collect($this->dashboard('independant')['opportunities'])->only(['hasProfile', 'items'])->all());

        $this->as('independant')->putJson('/opportunites/profil', ['expected_version' => 0, 'level' => 'master', 'fields' => ['Physique'], 'languages' => [], 'residence_country' => null, 'nationality' => null])->assertOk();
        $suggested = $this->dashboard('independant')['opportunities'];
        $this->assertTrue($suggested['hasProfile']);
        $this->assertSame([[$match->id, 'Bourse fictive de physique', '2 critère(s) déclaré(s) correspondent ; les autres sont à vérifier sur la fiche']], array_map(fn (array $item) => [$item['id'], $item['title'], $item['matchLabel']], $suggested['items']));
        $this->assertStringContainsString('Ce n’est pas une décision d’éligibilité', $suggested['disclaimer']);
        // Une fiche suspendue disparaît des suggestions.
        Opportunity::whereKey($match->id)->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->assertSame([], $this->dashboard('independant')['opportunities']['items']);
    }

    public function test_the_institution_start_path_is_shown_to_its_administration_only_and_stays_inside_its_perimeter(): void
    {
        // Ni l'espace individuel, ni un apprenant, ni un enseignant ne reçoivent le parcours d'administration.
        $this->assertNull($this->dashboard('admin-a')['institutionSteps']);
        $this->assertNull($this->dashboard('apprenant-double', 'A')['institutionSteps']);
        $this->assertNull($this->dashboard('enseignant-a', 'A')['institutionSteps']);

        $start = $this->dashboard('admin-a', 'A')['institutionSteps'];
        $this->assertSame(['references', 'import', 'publication', 'invitation', 'planning', 'report', 'decision'], array_column($start, 'key'));
        $this->assertSame(['references' => true, 'import' => false, 'publication' => false, 'invitation' => false, 'planning' => false, 'report' => false, 'decision' => false], $this->done($start));

        // Publication d'un cours hebdomadaire de A-G1, puis signalement de l'enseignant, puis décision.
        $teacher = $this->member('enseignant-a');
        $series = $this->course('admin-a', 'A', ['title' => 'Méthodologie fictive publiée', 'kind' => 'weekly', 'valid_until' => '2026-12-31', 'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id],
            'subject_id' => Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $teacher->id]);
        $this->assertFalse($this->done($this->dashboard('admin-a', 'A')['institutionSteps'])['publication']);
        $this->publish();
        $this->assertSame(['publication' => true, 'planning' => true, 'report' => false], collect($this->done($this->dashboard('admin-a', 'A')['institutionSteps']))->only(['publication', 'planning', 'report'])->all());

        $next = $series->occurrences()->where('original_date', '2026-11-23')->firstOrFail();
        $this->as('enseignant-a', 'A')->post('/planning/occurrences/'.$next->id.'/indisponibilite', ['reason' => 'Formation fictive ce jour-là'])->assertSessionHasNoErrors();
        $this->assertSame(['report' => true, 'decision' => false], collect($this->done($this->dashboard('admin-a', 'A')['institutionSteps']))->only(['report', 'decision'])->all());
        $this->as('admin-a', 'A')->post('/planning/indisponibilites/'.TeacherUnavailability::sole()->id.'/decision', ['decision' => 'decline'])->assertSessionHasNoErrors();
        $this->assertTrue($this->done($this->dashboard('admin-a', 'A')['institutionSteps'])['decision']);

        // Le cours publié apparaît chez l'apprenant et chez l'enseignant avec la provenance « établissement », jamais chez un tiers.
        $this->assertSame([['Méthodologie fictive publiée', 'institutional']], array_map(fn (array $row) => [$row['title'], $row['provenance']], $this->dashboard('apprenant-double')['today']));
        $this->assertSame(['Méthodologie fictive publiée'], array_column($this->dashboard('enseignant-a')['today'], 'title'));
        $this->assertSame([], $this->dashboard('independant')['today']);

        // L'établissement B ne voit rien de A : son parcours ne bouge pas, et il ne contient que des indicateurs.
        $other = $this->dashboard('admin-b', 'B');
        $this->assertSame(['import' => false, 'publication' => false, 'invitation' => false, 'planning' => false, 'report' => false, 'decision' => false], collect($this->done($other['institutionSteps']))->except('references')->all());
        $this->assertStringNotContainsString('Méthodologie', json_encode($other, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Formation fictive', json_encode($this->dashboard('admin-a', 'A'), JSON_UNESCAPED_UNICODE));
    }

    public function test_errors_always_offer_a_way_forward(): void
    {
        // Page inconnue, accès interdit : page d'erreur de Campus, avec le bon code.
        $this->as('independant')->get('/page-fictive-inexistante')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
        $this->freshDevice()->get('/page-fictive-inexistante')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
        $this->as('apprenant-double', 'A')->get('/administration/etablissement')->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
        // Une demande de données reste en JSON, avec un code lisible par l'appareil.
        $this->as('independant')->getJson('/page-fictive-inexistante')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        // Session fermée : retour à la connexion, pas une page blanche.
        $this->freshDevice()->get('/taches')->assertRedirect('/connexion');

        // Action refusée sur un écran qui n'affiche pas lui-même l'erreur : retour à l'écran, avec une explication.
        $foreign = $this->member('admin-b', 'B')->id;
        $this->as('independant')->from('/profil')->withHeaders(['X-Inertia' => 'true'])->post('/contexte', ['membership_id' => $foreign])
            ->assertRedirect('/profil')->assertSessionHas('problem', 'Cette action n’est pas autorisée, ou l’élément n’existe plus. Rien n’a été modifié.');
        $this->flushHeaders();
        $this->as('independant')->withSession(['problem' => 'Message fictif'])->get('/profil')->assertInertia(fn (Assert $page) => $page->where('flash.problem', 'Message fictif'));
        // Les écrans qui affichent l'erreur près du formulaire gardent leur comportement.
        $this->as('independant')->from('/taches')->withHeaders(['X-Inertia' => 'true'])->patch('/taches/'.Str::uuid(), ['expected_version' => 1, 'title' => 'x', 'priority' => 'normal', 'status' => 'todo'])
            ->assertRedirect('/taches')->assertSessionHasErrors('reference');
        $this->flushHeaders();
    }

    public function test_the_fixture_access_command_refuses_anything_but_the_local_development_database(): void
    {
        $before = User::orderBy('id')->pluck('password')->all();
        // Environnement de test (et, à plus forte raison, production) : refus, aucun mot de passe modifié, rien d'affiché d'autre que le refus.
        $this->artisan('campus:fixtures:access', ['--lock' => true])->expectsOutput('Commande réservée à l’environnement local et à la base de développement fictive.')->assertFailed();
        $this->app['env'] = 'production';
        $this->artisan('campus:fixtures:access', ['--password-file' => base_path('composer.json')])->assertFailed();
        $this->app['env'] = 'local';
        // Environnement local, mais ce n'est pas la base de développement fictive : refus aussi.
        $this->artisan('campus:fixtures:access', ['--lock' => true])->assertFailed();
        $this->app['env'] = 'testing';
        $this->assertSame($before, User::orderBy('id')->pluck('password')->all());
    }

    public function test_switching_context_keeps_the_person_on_a_common_screen_and_never_follows_a_received_address(): void
    {
        $a = $this->member('apprenant-double', 'A');
        $host = 'http://'.parse_url((string) config('app.url'), PHP_URL_HOST);
        // Depuis un écran commun : la personne y reste, sans les paramètres de l'adresse d'origine.
        $this->as('apprenant-double')->from($host.'/')->post('/contexte', ['membership_id' => $a->id])->assertRedirect('/');
        $this->from($host.'/planning?date=2026-11-16&view=day')->post('/contexte', ['membership_id' => null])->assertRedirect('/planning');
        // Écran propre à un contexte, adresse inconnue ou autre site : retour au profil, jamais à l'adresse reçue.
        $this->from($host.'/administration/etablissement')->post('/contexte', ['membership_id' => $a->id])->assertRedirect('/profil');
        $this->from($host.'/adresse-inconnue')->post('/contexte', ['membership_id' => null])->assertRedirect('/profil');
        $this->from('https://ailleurs.example.test/')->post('/contexte', ['membership_id' => $a->id])->assertRedirect('/profil');
        // Le contrôle du choix ne change pas : une appartenance étrangère reste introuvable.
        $this->from($host.'/')->post('/contexte', ['membership_id' => $this->member('admin-a')->id])->assertNotFound();
        // Nom court des pastilles : le nom de l'établissement, sans rôle tant qu'il n'y a pas d'homonyme.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.contexts.0.short', 'Établissement fictif A')->where('campus.quickAddAvailable', true)->missing('quickAdd'));
    }

    public function test_quick_add_data_is_loaded_on_demand_for_the_account_only_and_a_task_ticked_on_the_home_stays_there(): void
    {
        $host = 'http://'.parse_url((string) config('app.url'), PHP_URL_HOST);
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user('independant')->id, 'name' => 'Mathématiques fictives']);
        PersonalSubject::factory()->create(['owner_id' => $this->user('apprenant-double')->id, 'name' => 'Matière fictive d’un autre compte']);
        // Chargé seulement à la demande, avec les seules matières du compte ; un visiteur n'en reçoit pas.
        $quick = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'quickAdd'];
        $this->as('independant')->get('/', $quick())->assertOk()->assertJsonPath('props.quickAdd.individual', true)->assertJsonPath('props.quickAdd.timezone', 'Africa/Abidjan')
            ->assertJsonPath('props.quickAdd.today', '2026-11-16')->assertJsonPath('props.quickAdd.subjects', [['id' => $subject->id, 'name' => 'Mathématiques fictives']]);
        $this->as('apprenant-double', 'A')->get('/', $quick())->assertOk()->assertJsonPath('props.quickAdd.individual', false);
        $this->freshDevice()->get('/', $quick())->assertOk()->assertJsonPath('props.quickAdd', null);

        // Tâche ajoutée puis cochée depuis l'accueil : mêmes routes et mêmes contrôles que l'écran des tâches, et la personne reste sur l'accueil.
        $this->as('independant')->from($host.'/')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive de l’accueil', 'due_at' => '2026-11-16T18:00'])->assertRedirect('/');
        $task = $this->dashboard('independant')['upcomingTasks'][0];
        $this->assertSame(['Tâche fictive de l’accueil', false, 1, '2026-11-16T18:00', 'normal', null], [$task['title'], $task['collective'], $task['version'], $task['dueLocal'], $task['priority'], $task['subject']]);
        $done = ['title' => $task['title'], 'description' => $task['description'], 'due_at' => $task['dueLocal'], 'priority' => $task['priority'], 'personal_subject_id' => $task['personalSubjectId'], 'status' => 'done'];
        $this->as('apprenant-double')->from($host.'/')->patch('/taches/'.$task['id'], ['expected_version' => $task['version'], ...$done])->assertNotFound();
        $this->as('independant')->from($host.'/')->patch('/taches/'.$task['id'], ['expected_version' => $task['version'], ...$done])->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertSame([], $this->dashboard('independant')['upcomingTasks']);
        // Depuis l'écran des tâches, rien ne change : retour à la liste.
        $this->as('independant')->from($host.'/taches')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Autre tâche fictive'])->assertRedirect('/taches');
    }

    public function test_no_dead_route_and_no_placeholder_screen_remain(): void
    {
        // L'écran « à construire » a disparu, avec sa route de démonstration.
        $this->assertFileDoesNotExist(resource_path('js/pages/ModulePending.tsx'));
        $pages = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringNotContainsString('ModulePending', $pages);
        // Chaque écran rendu par le serveur existe, et chaque écran déclaré est rendu quelque part.
        preg_match_all("/^\s+'?([A-Za-z\/]+)'?: \(\) => import\('\.\/pages\/([A-Za-z\/]+)'\)/m", $pages, $declared);
        $this->assertSame($declared[1], $declared[2]);
        $php = '';
        foreach ([...glob(app_path('Http/Controllers/*.php')), ...glob(app_path('Http/Controllers/*/*.php')), base_path('bootstrap/app.php'), base_path('routes/web.php')] as $file) {
            $php .= file_get_contents($file);
        }
        foreach ($declared[1] as $name) {
            $this->assertFileExists(resource_path('js/pages/'.$name.'.tsx'));
            $this->assertMatchesRegularExpression("/Inertia::render\(\s*'".preg_quote($name, '/')."'/", $php, 'Écran jamais rendu : '.$name);
        }
        preg_match_all("/Inertia::render\(\s*'([A-Za-z\/]+)'/", $php, $rendered);
        $this->assertSame([], array_values(array_diff(array_unique($rendered[1]), $declared[1])), 'Écran rendu mais absent de l’application');

        // Chaque lien fixe écrit dans les écrans mène à une vraie route.
        $links = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (preg_match_all('/href(?:=|: )["\'](\/[a-z0-9\/-]*)(?:[?#][^"\']*)?["\']/', (string) file_get_contents($file->getPathname()), $found)) {
                $links = [...$links, ...$found[1]];
            }
        }
        $links = array_values(array_unique($links));
        $this->assertGreaterThan(20, count($links));
        foreach ($links as $path) {
            try {
                Route::getRoutes()->match(Request::create($path));
            } catch (NotFoundHttpException) {
                $this->fail('Lien mort dans un écran : '.$path);
            }
        }
        // Textes d'attente retirés de l'accueil : plus de module « en construction » ni de promesse « bientôt ».
        $home = (string) file_get_contents(resource_path('js/pages/Home.tsx'));
        foreach (['En construction', 'Bientôt disponible', 'Campus se prépare', 'en préparation'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $home);
        }
    }
}
