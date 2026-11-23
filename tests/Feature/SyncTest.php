<?php

namespace Tests\Feature;

use App\Models\CourseCheckIn;
use App\Models\CourseCheckInEvent;
use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Subject;
use App\Models\SyncOperation;
use App\Models\Task;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\OfflineSnapshot;
use App\Support\OperationFingerprint;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lot 20 : synchronisation des brouillons personnels écrits hors connexion. Données fictives uniquement.
 */
class SyncTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/synchronisation/v1/operations';

    private const PRIVATE_TEXT = 'Brouillon privé fictif écrit sans réseau';

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

    /**
     * Opération telle qu'un appareil l'écrit : identifiant client, compte, contexte, type, empreinte et version connue.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function operation(string $owner, string $type, ?string $target, int $base, array $payload, ?string $id = null): array
    {
        return ['operation_id' => $id ?? (string) Str::uuid(), 'account_key' => OfflineSnapshot::accountKey($this->user($owner)), 'context' => 'personal', 'type' => $type,
            'target_id' => $target, 'base_version' => $base, 'fingerprint' => OperationFingerprint::of($type, $target, $base, $payload), 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function send(string $name, array $operation): TestResponse
    {
        return $this->as($name)->postJson(self::URL, $operation);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = [], bool $update = false): array
    {
        return [...['title' => 'Tâche fictive écrite hors connexion', 'description' => null, 'due_at' => null, 'priority' => 'normal', 'personal_subject_id' => null],
            ...($update ? ['status' => 'todo'] : ['source_occurrence_id' => null]), ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkInPayload(array $overrides = []): array
    {
        return [...['summary' => self::PRIVATE_TEXT, 'work_to_do' => null, 'points_to_review' => null, 'understanding' => null], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rules(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Révision fictive', 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16',
            'start_time' => '06:00', 'end_time' => '07:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides);
    }

    /** Événement personnel, déjà terminé à 8 h. */
    private function personalCourse(string $owner = 'independant'): CourseOccurrence
    {
        $data = $this->rules();
        $this->as($owner)->post('/planning/series', $data)->assertSessionHasNoErrors();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    /** Cours institutionnel de A-G1, le lundi 16 novembre de 6 h à 7 h, publié et déjà terminé. */
    private function publishedCourse(): CourseOccurrence
    {
        $teacher = $this->member('enseignant-a');
        $data = $this->rules(['title' => 'Méthodologie fictive publiée', 'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id],
            'subject_id' => Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $teacher->id]);
        $this->as('admin-a', 'A')->post('/planning/series', $data)->assertSessionHasNoErrors();
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    public function test_the_endpoint_is_versioned_session_bound_and_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('sync.v1.operations');
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('synchronisation/v1/operations', $route->uri());
        $middleware = $route->gatherMiddleware();
        // Groupe « web » : session et jeton CSRF ; puis compte connecté, vérifié et actif.
        foreach (['web', 'auth', 'verified'] as $expected) {
            $this->assertContains($expected, $middleware);
        }
        $operation = $this->operation('independant', 'task.create', null, 0, $this->taskPayload());
        $this->freshDevice()->postJson(self::URL, $operation)->assertUnauthorized();
        DB::table('users')->where('id', $this->user('independant')->id)->update(['status' => 'suspended']);
        $this->assertNotSame(200, $this->send('independant', $operation)->getStatusCode());
        $this->assertSame([0, 0], [Task::count(), SyncOperation::count()]);
    }

    public function test_the_fingerprint_is_the_same_canonical_form_as_on_the_device(): void
    {
        // Même contenu et même empreinte que dans tests/js/offline-core.test.mjs : les deux calculs doivent rester identiques.
        $payload = ['title' => 'Révision « fictive » d’été / n°1 😀', 'description' => "Ligne 1\nLigne 2\t\"citée\" \\ fin\u{2028}suite", 'due_at' => '2026-11-20T18:00', 'priority' => 'high',
            'personal_subject_id' => null, 'source_occurrence_id' => null];
        $this->assertSame('8af8d43334d47b3fa776767f4bc536df26e25532c3cd30fc7428b3371b878993', OperationFingerprint::of('task.create', null, 0, $payload));

        // Une empreinte qui ne correspond pas au contenu est refusée avant toute écriture.
        $operation = $this->operation('independant', 'task.create', null, 0, $payload);
        $this->send('independant', [...$operation, 'payload' => [...$payload, 'title' => 'Autre titre fictif']])->assertStatus(422)->assertJsonPath('errors.fingerprint.0', 'L’empreinte ne correspond pas au contenu envoyé.');
        $this->assertSame([0, 0], [Task::count(), SyncOperation::count()]);
        $this->send('independant', $operation)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame($payload['description'], Task::sole()->description);
    }

    public function test_r57_an_offline_creation_sent_several_times_creates_one_task_and_replays_the_same_acknowledgement(): void
    {
        $operation = $this->operation('independant', 'task.create', null, 0, $this->taskPayload(['description' => self::PRIVATE_TEXT, 'due_at' => '2026-11-20T18:00']));
        $first = $this->send('independant', $operation)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $task = Task::sole();
        $this->assertSame(['operation_id' => $operation['operation_id'], 'outcome' => 'applied', 'code' => 'APPLIED', 'message' => 'Enregistré.', 'replayed' => false,
            'result' => ['id' => $task->id, 'version' => 1, 'status' => 'todo'], 'current' => null, 'errors' => []], $first);
        $this->assertSame([$this->user('independant')->id, 'personal', $operation['operation_id'], '2026-11-20 18:00:00'], [$task->owner_id, $task->scope, $task->client_key, $task->due_at->format('Y-m-d H:i:s')]);

        // Réseau oscillant : l'accusé s'est perdu, l'appareil renvoie trois fois la même opération.
        foreach (range(1, 3) as $attempt) {
            $this->assertSame([...$first, 'replayed' => true], $this->send('independant', $operation)->assertOk()->json());
        }
        $this->assertSame([1, 1], [Task::count(), SyncOperation::count()]);

        // Même clé, autre contenu : conflit, jamais un nouvel objet, et le premier accusé reste intact.
        $other = $this->operation('independant', 'task.create', null, 0, $this->taskPayload(['title' => 'Autre contenu fictif']), $operation['operation_id']);
        $this->send('independant', $other)->assertStatus(409)->assertJsonPath('outcome', 'conflict')->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED')->assertJsonPath('result', null);
        $this->assertSame([1, 1, 'Tâche fictive écrite hors connexion'], [Task::count(), SyncOperation::count(), $task->fresh()->title]);
        $this->assertSame([...$first, 'replayed' => true], $this->send('independant', $operation)->json());

        // L'unicité est liée au compte : la même clé chez un autre compte est une autre opération, sur ses propres données.
        $foreign = $this->operation('apprenant-double', 'task.create', null, 0, $this->taskPayload(), $operation['operation_id']);
        $this->send('apprenant-double', $foreign)->assertOk()->assertJsonPath('replayed', false);
        $this->assertSame([2, 2], [Task::count(), SyncOperation::count()]);
        $this->assertSame(1, Task::where('owner_id', $this->user('apprenant-double')->id)->count());

        // Formulaire en ligne dont la réponse s'est perdue, puis reprise par la synchronisation avec la même clé : une seule tâche.
        $key = (string) Str::uuid();
        $this->as('independant')->post('/taches', ['client_key' => $key, 'title' => 'Formulaire fictif interrompu'])->assertSessionHasNoErrors();
        $this->send('independant', $this->operation('independant', 'task.create', null, 0, $this->taskPayload(['title' => 'Formulaire fictif interrompu']), $key))->assertOk()->assertJsonPath('result.version', 1);
        $this->assertSame(1, Task::where('client_key', $key)->count());

        // Le registre ne garde aucun contenu saisi.
        $ledger = json_encode(DB::table('sync_operations')->get(), JSON_UNESCAPED_UNICODE);
        foreach ([self::PRIVATE_TEXT, 'Tâche fictive', 'Formulaire fictif'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $ledger);
        }
    }

    public function test_r45_two_devices_edit_the_same_task_offline_and_the_conflict_is_visible_without_silent_overwrite(): void
    {
        $created = $this->send('independant', $this->operation('independant', 'task.create', null, 0, $this->taskPayload()))->json('result');
        $task = Task::findOrFail($created['id']);

        // Les deux appareils connaissent la version 1. Le premier se synchronise.
        $deviceA = $this->operation('independant', 'task.update', $task->id, 1, $this->taskPayload(['title' => 'Titre de l’appareil A', 'priority' => 'high'], true));
        $this->send('independant', $deviceA)->assertOk()->assertJsonPath('result.version', 2);

        // Le second arrive avec une version dépassée : rien n'est écrasé, et il reçoit la version actuelle pour comparer.
        $deviceB = $this->operation('independant', 'task.update', $task->id, 1, $this->taskPayload(['title' => 'Titre de l’appareil B', 'description' => 'Note fictive de B', 'status' => 'in_progress'], true));
        $conflict = $this->send('independant', $deviceB)->assertStatus(409)->json();
        $this->assertSame(['conflict', 'VERSION_CONFLICT', false, null], [$conflict['outcome'], $conflict['code'], $conflict['replayed'], $conflict['result']]);
        $this->assertSame(['version' => 2, 'title' => 'Titre de l’appareil A', 'description' => null, 'due_at' => null, 'priority' => 'high', 'status' => 'todo', 'personal_subject_id' => null], $conflict['current']);
        $this->assertSame(['Titre de l’appareil A', 2, 'todo'], [$task->fresh()->title, $task->fresh()->lock_version, $task->fresh()->status]);

        // Renvoyer la même opération ne la fait pas passer : même conflit, avec l'état relu au moment de la demande.
        $this->send('independant', $this->operation('independant', 'task.update', $task->id, 2, $this->taskPayload(['title' => 'Titre de l’appareil A, encore', 'priority' => 'high'], true)))->assertOk();
        $again = $this->send('independant', $deviceB)->assertStatus(409)->json();
        $this->assertSame([true, 3, 'Titre de l’appareil A, encore'], [$again['replayed'], $again['current']['version'], $again['current']['title']]);
        $this->assertSame('Titre de l’appareil A, encore', $task->fresh()->title);

        // Le propriétaire choisit explicitement d'appliquer son brouillon sur la version qu'il vient de voir : nouvelle opération, nouvelle version.
        $resolved = $this->operation('independant', 'task.update', $task->id, 3, $deviceB['payload']);
        $this->send('independant', $resolved)->assertOk()->assertJsonPath('result.version', 4)->assertJsonPath('result.status', 'in_progress');
        $this->assertSame(['Titre de l’appareil B', 'Note fictive de B', 4], [$task->fresh()->title, $task->fresh()->description, $task->fresh()->lock_version]);
        $this->assertSame(1, Task::count());
    }

    public function test_r23_a_check_in_written_offline_and_sent_repeatedly_gives_one_check_in_per_owner_and_course(): void
    {
        $course = $this->personalCourse();
        $operation = $this->operation('independant', 'check_in.save', $course->id, 0, $this->checkInPayload(['understanding' => 2]));
        $first = $this->send('independant', $operation)->assertOk()->json();
        $this->assertSame(['applied', ['id' => $course->id, 'version' => 1, 'status' => 'completed'], false], [$first['outcome'], $first['result'], $first['replayed']]);
        foreach (range(1, 3) as $attempt) {
            $this->assertSame([...$first, 'replayed' => true], $this->send('independant', $operation)->assertOk()->json());
        }
        $this->assertSame([1, 1, self::PRIVATE_TEXT, 2], [CourseCheckIn::count(), CourseCheckInEvent::count(), CourseCheckIn::sole()->summary, CourseCheckIn::sole()->understanding]);

        // Un second appareil avait aussi écrit un brouillon pour ce cours : le retour reste unique, le conflit est rendu avec le retour du propriétaire.
        $second = $this->operation('independant', 'check_in.save', $course->id, 0, $this->checkInPayload(['summary' => 'Brouillon fictif du second appareil']));
        $conflict = $this->send('independant', $second)->assertStatus(409)->json();
        $this->assertSame(['version' => 1, 'status' => 'completed', 'summary' => self::PRIVATE_TEXT, 'work_to_do' => null, 'points_to_review' => null, 'understanding' => 2], $conflict['current']);
        $this->assertSame([1, self::PRIVATE_TEXT], [CourseCheckIn::count(), CourseCheckIn::sole()->summary]);
        $this->send('independant', $this->operation('independant', 'check_in.save', $course->id, 1, $second['payload']))->assertOk()->assertJsonPath('result.version', 2);
        $this->assertSame([1, 2, 'Brouillon fictif du second appareil'], [CourseCheckIn::count(), CourseCheckInEvent::count(), CourseCheckIn::sole()->summary]);

        // Un brouillon vide ou portant un champ inattendu est refusé, et le refus est rendu à l'identique s'il est renvoyé.
        $empty = $this->operation('independant', 'check_in.save', $course->id, 2, $this->checkInPayload(['summary' => null]));
        $refused = $this->send('independant', $empty)->assertStatus(422)->json();
        $this->assertSame(['rejected', 'VALIDATION_FAILED', false], [$refused['outcome'], $refused['code'], $refused['replayed']]);
        $this->assertSame([...$refused, 'replayed' => true], $this->send('independant', $empty)->json());
        $this->send('independant', $this->operation('independant', 'check_in.save', $course->id, 2, [...$this->checkInPayload(), 'action' => 'ignore']))->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(2, CourseCheckIn::sole()->lock_version);
    }

    public function test_r48_a_right_withdrawn_before_synchronisation_refuses_the_operation_and_gives_nothing_back(): void
    {
        $course = $this->publishedCourse();
        $draft = $this->operation('apprenant-double', 'check_in.save', $course->id, 0, $this->checkInPayload());
        // L'appartenance est retirée pendant que l'appareil est hors connexion.
        $this->member('apprenant-double')->revoke();
        $response = $this->send('apprenant-double', $draft)->assertStatus(422);
        $this->assertSame(['rejected', 'PARENT_UNAVAILABLE', null, null], [$response->json('outcome'), $response->json('code'), $response->json('result'), $response->json('current')]);
        // Corps relu sans échappement des accents : sinon la recherche d'un mot accentué ne prouverait rien.
        $this->assertStringNotContainsString('Méthodologie', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('thodologie', $response->getContent());
        $this->assertSame(0, CourseCheckIn::count());
        $this->assertSame(['rejected', 'PARENT_UNAVAILABLE'], [SyncOperation::sole()->outcome, SyncOperation::sole()->code]);
        $this->send('apprenant-double', $draft)->assertStatus(422)->assertJsonPath('replayed', true);

        // Cours d'un groupe où le compte n'a jamais été inscrit, ou cours inconnu : même refus, sans indice d'existence.
        $this->send('independant', $this->operation('independant', 'check_in.save', $course->id, 0, $this->checkInPayload()))->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE');
        $this->send('independant', $this->operation('independant', 'check_in.save', (string) Str::uuid(), 0, $this->checkInPayload()))->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE');
        $this->assertSame(0, CourseCheckIn::count());

        // Tâche d'un autre compte, tâche supprimée, devoir collectif : rien d'institutionnel ni d'étranger ne passe par la synchronisation.
        $foreign = Task::findOrFail($this->send('independant', $this->operation('independant', 'task.create', null, 0, $this->taskPayload()))->json('result.id'));
        $this->send('apprenant-double', $this->operation('apprenant-double', 'task.update', $foreign->id, 1, $this->taskPayload(['title' => 'Intrusion fictive'], true)))->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE')->assertJsonPath('current', null);
        $homework = ['client_key' => (string) Str::uuid(), 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id, 'subject_id' => Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id,
            'title' => 'Devoir fictif collectif', 'due_at' => '2026-11-20T18:00', 'priority' => 'normal'];
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $homework)->assertSessionHasNoErrors();
        $collective = Task::where('client_key', $homework['client_key'])->firstOrFail();
        $this->send('enseignant-a', $this->operation('enseignant-a', 'task.update', $collective->id, 1, $this->taskPayload(['title' => 'Devoir modifié hors ligne'], true)))->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE');
        $this->as('independant')->delete('/taches/'.$foreign->id, ['expected_version' => 1])->assertSessionHasNoErrors();
        $this->send('independant', $this->operation('independant', 'task.update', $foreign->id, 2, $this->taskPayload(['title' => 'Après suppression'], true)))->assertStatus(422)->assertJsonPath('code', 'PARENT_UNAVAILABLE');
        $this->assertSame(['Tâche fictive écrite hors connexion', 'Devoir fictif collectif'], [$foreign->fresh()->title, $collective->fresh()->title]);

        // Contexte d'établissement, type inconnu, champ d'établissement glissé dans le contenu : refusés.
        $base = $this->operation('enseignant-a', 'task.create', null, 0, $this->taskPayload());
        $this->send('enseignant-a', [...$base, 'context' => 'institution'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->send('enseignant-a', [...$base, 'type' => 'homework.create'])->assertStatus(422);
        $smuggled = [...$this->taskPayload(), 'group_id' => $homework['group_id'], 'scope' => 'group'];
        $this->send('enseignant-a', $this->operation('enseignant-a', 'task.create', null, 0, $smuggled))->assertStatus(422)->assertJsonPath('outcome', 'rejected');
        $this->assertSame(0, Task::where('owner_id', $this->user('enseignant-a')->id)->count());
        $this->assertSame(1, Task::where('scope', 'group')->count());

        // Brouillon écrit par un autre compte sur le même appareil : jamais appliqué au compte connecté, rien n'est enregistré.
        $before = [Task::count(), SyncOperation::count()];
        $this->send('apprenant-double', $this->operation('independant', 'task.create', null, 0, $this->taskPayload()))->assertForbidden()->assertJsonPath('code', 'ACCOUNT_MISMATCH');
        $this->assertSame($before, [Task::count(), SyncOperation::count()]);
    }

    public function test_r48_an_existing_private_check_in_stays_with_its_owner_and_a_conflict_reveals_no_course_data(): void
    {
        $course = $this->publishedCourse();
        $this->send('apprenant-double', $this->operation('apprenant-double', 'check_in.save', $course->id, 0, $this->checkInPayload()))->assertOk();
        $this->member('apprenant-double')->revoke();
        // Brouillon d'un autre appareil, avec une version dépassée, envoyé après le retrait : conflit, avec le seul retour du propriétaire.
        $response = $this->send('apprenant-double', $this->operation('apprenant-double', 'check_in.save', $course->id, 0, $this->checkInPayload(['summary' => 'Second brouillon fictif'])))->assertStatus(409);
        $this->assertSame(['version', 'status', 'summary', 'work_to_do', 'points_to_review', 'understanding'], array_keys($response->json('current')));
        $this->assertStringNotContainsString('Méthodologie', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('thodologie', $response->getContent());
        // Son propre retour reste modifiable par lui seul (règle du lot 10), sans rouvrir l'accès au cours.
        $this->send('apprenant-double', $this->operation('apprenant-double', 'check_in.save', $course->id, 1, $this->checkInPayload(['summary' => 'Second brouillon fictif'])))->assertOk()->assertJsonPath('result.version', 2);
        $this->as('apprenant-double', null)->get('/planning?view=day&date=2026-11-16')->assertOk()->assertDontSee('Méthodologie fictive publiée');
    }

    public function test_the_offline_copy_carries_versions_for_drafts_but_never_the_text_of_a_check_in(): void
    {
        $course = $this->personalCourse();
        $later = $this->rules(['title' => 'Cours fictif de demain', 'valid_from' => '2026-11-17', 'valid_until' => '2026-11-17', 'weekdays' => [2]]);
        $this->as('independant')->post('/planning/series', $later)->assertSessionHasNoErrors();
        $task = $this->send('independant', $this->operation('independant', 'task.create', null, 0, $this->taskPayload(['description' => 'Description fictive', 'due_at' => '2026-11-20T18:00', 'priority' => 'high'])))->json('result');
        $snapshot = $this->as('independant')->getJson('/hors-ligne/instantane')->assertOk()->json();
        $this->assertSame(2, $snapshot['version']);
        $this->assertSame([['id' => $task['id'], 'title' => 'Tâche fictive écrite hors connexion', 'dueAt' => '2026-11-20T18:00:00+00:00', 'late' => false, 'collective' => false, 'description' => 'Description fictive',
            'dueLocal' => '2026-11-20T18:00', 'priority' => 'high', 'status' => 'todo', 'version' => 1, 'personalSubjectId' => null]], $snapshot['tasks']);
        // Cours terminé sans retour : un brouillon peut être écrit, à partir de la version 0.
        $this->assertSame([['id' => $course->id, 'title' => 'Révision fictive', 'provenance' => 'personal', 'version' => 0]], array_map(fn (array $row) => ['id' => $row['id'], 'title' => $row['title'], 'provenance' => $row['provenance'], 'version' => $row['version']], $snapshot['checkIns']));
        $this->assertSame([['Révision fictive', ['version' => 0, 'status' => null]], ['Cours fictif de demain', ['version' => 0, 'status' => null]]], array_map(fn (array $row) => [$row['title'], $row['checkIn']], $snapshot['planning']));
        $this->assertSame(['statuses', 'priorities', 'understanding'], array_keys($snapshot['labels']));

        $this->send('independant', $this->operation('independant', 'check_in.save', $course->id, 0, $this->checkInPayload()))->assertOk();
        $after = $this->as('independant')->getJson('/hors-ligne/instantane');
        $this->assertSame([[], ['version' => 1, 'status' => 'completed']], [$after->json('checkIns'), $after->json('planning.0.checkIn')]);
        $this->assertStringNotContainsString(self::PRIVATE_TEXT, json_encode($after->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('sans r', $after->getContent());

        // Un cours enseigné n'ouvre jamais de retour privé.
        $this->publishedCourse();
        $this->assertSame([null], array_column($this->as('enseignant-a')->getJson('/hors-ligne/instantane')->json('planning'), 'checkIn'));
        $this->assertSame([], $this->as('enseignant-a')->getJson('/hors-ligne/instantane')->json('checkIns'));
    }

    public function test_operations_written_by_the_real_device_code_are_accepted_as_they_are(): void
    {
        $course = $this->personalCourse();
        $task = Task::findOrFail($this->send('independant', $this->operation('independant', 'task.create', null, 0, $this->taskPayload()))->json('result.id'));
        // Le vrai code de l'appareil (public/offline-core.js) écrit trois opérations ; elles sont envoyées ici sans être retouchées.
        $process = new Process(['node', 'tests/js/emit-operations.mjs', OfflineSnapshot::accountKey($this->user('independant')), $task->id, $course->id], base_path());
        $process->setTimeout(60);
        $process->run();
        if ($process->getExitCode() === 127 || str_contains($process->getErrorOutput(), 'not recognized')) {
            $this->markTestSkipped('Node.js est indisponible sur ce poste.');
        }
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $sent = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['task.create', 'task.update', 'check_in.save'], array_column(array_column($sent, 'body'), 'type'));
        foreach ($sent as $request) {
            $this->assertSame(self::URL, $request['url']);
            // L'empreinte calculée par l'appareil est celle que le serveur recalcule sur le même contenu.
            $this->assertSame(OperationFingerprint::of($request['body']['type'], $request['body']['target_id'], $request['body']['base_version'], $request['body']['payload']), $request['body']['fingerprint']);
            $this->send('independant', $request['body'])->assertOk()->assertJsonPath('outcome', 'applied')->assertJsonPath('replayed', false);
            $this->send('independant', $request['body'])->assertOk()->assertJsonPath('replayed', true);
        }
        $created = Task::where('client_key', $sent[0]['body']['operation_id'])->sole();
        $this->assertSame(['Tâche fictive écrite par l’appareil 😀', "Ligne 1\nLigne 2 \"citée\" / fin", 'high', '2026-11-20 18:00:00'], [$created->title, $created->description, $created->priority, $created->due_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['Titre modifié par l’appareil', null, 'low', 'in_progress', 2], [$task->fresh()->title, $task->fresh()->description, $task->fresh()->priority, $task->fresh()->status, $task->fresh()->lock_version]);
        $checkIn = CourseCheckIn::sole();
        $this->assertSame(['Retour fictif écrit par l’appareil', null, 'Point fictif', 3, 1], [$checkIn->summary, $checkIn->work_to_do, $checkIn->points_to_review, $checkIn->understanding, $checkIn->lock_version]);
        $this->assertSame([2, 4], [Task::count(), SyncOperation::count()]);
    }

    public function test_old_acknowledgements_are_pruned_and_a_late_replay_still_creates_nothing_twice(): void
    {
        $operation = $this->operation('independant', 'task.create', null, 0, $this->taskPayload());
        $this->send('independant', $operation)->assertOk();
        $recent = $this->operation('independant', 'task.create', null, 0, $this->taskPayload(['title' => 'Tâche fictive récente']));
        $this->travel(80)->days();
        $this->send('independant', $recent)->assertOk();
        $this->travel(11)->days();
        $this->artisan('campus:sync:prune')->expectsOutput('1 accusé(s) de synchronisation supprimé(s).')->assertSuccessful();
        $this->assertSame([$recent['operation_id']], SyncOperation::pluck('operation_id')->all());
        // L'accusé a disparu, mais la création reste reconnue par la tâche elle-même.
        $this->send('independant', $operation)->assertOk()->assertJsonPath('result.version', 1);
        $this->assertSame(2, Task::count());
    }
}
