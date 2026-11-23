<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\TaskStep;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\Tasks;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use DatabaseTransactions;

    private const PRIVATE_TITLE = 'Tâche privée fictive de révision';

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
        return $this->freshDevice()->actingAs($this->user($name))
            ->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    /** Crée une tâche personnelle par la route réelle et la renvoie. */
    private function personal(string $owner = 'independant', array $data = []): Task
    {
        $payload = ['client_key' => (string) Str::uuid(), 'title' => self::PRIVATE_TITLE, ...$data];
        $this->as($owner)->post('/taches', $payload)->assertSessionHasNoErrors()->assertRedirect('/taches');

        return Task::where('client_key', $payload['client_key'])->firstOrFail();
    }

    private function homeworkPayload(array $data = []): array
    {
        return ['client_key' => (string) Str::uuid(), 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id,
            'subject_id' => Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->firstOrFail()->id,
            'title' => 'Devoir fictif de méthodologie', 'description' => 'Consigne fictive', 'due_at' => '2026-11-20T18:00', 'priority' => 'high', ...$data];
    }

    /** Devoir publié par l'enseignant fictif A pour A-G1 / méthodologie. */
    private function homework(array $data = []): Task
    {
        $payload = $this->homeworkPayload($data);
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $payload)->assertSessionHasNoErrors()->assertRedirect('/taches/devoirs');

        return Task::where('client_key', $payload['client_key'])->firstOrFail();
    }

    private function advance(string $name, Task $task, array $data, bool $json = false): TestResponse
    {
        $url = '/taches/'.$task->id.'/progression';

        return $json ? $this->as($name)->putJson($url, $data) : $this->as($name)->put($url, $data);
    }

    private function edit(string $name, Task $task, array $data, bool $json = false): TestResponse
    {
        $payload = ['title' => $task->title, 'priority' => $task->priority, 'status' => $task->status, ...$data];
        $url = '/taches/'.$task->id;

        return $json ? $this->as($name)->patchJson($url, $payload) : $this->as($name)->patch($url, $payload);
    }

    private function row(string $name, Task $task, string $query = '?statut=all'): ?array
    {
        $props = $this->as($name)->get('/taches'.$query)->assertOk()->viewData('page')['props'];

        return collect($props['tasks'])->concat($props['undated'])->firstWhere('id', $task->id);
    }

    public function test_t11_01_a_personal_task_has_optional_fields_four_states_and_a_computed_late_flag(): void
    {
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user('independant')->id, 'name' => 'Matière fictive personnelle']);
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => ''])->assertSessionHasErrors('title');
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'x', 'priority' => 'urgent'])->assertSessionHasErrors('priority');
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'x', 'due_at' => 'demain'])->assertSessionHasErrors('due_at');
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'x', 'status' => 'late'])->assertSessionHasNoErrors();
        $this->assertSame(['todo'], Task::pluck('status')->all());
        Task::query()->delete();

        $bare = $this->personal();
        $this->assertSame(['personal', $this->user('independant')->id, 'todo', 'normal', null, null, null, 1],
            [$bare->scope, $bare->owner_id, $bare->status, $bare->priority, $bare->description, $bare->due_at, $bare->personal_subject_id, $bare->lock_version]);

        $late = $this->personal('independant', ['title' => 'Tâche fictive en retard', 'description' => 'Détail fictif', 'personal_subject_id' => $subject->id, 'due_at' => '2026-11-15T12:00', 'priority' => 'high']);
        $this->assertSame('2026-11-15 12:00:00', $late->due_at->format('Y-m-d H:i:s'));
        $row = $this->row('independant', $late);
        $this->assertSame(['todo', true, 'Matière fictive personnelle', 'high', false], [$row['status'], $row['late'], $row['subject'], $row['priority'], $row['collective']]);

        // Le retard n'est pas un état : il disparaît dès que la tâche est terminée ou abandonnée, l'état reste celui choisi.
        $this->edit('independant', $late, ['expected_version' => 1, 'status' => 'in_progress', 'due_at' => '2026-11-15T12:00'])->assertSessionHasNoErrors();
        $this->assertSame(['in_progress', true], [$this->row('independant', $late)['status'], $this->row('independant', $late)['late']]);
        $this->edit('independant', $late, ['expected_version' => 2, 'status' => 'done', 'due_at' => '2026-11-15T12:00'])->assertSessionHasNoErrors();
        $this->assertSame(['done', false], [$this->row('independant', $late)['status'], $this->row('independant', $late)['late']]);
        $this->edit('independant', $late, ['expected_version' => 3, 'status' => 'abandoned', 'due_at' => '2026-11-15T12:00'])->assertSessionHasNoErrors();
        $this->assertSame(['abandoned', false], [$this->row('independant', $late)['status'], $this->row('independant', $late)['late']]);
        $this->edit('independant', $late, ['expected_version' => 4, 'status' => 'late'])->assertSessionHasErrors('status');

        $foreign = PersonalSubject::factory()->create(['owner_id' => $this->user('editeur')->id]);
        $this->edit('independant', $bare, ['expected_version' => 1, 'personal_subject_id' => $foreign->id])->assertSessionHasErrors('personal_subject_id');
        $this->as('independant')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'x', 'source_occurrence_id' => (string) Str::uuid()])->assertSessionHasErrors('source_occurrence_id');
        try {
            DB::table('tasks')->where('id', $bare->id)->update(['status' => 'late']);
            $this->fail('La base a accepté un état « en retard ».');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }
    }

    public function test_t11_02_filters_by_status_priority_subject_and_date_keep_undated_tasks_visible(): void
    {
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user('independant')->id]);
        $undated = $this->personal('independant', ['title' => 'Sans échéance fictive']);
        $soon = $this->personal('independant', ['title' => 'Bientôt fictive', 'due_at' => '2026-11-17T10:00', 'priority' => 'high', 'personal_subject_id' => $subject->id]);
        $later = $this->personal('independant', ['title' => 'Plus tard fictive', 'due_at' => '2026-12-10T10:00', 'priority' => 'low']);
        $done = $this->personal('independant', ['title' => 'Terminée fictive', 'due_at' => '2026-11-18T10:00']);
        $this->edit('independant', $done, ['expected_version' => 1, 'status' => 'done', 'due_at' => '2026-11-18T10:00'])->assertSessionHasNoErrors();
        $ids = fn (string $query, string $list = 'tasks') => collect($this->as('independant')->get('/taches'.$query)->assertOk()->viewData('page')['props'][$list])->pluck('id')->all();

        $this->assertSame([$soon->id, $later->id], $ids(''));
        $this->assertSame([$undated->id], $ids('', 'undated'));
        $this->assertSame([$soon->id, $done->id, $later->id], $ids('?statut=all'));
        $this->assertSame([$done->id], $ids('?statut=done'));
        $this->assertSame([], $ids('?statut=done', 'undated'));
        $this->assertSame([$soon->id], $ids('?priorite=high'));
        $this->assertSame([$soon->id], $ids('?matiere=p-'.$subject->id));
        $this->assertSame([$soon->id], $ids('?du=2026-11-16&au=2026-11-30'));
        $this->assertSame([$undated->id], $ids('?du=2026-11-16&au=2026-11-30', 'undated'));
        $this->assertSame([$later->id], $ids('?du=2026-12-01'));
        $this->as('independant')->get('/taches?statut=late')->assertSessionHasErrors('statut');
        $this->as('independant')->get('/taches?matiere=1%20OR%201')->assertSessionHasErrors('matiere');
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->has('dashboard.upcomingTasks', 3)->where('dashboard.upcomingTasks.0.id', $soon->id)->where('dashboard.upcomingTasks.2.id', $undated->id));
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('dashboard', null));
    }

    public function test_t11_03_owner_isolation_no_other_account_reads_or_changes_a_personal_task(): void
    {
        $task = $this->personal();
        $step = app(Tasks::class)->addStep($this->user('independant'), $task->id, (string) Str::uuid(), 'Étape privée fictive');

        foreach ([['apprenant-double', 'A'], ['enseignant-a', 'A'], ['admin-a', 'A'], ['admin-b', 'B'], ['plateforme', null], ['editeur', null]] as [$name, $letter]) {
            $actor = fn () => in_array($name, ['plateforme', 'editeur'], true)
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            foreach (['/taches?statut=all', '/taches?vue=corbeille', '/'] as $url) {
                $actor()->get($url)->assertOk()->assertDontSee('privée fictive');
            }
            $actor()->patch('/taches/'.$task->id, ['expected_version' => 1, 'title' => 'Écrasement fictif', 'priority' => 'low', 'status' => 'done'])->assertNotFound();
            $actor()->put('/taches/'.$task->id.'/progression', ['expected_version' => 0, 'status' => 'done'])->assertNotFound();
            $actor()->delete('/taches/'.$task->id, ['expected_version' => 1])->assertNotFound();
            $actor()->post('/taches/'.$task->id.'/restauration')->assertNotFound();
            $actor()->post('/taches/'.$task->id.'/etapes', ['client_key' => (string) Str::uuid(), 'title' => 'Intrusion fictive'])->assertNotFound();
            $actor()->patch('/taches/'.$task->id.'/etapes/'.$step->id, ['done' => true])->assertNotFound();
            $actor()->delete('/taches/'.$task->id.'/etapes/'.$step->id)->assertNotFound();
            $this->assertTrue(Gate::forUser($this->user($name))->denies('view', $task));
            $this->assertTrue(Gate::forUser($this->user($name))->denies('update', $task));
            $this->assertFalse(app(Tasks::class)->shouldRemind($this->user($name), $task->id));
        }
        // Un institution_id ou un owner_id envoyé par le client n'ouvre rien et n'est pas écrit.
        $this->as('apprenant-double', 'A')->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive forcée', 'owner_id' => $this->user('independant')->id,
            'institution_id' => $this->member('admin-a')->institution_id, 'scope' => 'group'])->assertSessionHasNoErrors();
        $forced = Task::where('title', 'Tâche fictive forcée')->sole();
        $this->assertSame(['personal', $this->user('apprenant-double')->id, null], [$forced->scope, $forced->owner_id, $forced->institution_id]);

        $this->assertTrue(Gate::forUser($this->user('independant'))->allows('view', $task));
        $this->assertSame([self::PRIVATE_TITLE, 'todo', null, 1, false], [$task->fresh()->title, $task->fresh()->status, $task->fresh()->deleted_at, TaskStep::count(), $step->fresh()->done]);
        foreach ([AuditLog::query()->get(), DomainEvent::query()->get()] as $records) {
            $this->assertStringNotContainsString('privée fictive', json_encode($records->toArray(), JSON_UNESCAPED_UNICODE));
        }
        $this->freshDevice()->get('/taches')->assertRedirect('/connexion');
        $this->freshDevice()->post('/taches', ['client_key' => (string) Str::uuid(), 'title' => 'x'])->assertRedirect('/connexion');
    }

    public function test_r57_a_double_click_or_replayed_form_creates_one_task_and_a_stale_version_conflicts_without_overwriting(): void
    {
        $payload = ['client_key' => (string) Str::uuid(), 'title' => 'Tâche fictive double clic', 'due_at' => '2026-11-19T09:00'];
        $first = $this->as('independant')->postJson('/taches', $payload)->assertOk()->assertJson(['version' => 1, 'status' => 'todo', 'replayed' => false]);
        $second = $this->as('independant')->postJson('/taches', $payload)->assertOk()->assertJson(['version' => 1, 'replayed' => true]);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Task::count());
        $this->as('independant')->postJson('/taches', [...$payload, 'title' => 'Autre contenu fictif'])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        // La même clé chez un autre compte crée sa propre tâche, sans jamais révéler celle du premier.
        $this->as('editeur')->postJson('/taches', $payload)->assertOk()->assertJson(['replayed' => false]);
        $this->assertSame(2, Task::count());

        $task = Task::where('owner_id', $this->user('independant')->id)->sole();
        $change = ['expected_version' => 1, 'title' => 'Titre fictif modifié', 'due_at' => '2026-11-19T09:00'];
        $this->edit('independant', $task, $change, json: true)->assertOk()->assertJson(['version' => 2, 'replayed' => false]);
        $this->edit('independant', $task, $change, json: true)->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->edit('independant', $task, ['expected_version' => 1, 'title' => 'Écrasement fictif'], json: true)->assertStatus(409)
            ->assertJson(['code' => 'VERSION_CONFLICT'])->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
        $this->edit('independant', $task, ['expected_version' => 9, 'title' => 'Écrasement fictif'], json: true)->assertStatus(409);
        $this->as('independant')->withHeader('X-Inertia', 'true')->from('/taches')->patch('/taches/'.$task->id, ['expected_version' => 1, 'title' => 'Écrasement fictif', 'priority' => 'normal', 'status' => 'todo'])
            ->assertRedirect('/taches')->assertSessionHasErrors('reference');
        $this->assertSame(['Titre fictif modifié', 2], [$task->fresh()->title, $task->fresh()->lock_version]);
        // La création rejouée après modification ne recrée ni n'écrase rien.
        $this->as('independant')->postJson('/taches', $payload)->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->assertSame('Titre fictif modifié', $task->fresh()->title);
    }

    public function test_r24_a_teacher_publishes_homework_for_an_assigned_group_and_each_learner_keeps_a_separate_progress(): void
    {
        $task = $this->homework();
        $teacher = $this->member('enseignant-a');
        $this->assertSame(['group', null, $teacher->institution_id, $teacher->id, 'todo', '2026-11-20 18:00:00'],
            [$task->scope, $task->owner_id, $task->institution_id, $task->author_membership_id, $task->status, $task->due_at->format('Y-m-d H:i:s')]);
        $event = DomainEvent::where('type', 'homework.published')->sole();
        $this->assertSame(['homework-published:'.$task->id, $task->id], [$event->dedup_key, $event->subject_uuid]);
        $this->assertStringNotContainsString('fictif', json_encode($event->payload, JSON_UNESCAPED_UNICODE));

        // Les deux apprenants du groupe voient le devoir, à faire, sans aucune ligne d'avancement créée d'office.
        foreach (['apprenant-double', 'polyvalent'] as $learner) {
            $row = $this->row($learner, $task);
            $this->assertSame([true, 'todo', 0, 'Méthodologie fictive', 'Groupe fictif A 1'], [$row['collective'], $row['status'], $row['version'], $row['subject'], $row['group']]);
        }
        $this->assertSame(0, TaskProgress::count());
        $this->assertNull($this->row('independant', $task));
        $this->assertNull($this->row('enseignant-a', $task));
        $this->assertNull($this->row('admin-a', $task));

        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'done'])->assertSessionHasNoErrors()->assertRedirect('/taches');
        $this->assertSame('done', $this->row('apprenant-double', $task)['status']);
        $other = $this->row('polyvalent', $task);
        $this->assertSame(['todo', 0], [$other['status'], $other['version']]);
        $this->advance('polyvalent', $task, ['expected_version' => 0, 'status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->assertSame('done', $this->row('apprenant-double', $task)['status']);
        $this->assertEqualsCanonicalizing(['done', 'in_progress'], TaskProgress::pluck('status')->all());
        // Le devoir lui-même ne porte aucun avancement : son état et sa version n'ont pas bougé.
        $this->assertSame(['todo', 1], [$task->fresh()->status, $task->fresh()->lock_version]);

        // L'apprenant ne modifie ni le contenu ni la date officielle, et ne peut pas retirer le devoir.
        $this->as('apprenant-double')->patch('/taches/'.$task->id, ['expected_version' => 1, 'title' => 'Titre fictif falsifié', 'priority' => 'low', 'status' => 'done', 'due_at' => '2027-01-01T10:00'])->assertNotFound();
        $this->as('apprenant-double')->delete('/taches/'.$task->id, ['expected_version' => 1])->assertNotFound();
        $this->as('apprenant-double', 'A')->patch('/taches/devoirs/'.$task->id, ['expected_version' => 1, 'title' => 'Titre fictif falsifié', 'priority' => 'low'])->assertForbidden();
        $this->as('apprenant-double', 'A')->get('/taches/devoirs')->assertForbidden();
        $this->assertSame(['Devoir fictif de méthodologie', '2026-11-20 18:00:00'], [$task->fresh()->title, $task->fresh()->due_at->format('Y-m-d H:i:s')]);

        // L'enseignant gère le contenu officiel, sans aucune donnée d'avancement individuel.
        $page = $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Homework')->has('homework', 1)->has('assignments', 1)->where('canWrite', true));
        $this->assertStringNotContainsString('in_progress', $page->getContent());
        $this->assertStringNotContainsString('"done"', $page->getContent());
    }

    public function test_t11_05_homework_is_limited_to_the_effective_assignment_of_the_teacher(): void
    {
        $task = $this->homework();
        $otherGroup = Group::where('code', 'A-G2')->firstOrFail();
        $before = Task::count();

        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload(['group_id' => $otherGroup->id]))->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload(['group_id' => Group::where('code', 'B-G1')->firstOrFail()->id]))->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload(['institution_id' => $this->member('admin-b', 'B')->institution_id, 'group_id' => $otherGroup->id]))->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload(['source_occurrence_id' => (string) Str::uuid()]))->assertSessionHasErrors('source_occurrence_id');
        // Hors contexte d'enseignant : refus, y compris pour l'administration et pour un enseignant d'un autre établissement.
        $this->as('admin-a', 'A')->post('/taches/devoirs', $this->homeworkPayload())->assertForbidden();
        $this->as('enseignant-a')->post('/taches/devoirs', $this->homeworkPayload())->assertForbidden();
        $this->as('polyvalent', 'B')->post('/taches/devoirs', $this->homeworkPayload())->assertNotFound();
        $this->as('polyvalent', 'B')->patch('/taches/devoirs/'.$task->id, ['expected_version' => 1, 'title' => 'Intrusion fictive', 'priority' => 'low'])->assertNotFound();
        $this->as('polyvalent', 'B')->delete('/taches/devoirs/'.$task->id, ['expected_version' => 1])->assertNotFound();
        $this->as('polyvalent', 'B')->get('/taches/devoirs')->assertOk()->assertInertia(fn (Assert $page) => $page->has('homework', 0));
        $this->assertSame($before, Task::count());

        // Sans vérification renforcée (second facteur non livré), l'enseignant lit mais ne publie pas.
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload())->assertForbidden();
        $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWrite', false)->has('homework', 1));
        config(['campus.identity.privileged_without_mfa' => true]);

        // Affectation clôturée : plus de publication ni de modification ; le devoir reste visible des apprenants.
        TeachingAssignment::where('membership_id', $this->member('enseignant-a')->id)->firstOrFail()->close();
        $this->as('enseignant-a', 'A')->post('/taches/devoirs', $this->homeworkPayload())->assertNotFound();
        $this->as('enseignant-a', 'A')->patch('/taches/devoirs/'.$task->id, ['expected_version' => 1, 'title' => 'Après clôture fictive', 'priority' => 'low'])->assertNotFound();
        $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertOk()->assertInertia(fn (Assert $page) => $page->has('homework', 0)->has('assignments', 0));
        $this->assertSame([$before, 'Devoir fictif de méthodologie'], [Task::count(), $task->fresh()->title]);
        $this->assertNotNull($this->row('apprenant-double', $task));

        // Inscription clôturée : le devoir et l'avancement ne sont plus accessibles à l'ancien inscrit.
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'in_progress'])->assertSessionHasNoErrors();
        Enrollment::where('membership_id', $this->member('apprenant-double')->id)->firstOrFail()->close();
        $this->assertNull($this->row('apprenant-double', $task));
        $this->advance('apprenant-double', $task, ['expected_version' => 1, 'status' => 'done'])->assertNotFound();
        $this->assertFalse(app(Tasks::class)->shouldRemind($this->user('apprenant-double'), $task->id));
        $this->assertTrue(app(Tasks::class)->shouldRemind($this->user('polyvalent'), $task->id));
    }

    public function test_t11_04_a_collective_change_keeps_every_progress_and_emits_one_deduplicated_event(): void
    {
        $task = $this->homework();
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'done'])->assertSessionHasNoErrors();
        $this->advance('polyvalent', $task, ['expected_version' => 0, 'status' => 'in_progress', 'personal_target_at' => '2026-11-18T08:00'])->assertSessionHasNoErrors();
        $progress = TaskProgress::orderBy('id')->get()->map->only(['owner_id', 'status', 'lock_version'])->all();
        $change = ['expected_version' => 1, 'title' => 'Devoir fictif de méthodologie', 'description' => 'Consigne fictive corrigée', 'due_at' => '2026-11-24T18:00', 'priority' => 'high'];

        $this->as('enseignant-a', 'A')->patchJson('/taches/devoirs/'.$task->id, $change)->assertOk()->assertJson(['version' => 2, 'replayed' => false]);
        // Rejeu de la même demande (double clic, synchronisation) : rien de plus n'est écrit ni émis.
        $this->as('enseignant-a', 'A')->patchJson('/taches/devoirs/'.$task->id, $change)->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->as('enseignant-a', 'A')->patchJson('/taches/devoirs/'.$task->id, [...$change, 'expected_version' => 2])->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $event = DomainEvent::where('type', 'homework.updated')->sole();
        $this->assertSame('homework-updated:'.$task->id.':v2', $event->dedup_key);
        $this->assertEquals(['task_id' => $task->id, 'institution_id' => $task->institution_id, 'academic_year_id' => $task->academic_year_id, 'group_id' => $task->group_id,
            'subject_id' => $task->subject_id, 'version' => 2, 'changed' => ['description', 'due_at']], $event->payload);
        $this->assertStringNotContainsString('corrigée', json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE));
        try {
            DomainEvent::create(['type' => 'homework.updated', 'dedup_key' => $event->dedup_key, 'subject_uuid' => $task->id, 'payload' => [], 'occurred_at' => now()]);
            $this->fail('La base a accepté un second événement pour la même version.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
        $this->assertSame(1, AuditLog::where('action', 'homework.updated')->count());

        // Les avancements sont intacts ; chacun voit le nouveau contenu officiel et son propre état.
        $this->assertSame($progress, TaskProgress::orderBy('id')->get()->map->only(['owner_id', 'status', 'lock_version'])->all());
        $done = $this->row('apprenant-double', $task);
        $this->assertSame(['done', 'Consigne fictive corrigée', '2026-11-24T18:00:00+00:00', null], [$done['status'], $done['description'], $done['dueAt'], $done['personalTargetAt']]);
        $busy = $this->row('polyvalent', $task);
        $this->assertSame(['in_progress', '2026-11-24T18:00:00+00:00', '2026-11-18T08:00:00+00:00'], [$busy['status'], $busy['dueAt'], $busy['personalTargetAt']]);

        // Version dépassée avec un autre contenu : conflit, rien n'est écrasé.
        $this->as('enseignant-a', 'A')->patchJson('/taches/devoirs/'.$task->id, [...$change, 'title' => 'Écrasement fictif'])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->assertSame(['Devoir fictif de méthodologie', 2, 1], [$task->fresh()->title, $task->fresh()->lock_version, DomainEvent::where('type', 'homework.updated')->count()]);
        // Un second changement réel produit un second événement, distinct.
        $this->as('enseignant-a', 'A')->patchJson('/taches/devoirs/'.$task->id, [...$change, 'expected_version' => 2, 'priority' => 'low'])->assertOk()->assertJson(['version' => 3]);
        $this->assertSame(['homework-updated:'.$task->id.':v2', 'homework-updated:'.$task->id.':v3'], DomainEvent::where('type', 'homework.updated')->orderBy('id')->pluck('dedup_key')->all());
    }

    public function test_t11_06_a_learner_sets_a_personal_target_without_changing_the_official_date(): void
    {
        $task = $this->homework();
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'todo', 'personal_target_at' => '2026-11-22T20:00'], json: true)->assertOk()->assertJson(['version' => 1, 'status' => 'todo', 'replayed' => false]);
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'todo', 'personal_target_at' => '2026-11-22T20:00'], json: true)->assertOk()->assertJson(['version' => 1, 'replayed' => true]);
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'done'], json: true)->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->advance('apprenant-double', $task, ['expected_version' => 1, 'status' => 'todo', 'personal_target_at' => 'bientôt'])->assertSessionHasErrors('personal_target_at');
        $this->advance('apprenant-double', $task, ['expected_version' => 1, 'status' => 'todo', 'due_at' => '2027-01-01T10:00', 'personal_target_at' => '2026-11-23T20:00'])->assertSessionHasNoErrors();

        $mine = $this->row('apprenant-double', $task);
        $this->assertSame(['2026-11-20T18:00:00+00:00', '2026-11-23T20:00:00+00:00', 2, false], [$mine['dueAt'], $mine['personalTargetAt'], $mine['version'], $mine['late']]);
        $this->assertSame(['2026-11-20 18:00:00', 1], [$task->fresh()->due_at->format('Y-m-d H:i:s'), $task->fresh()->lock_version]);
        $this->assertNull($this->row('polyvalent', $task)['personalTargetAt']);
        $this->assertSame(1, TaskProgress::count());
        // Le retard reste calculé sur la date officielle, l'objectif personnel ne le masque pas.
        $this->travelTo(now()->setDate(2026, 11, 21)->setTime(8, 0));
        $this->assertTrue($this->row('apprenant-double', $task)['late']);
        // Une tâche personnelle n'a pas de progression séparée.
        $this->advance('independant', $this->personal(), ['expected_version' => 0, 'status' => 'done'])->assertNotFound();
        try {
            DB::table('task_progress')->insert(['task_id' => $task->id, 'owner_id' => $this->user('apprenant-double')->id, 'status' => 'todo']);
            $this->fail('La base a accepté deux avancements pour le même apprenant.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
    }

    public function test_r25_finishing_or_abandoning_cancels_future_reminders_even_after_a_synchronisation_replay(): void
    {
        $service = app(Tasks::class);
        $owner = $this->user('independant');
        $task = $this->personal('independant', ['due_at' => '2026-11-19T09:00']);
        $this->assertTrue($service->shouldRemind($owner, $task->id));

        $finish = ['expected_version' => 1, 'status' => 'done', 'due_at' => '2026-11-19T09:00'];
        $this->edit('independant', $task, $finish, json: true)->assertOk()->assertJson(['version' => 2, 'status' => 'done', 'replayed' => false]);
        $this->assertFalse($service->shouldRemind($owner, $task->id));
        $event = DomainEvent::where('type', 'task.settled')->sole();
        $this->assertEquals(['user_id' => $owner->id, 'task_id' => $task->id, 'status' => 'done'], $event->payload);
        $this->assertSame('task-settled:'.$task->id.':'.$owner->id.':v2', $event->dedup_key);

        // Rejeu de la même demande, puis d'une ancienne modification hors ligne restée « à faire » : la tâche reste terminée.
        $this->edit('independant', $task, $finish, json: true)->assertOk()->assertJson(['version' => 2, 'status' => 'done', 'replayed' => true]);
        $this->edit('independant', $task, ['expected_version' => 1, 'status' => 'todo', 'title' => 'Ancienne modification fictive hors ligne'], json: true)->assertStatus(409);
        $this->edit('independant', $task, ['expected_version' => 1, 'status' => 'in_progress', 'due_at' => '2026-11-19T09:00'], json: true)->assertStatus(409);
        $this->assertSame(['done', 2, 1], [$task->fresh()->status, $task->fresh()->lock_version, DomainEvent::where('type', 'task.settled')->count()]);
        $this->assertFalse($service->shouldRemind($owner, $task->id));

        // Abandon : même règle. Une réouverture explicite, à la bonne version, rétablit les rappels.
        $abandoned = $this->personal('independant', ['title' => 'Tâche fictive abandonnée']);
        $this->edit('independant', $abandoned, ['expected_version' => 1, 'status' => 'abandoned'])->assertSessionHasNoErrors();
        $this->assertFalse($service->shouldRemind($owner, $abandoned->id));
        $this->edit('independant', $abandoned, ['expected_version' => 2, 'status' => 'todo'])->assertSessionHasNoErrors();
        $this->assertTrue($service->shouldRemind($owner, $abandoned->id));
        $this->edit('independant', $abandoned, ['expected_version' => 3, 'status' => 'done'])->assertSessionHasNoErrors();
        $this->assertSame(3, DomainEvent::where('type', 'task.settled')->count());

        // Devoir collectif : seul celui qui a terminé n'est plus rappelé ; le rejeu d'un ancien état ne rouvre rien.
        $homework = $this->homework();
        $double = $this->user('apprenant-double');
        $this->assertTrue($service->shouldRemind($double, $homework->id));
        $this->advance('apprenant-double', $homework, ['expected_version' => 0, 'status' => 'in_progress'], json: true)->assertOk();
        $this->advance('apprenant-double', $homework, ['expected_version' => 1, 'status' => 'abandoned'], json: true)->assertOk()->assertJson(['version' => 2, 'replayed' => false]);
        $this->advance('apprenant-double', $homework, ['expected_version' => 0, 'status' => 'in_progress'], json: true)->assertStatus(409);
        $this->advance('apprenant-double', $homework, ['expected_version' => 1, 'status' => 'abandoned'], json: true)->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->assertFalse($service->shouldRemind($double, $homework->id));
        $this->assertTrue($service->shouldRemind($this->user('polyvalent'), $homework->id));
        $this->assertFalse($service->shouldRemind($this->user('enseignant-a'), $homework->id));
        $this->assertSame(1, DomainEvent::where('type', 'task.settled')->where('subject_uuid', $homework->id)->count());

        // Tâche supprimée ou compte suspendu : plus de rappel.
        $trashed = $this->personal('independant', ['title' => 'Tâche fictive supprimée']);
        $this->as('independant')->delete('/taches/'.$trashed->id, ['expected_version' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($service->shouldRemind($owner, $trashed->id));
        DB::table('users')->where('id', $this->user('polyvalent')->id)->update(['status' => 'suspended']);
        $this->assertFalse($service->shouldRemind($this->user('polyvalent'), $homework->id));
    }

    public function test_t11_07_delete_goes_to_a_seven_day_trash_then_restore_or_controlled_purge(): void
    {
        $task = $this->personal();
        $kept = $this->personal('independant', ['title' => 'Tâche fictive conservée']);
        app(Tasks::class)->addStep($this->user('independant'), $task->id, (string) Str::uuid(), 'Étape fictive');
        $url = '/taches/'.$task->id;

        $this->as('independant')->delete($url)->assertSessionHasErrors('expected_version');
        $this->as('independant')->deleteJson($url, ['expected_version' => 7])->assertStatus(409);
        $this->as('independant')->deleteJson($url, ['expected_version' => 1])->assertOk()->assertJson(['deleted' => true, 'version' => 2]);
        $this->as('independant')->deleteJson($url, ['expected_version' => 1])->assertOk()->assertJson(['deleted' => true, 'version' => 2]);
        $this->assertNull($this->row('independant', $task));
        $this->as('independant')->get('/taches?vue=corbeille')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $task->id)
            ->where('tasks.0.purgeAt', '2026-11-23T08:00:00+00:00')->where('trashDays', 7)->where('filters.vue', 'corbeille'));
        $this->edit('independant', $task, ['expected_version' => 2, 'title' => 'Modification fictive en corbeille'])->assertNotFound();

        $this->travelTo(now()->setDate(2026, 11, 22)->setTime(8, 0));
        $this->artisan('campus:purge-tasks')->expectsOutputToContain('0 tâche(s) purgée(s).')->assertSuccessful();
        $this->as('independant')->post($url.'/restauration')->assertSessionHasNoErrors()->assertRedirect('/taches');
        $this->as('independant')->post($url.'/restauration')->assertSessionHasNoErrors();
        $this->assertSame([null, 3, 1], [$task->fresh()->deleted_at, $task->fresh()->lock_version, TaskStep::where('task_id', $task->id)->count()]);
        $this->assertNotNull($this->row('independant', $task));

        $this->as('independant')->delete($url, ['expected_version' => 3])->assertSessionHasNoErrors();
        $this->travelTo(now()->setDate(2026, 11, 29)->setTime(8, 1));
        // Délai dépassé : la restauration n'est plus proposée, puis la purge supprime la tâche et ses étapes, et rien d'autre.
        $this->as('independant')->get('/taches?vue=corbeille')->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
        $this->as('independant')->post($url.'/restauration')->assertNotFound();
        $this->artisan('campus:purge-tasks', ['--limit' => 10])->expectsOutputToContain('1 tâche(s) purgée(s).')->assertSuccessful();
        $this->assertSame([0, 0, 1], [Task::whereKey($task->id)->count(), TaskStep::count(), Task::whereKey($kept->id)->count()]);
        $this->artisan('campus:purge-tasks')->expectsOutputToContain('0 tâche(s) purgée(s).')->assertSuccessful();
    }

    public function test_t11_08_a_withdrawn_homework_keeps_progress_until_restored_or_purged(): void
    {
        $task = $this->homework();
        $this->advance('apprenant-double', $task, ['expected_version' => 0, 'status' => 'in_progress'])->assertSessionHasNoErrors();
        $url = '/taches/devoirs/'.$task->id;

        $this->as('enseignant-a', 'A')->deleteJson($url, ['expected_version' => 5])->assertStatus(409);
        $this->as('enseignant-a', 'A')->deleteJson($url, ['expected_version' => 1])->assertOk()->assertJson(['deleted' => true, 'version' => 2]);
        $this->as('enseignant-a', 'A')->deleteJson($url, ['expected_version' => 1])->assertOk()->assertJson(['deleted' => true, 'version' => 2]);
        $this->assertNull($this->row('apprenant-double', $task));
        $this->advance('apprenant-double', $task, ['expected_version' => 1, 'status' => 'done'])->assertNotFound();
        $this->assertFalse(app(Tasks::class)->shouldRemind($this->user('polyvalent'), $task->id));
        $this->as('enseignant-a', 'A')->get('/taches/devoirs')->assertInertia(fn (Assert $page) => $page->has('homework', 0)->has('withdrawn', 1));
        $this->as('apprenant-double')->get('/taches?vue=corbeille')->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

        $this->as('enseignant-a', 'A')->postJson($url.'/retablissement')->assertOk()->assertJson(['deleted' => false, 'version' => 3]);
        $this->as('enseignant-a', 'A')->postJson($url.'/retablissement')->assertOk()->assertJson(['deleted' => false, 'version' => 3]);
        $this->assertSame('in_progress', $this->row('apprenant-double', $task)['status']);
        $this->assertSame(['homework.published', 'homework.withdrawn', 'homework.restored'], DomainEvent::where('subject_uuid', $task->id)->orderBy('id')->pluck('type')->all());

        $this->as('enseignant-a', 'A')->delete($url, ['expected_version' => 3])->assertSessionHasNoErrors();
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(8, 0));
        $this->as('enseignant-a', 'A')->post($url.'/retablissement')->assertNotFound();
        $this->artisan('campus:purge-tasks')->expectsOutputToContain('1 tâche(s) purgée(s).')->assertSuccessful();
        $this->assertSame([0, 0], [Task::count(), TaskProgress::count()]);
    }

    public function test_t11_09_microtasks_are_private_simple_steps_and_the_first_open_one_is_the_next_action(): void
    {
        $task = $this->personal();
        $homework = $this->homework();
        $key = (string) Str::uuid();
        $steps = '/taches/'.$task->id.'/etapes';

        $this->as('independant')->post($steps, ['client_key' => $key, 'title' => ''])->assertSessionHasErrors('title');
        $this->as('independant')->post($steps, ['client_key' => $key, 'title' => str_repeat('x', 161)])->assertSessionHasErrors('title');
        $first = $this->as('independant')->postJson($steps, ['client_key' => $key, 'title' => 'Relire le chapitre fictif'])->assertOk();
        $this->as('independant')->postJson($steps, ['client_key' => $key, 'title' => 'Relire le chapitre fictif'])->assertOk()->assertJson(['id' => $first->json('id')]);
        $this->as('independant')->post($steps, ['client_key' => (string) Str::uuid(), 'title' => 'Faire trois exercices fictifs'])->assertSessionHasNoErrors();
        $this->assertSame(2, TaskStep::count());
        $row = $this->row('independant', $task);
        $this->assertSame([['Relire le chapitre fictif', false], ['Faire trois exercices fictifs', false]], collect($row['steps'])->map(fn ($step) => [$step['title'], $step['done']])->all());
        $this->assertSame('Relire le chapitre fictif', $row['nextStep']);

        $this->as('independant')->patch($steps.'/'.$first->json('id'), ['done' => true])->assertSessionHasNoErrors();
        $this->as('independant')->patch($steps.'/'.$first->json('id'), ['done' => true])->assertSessionHasNoErrors();
        $this->assertSame('Faire trois exercices fictifs', $this->row('independant', $task)['nextStep']);
        $this->as('independant')->delete($steps.'/'.$first->json('id'))->assertSessionHasNoErrors();
        $this->as('independant')->delete($steps.'/'.$first->json('id'))->assertNotFound();
        // Terminer les étapes ne change pas l'état de la tâche : c'est la personne qui décide.
        $this->assertSame(['todo', 1], [$task->fresh()->status, $task->fresh()->lock_version]);

        // Sur un devoir collectif, les étapes de chacun sont privées.
        $this->as('apprenant-double')->post('/taches/'.$homework->id.'/etapes', ['client_key' => (string) Str::uuid(), 'title' => 'Étape fictive de A'])->assertSessionHasNoErrors();
        $this->assertSame(['Étape fictive de A'], collect($this->row('apprenant-double', $homework)['steps'])->pluck('title')->all());
        $this->assertSame([], $this->row('polyvalent', $homework)['steps']);
        $mine = TaskStep::where('task_id', $homework->id)->sole();
        $this->as('polyvalent')->patch('/taches/'.$homework->id.'/etapes/'.$mine->id, ['done' => true])->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/taches/'.$homework->id.'/etapes', ['client_key' => (string) Str::uuid(), 'title' => 'Étape fictive enseignant'])->assertNotFound();
        $this->assertStringNotContainsString('Étape fictive de A', $this->as('enseignant-a', 'A')->get('/taches/devoirs')->getContent());

        config(['campus.tasks.max_steps' => 2]);
        $this->as('independant')->post($steps, ['client_key' => (string) Str::uuid(), 'title' => 'Deuxième étape fictive'])->assertSessionHasNoErrors();
        $this->as('independant')->post($steps, ['client_key' => (string) Str::uuid(), 'title' => 'Étape fictive en trop'])->assertSessionHasErrors('title');
    }
}
