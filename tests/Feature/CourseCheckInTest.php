<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CourseCheckIn;
use App\Models\CourseCheckInEvent;
use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\DomainEvent;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\CourseCheckIns;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class CourseCheckInTest extends TestCase
{
    use DatabaseTransactions;

    private const PRIVATE_TEXT = 'Je n’ai pas compris la deuxième partie — texte privé fictif';

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

    private function rules(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Révision fictive', 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16',
            'start_time' => '06:00', 'end_time' => '07:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides);
    }

    /** Événement personnel de l'indépendant, déjà terminé à 8 h. */
    private function personal(array $overrides = [], string $owner = 'independant'): CourseOccurrence
    {
        $data = $this->rules($overrides);
        $this->as($owner)->post('/planning/series', $data)->assertSessionHasNoErrors();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    /** Cours institutionnel de A-G1, le lundi 16 novembre de 9 h à 10 h, publié. */
    private function published(): CourseOccurrence
    {
        $teacher = $this->member('enseignant-a');
        $data = $this->rules(['title' => 'Méthodologie fictive publiée', 'kind' => 'weekly', 'valid_until' => '2026-12-31', 'start_time' => '09:00', 'end_time' => '10:00',
            'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id], 'subject_id' => Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $teacher->id]);
        $this->as('admin-a', 'A')->post('/planning/series', $data)->assertSessionHasNoErrors();
        $this->publish();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail()->occurrences()->orderBy('original_date')->firstOrFail();
    }

    private function publish(): void
    {
        $props = $this->as('admin-a', 'A')->get('/planning/publication')->viewData('page')['props'];
        $this->as('admin-a', 'A')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => $props['current']['version'] ?? 0, 'preview_hash' => $props['preview']['hash'], 'reason' => 'Publication fictive'])->assertSessionHasNoErrors();
    }

    private function send(string $name, CourseOccurrence $occurrence, array $data = [], bool $json = false): TestResponse
    {
        $payload = ['action' => 'save', 'expected_version' => 0, 'summary' => self::PRIVATE_TEXT, ...$data];
        $url = '/planning/occurrences/'.$occurrence->id.'/retour';

        return $json ? $this->as($name)->putJson($url, $payload) : $this->as($name)->put($url, $payload);
    }

    public function test_r21_an_ended_course_is_offered_in_the_app_without_any_notification_and_with_no_penalty_states(): void
    {
        $ended = $this->personal();
        $future = $this->personal(['title' => 'Cours fictif à venir', 'start_time' => '18:00', 'end_time' => '19:00']);
        $old = $this->personal(['title' => 'Cours fictif ancien', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02']);

        $this->as('independant')->get('/retours')->assertOk()->assertInertia(fn (Assert $page) => $page->component('CheckIns')
            ->has('pending', 1)->where('pending.0.occurrenceId', $ended->id)->where('pending.0.deferred', false)->where('pending.0.provenance', 'personal')->has('history', 0));
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->has('dashboard.pendingCheckIns', 1)->where('dashboard.pendingCheckIns.0.occurrenceId', $ended->id));
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('dashboard', null));
        $this->as('independant')->get('/planning?view=day&date=2026-11-16')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $ended->id)['canCheckIn'] === true && collect($rows)->firstWhere('id', $future->id)['canCheckIn'] === false));
        $this->as('independant')->get('/planning/occurrences/'.$ended->id.'/retour')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('CheckIn')->where('checkIn', null)->where('canWrite', true)->where('course.title', 'Révision fictive'));
        $this->as('independant')->get('/planning/occurrences/'.$future->id.'/retour')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWrite', false)->where('notStarted', true));
        $this->send('independant', $future)->assertSessionHasErrors('summary');
        $this->assertTrue(app(CourseCheckIns::class)->shouldRemind($this->user('independant'), $ended->id));

        $this->send('independant', $ended, ['action' => 'defer', 'summary' => null])->assertRedirect('/retours');
        $this->as('independant')->get('/retours')->assertInertia(fn (Assert $page) => $page->has('pending', 1)->where('pending.0.deferred', true)->where('history.0.status', 'deferred'));
        $this->assertTrue(app(CourseCheckIns::class)->shouldRemind($this->user('independant'), $ended->id));
        $this->send('independant', $ended, ['action' => 'ignore', 'summary' => null, 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->as('independant')->get('/retours')->assertInertia(fn (Assert $page) => $page->has('pending', 0)->where('history.0.statusLabel', 'Ignoré'));
        $this->send('independant', $ended, ['expected_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(['completed', 3], [CourseCheckIn::sole()->status, CourseCheckIn::sole()->lock_version]);
        $this->send('independant', $old, ['action' => 'nothing', 'summary' => null])->assertSessionHasNoErrors();
        $this->assertSame(2, CourseCheckIn::count());
    }

    public function test_one_private_check_in_per_owner_and_course_with_all_fields_optional_and_limited_history(): void
    {
        $course = $this->personal();
        $this->send('independant', $course, ['summary' => null])->assertSessionHasErrors('summary');
        $this->send('independant', $course, ['understanding' => 7])->assertSessionHasErrors('understanding');
        $this->assertSame(0, CourseCheckIn::count());

        $this->send('independant', $course, ['summary' => null, 'understanding' => 2])->assertRedirect('/retours')->assertSessionHas('status');
        $checkIn = CourseCheckIn::sole();
        $this->assertSame([$this->user('independant')->id, $course->id, 'completed', null, 2, 1], [$checkIn->owner_id, $checkIn->course_occurrence_id, $checkIn->status, $checkIn->summary, $checkIn->understanding, $checkIn->lock_version]);

        $this->send('independant', $course, ['expected_version' => 1, 'summary' => self::PRIVATE_TEXT, 'work_to_do' => 'Exercices fictifs 1 à 3', 'points_to_review' => 'Définitions fictives', 'understanding' => 3])->assertSessionHasNoErrors();
        $this->send('independant', $course, ['expected_version' => 2, 'action' => 'nothing'])->assertSessionHasNoErrors();
        $checkIn->refresh();
        $this->assertSame(['nothing', null, null, null, null, 3], [$checkIn->status, $checkIn->summary, $checkIn->work_to_do, $checkIn->points_to_review, $checkIn->understanding, $checkIn->lock_version]);
        $this->send('independant', $course, ['expected_version' => 3, 'action' => 'defer'])->assertSessionHasErrors('summary');
        $this->send('independant', $course, ['expected_version' => 3, 'action' => 'ignore'])->assertSessionHasErrors('summary');

        $events = CourseCheckInEvent::orderBy('version')->get();
        $this->assertSame([1, 2, 3], $events->pluck('version')->all());
        $this->assertSame(['understanding', 'status'], $events[0]->changed_fields);
        $this->assertEqualsCanonicalizing(['summary', 'work_to_do', 'points_to_review', 'understanding'], $events[1]->changed_fields);
        $this->assertStringNotContainsString('fictif', json_encode($events->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('privé', json_encode(AuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE));

        try {
            DB::table('course_check_ins')->insert(['owner_id' => $checkIn->owner_id, 'course_occurrence_id' => $course->id, 'status' => 'nothing', 'course_title' => 'x',
                'course_starts_at' => now(), 'course_ends_at' => now(), 'content_hash' => str_repeat('a', 64)]);
            $this->fail('La base a accepté un second retour pour le même cours.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
    }

    public function test_a_double_click_or_replay_creates_one_check_in_and_one_task_and_a_stale_version_conflicts(): void
    {
        $course = $this->personal();
        $payload = ['work_to_do' => "Exercices fictifs 4 et 5\nPour jeudi", 'create_task' => true];

        $first = $this->send('independant', $course, $payload, json: true)->assertOk()->assertJson(['version' => 1, 'status' => 'completed', 'replayed' => false]);
        $second = $this->send('independant', $course, $payload, json: true)->assertOk()->assertJson(['version' => 1, 'replayed' => true]);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('task_id'), $second->json('task_id'));
        $this->assertSame([1, 1, 1], [CourseCheckIn::count(), Task::count(), CourseCheckInEvent::count()]);
        $task = Task::sole();
        $this->assertSame(['Exercices fictifs 4 et 5', $this->user('independant')->id, $course->id, CourseCheckIn::sole()->id, 'personal', 'todo'],
            [$task->title, $task->owner_id, $task->source_occurrence_id, $task->source_check_in_id, $task->scope, $task->status]);

        $this->send('independant', $course, ['summary' => 'Autre contenu fictif'], json: true)->assertStatus(409)
            ->assertJson(['code' => 'VERSION_CONFLICT'])->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
        $this->send('independant', $course, ['summary' => 'Autre contenu fictif', 'expected_version' => 5], json: true)->assertStatus(409);
        $this->send('independant', $course, ['summary' => 'Autre contenu fictif'])->assertStatus(409);
        $this->assertSame(self::PRIVATE_TEXT, CourseCheckIn::sole()->summary);

        $this->send('independant', $course, [...$payload, 'expected_version' => 1, 'task_title' => 'Second titre fictif'], json: true)->assertOk()->assertJson(['version' => 2]);
        $this->assertSame([1, 'Exercices fictifs 4 et 5'], [Task::count(), Task::sole()->title]);
        $this->as('independant')->get('/planning/occurrences/'.$course->id.'/retour')->assertInertia(fn (Assert $page) => $page
            ->where('checkIn.version', 2)->where('checkIn.task.title', 'Exercices fictifs 4 et 5')->where('checkIn.workToDo', "Exercices fictifs 4 et 5\nPour jeudi"));
    }

    public function test_the_task_and_the_check_in_are_saved_together_or_not_at_all(): void
    {
        $course = $this->personal();
        $this->send('independant', $course, ['create_task' => true])->assertSessionHasErrors('task_title');
        $this->send('independant', $course, ['create_task' => true, 'task_title' => str_repeat('x', 256)])->assertSessionHasErrors('task_title');
        $this->assertSame([0, 0, 0], [CourseCheckIn::count(), Task::count(), DomainEvent::count()]);

        $this->send('independant', $course, ['create_task' => true, 'task_title' => 'Titre fictif choisi'])->assertSessionHasNoErrors();
        $this->assertSame([1, 'Titre fictif choisi', null], [CourseCheckIn::count(), Task::sole()->title, Task::sole()->description]);
    }

    public function test_r22_saving_a_check_in_settles_the_reminder_rule_once_without_leaking_private_content(): void
    {
        $course = $this->personal();
        $service = app(CourseCheckIns::class);
        $user = $this->user('independant');
        $this->assertTrue($service->shouldRemind($user, $course->id));

        $this->send('independant', $course, ['points_to_review' => self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        $this->assertFalse($service->shouldRemind($user, $course->id));
        $event = DomainEvent::where('type', 'checkin.settled')->sole();
        $this->assertEquals(['user_id' => $user->id, 'occurrence_id' => $course->id, 'status' => 'completed'], $event->payload);
        $this->assertSame([$course->id, 'checkin-settled:'.CourseCheckIn::sole()->id], [$event->subject_uuid, $event->dedup_key]);
        $this->assertStringNotContainsString('privé', json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE));

        $this->send('independant', $course, ['expected_version' => 1, 'summary' => 'Modification fictive'])->assertSessionHasNoErrors();
        $this->send('independant', $course, ['expected_version' => 2, 'action' => 'nothing'])->assertSessionHasNoErrors();
        $this->assertSame(1, DomainEvent::where('type', 'checkin.settled')->count());
        $this->as('independant')->get('/retours')->assertInertia(fn (Assert $page) => $page->has('pending', 0)->has('history', 1));
        $this->assertFalse($service->shouldRemind($this->user('editeur'), $course->id));
    }

    public function test_r18_a_cancelled_course_expects_no_check_in_and_no_reminder(): void
    {
        $course = $this->personal();
        $this->as('independant')->patch('/planning/occurrences/'.$course->id, ['lock_version' => 1, 'action' => 'cancel', 'reason' => 'Annulation fictive'])->assertSessionHasNoErrors();

        $this->assertFalse(app(CourseCheckIns::class)->shouldRemind($this->user('independant'), $course->id));
        $this->as('independant')->get('/retours')->assertInertia(fn (Assert $page) => $page->has('pending', 0));
        $this->as('independant')->get('/planning/occurrences/'.$course->id.'/retour')->assertOk()->assertInertia(fn (Assert $page) => $page->where('course.cancelled', true)->where('canWrite', false));
        $this->send('independant', $course)->assertSessionHasErrors('summary');
        $this->assertSame([0, 0], [CourseCheckIn::count(), DomainEvent::where('type', 'checkin.settled')->count()]);
    }

    public function test_a_learner_checks_in_on_a_published_course_and_a_reschedule_keeps_the_same_check_in(): void
    {
        $course = $this->published();
        $unpublished = $this->as('admin-a', 'A')->post('/planning/series', $draft = $this->rules(['title' => 'Brouillon fictif non publié', 'start_time' => '06:00', 'end_time' => '07:00',
            'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id], 'subject_id' => $course->subject_id, 'teacher_membership_id' => $course->teacher_membership_id]));
        $unpublished->assertSessionHasNoErrors();
        $draftOccurrence = CourseSeries::where('request_key', $draft['request_key'])->firstOrFail()->occurrences()->firstOrFail();

        $this->send('apprenant-double', $course)->assertSessionHasErrors('summary');
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(10, 6));
        $this->as('apprenant-double')->get('/retours')->assertInertia(fn (Assert $page) => $page->has('pending', 1)->where('pending.0.occurrenceId', $course->id)->where('pending.0.provenance', 'institutional'));
        $this->as('apprenant-double', 'A')->get('/planning?view=day&date=2026-11-16')->assertInertia(fn (Assert $page) => $page->where('rows.0.canCheckIn', true)->where('rows.0.checkIn', null));
        $this->send('apprenant-double', $draftOccurrence)->assertNotFound();
        $this->send('independant', $course)->assertNotFound();
        $this->send('admin-b', $course)->assertNotFound();

        $this->send('apprenant-double', $course, ['understanding' => 1])->assertSessionHasNoErrors();
        $checkIn = CourseCheckIn::sole();
        $this->assertSame([$this->user('apprenant-double')->id, 'Méthodologie fictive publiée', '2026-11-16 09:00:00'], [$checkIn->owner_id, $checkIn->course_title, $checkIn->course_starts_at->format('Y-m-d H:i:s')]);
        $this->as('apprenant-double', 'A')->get('/planning?view=day&date=2026-11-16')->assertInertia(fn (Assert $page) => $page->where('rows.0.checkIn', 'completed'));

        $next = CourseOccurrence::where('course_series_id', $course->course_series_id)->where('original_date', '2026-11-23')->firstOrFail();
        $this->as('admin-a', 'A')->patch('/planning/occurrences/'.$course->id, ['lock_version' => $course->fresh()->lock_version, 'action' => 'reschedule', 'date' => '2026-11-17', 'start_time' => '14:00', 'end_time' => '15:00', 'reason' => 'Report fictif'])->assertSessionHasNoErrors();
        $this->publish();
        $this->as('apprenant-double')->get('/planning/occurrences/'.$course->id.'/retour')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('course.startsAt', '2026-11-17T14:00:00+00:00')->where('checkIn.version', 1)->where('checkIn.understanding', 1));
        $this->send('apprenant-double', $course, ['expected_version' => 1, 'summary' => 'Après le report fictif'])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, '2026-11-17 14:00:00'], [CourseCheckIn::count(), $checkIn->fresh()->lock_version, $checkIn->fresh()->course_starts_at->format('Y-m-d H:i:s')]);
        $this->send('apprenant-double', $next)->assertSessionHasErrors('summary');

        $this->member('apprenant-double')->revoke();
        $this->as('apprenant-double')->get('/planning/occurrences/'.$course->id.'/retour')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('course.stillAccessible', false)->where('checkIn.summary', 'Après le report fictif')->where('canWrite', true));
        $this->send('apprenant-double', $course, ['expected_version' => 2, 'summary' => 'Toujours à moi'])->assertSessionHasNoErrors();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(10, 6));
        $this->send('apprenant-double', $next)->assertNotFound();
        $this->assertFalse(app(CourseCheckIns::class)->shouldRemind($this->user('apprenant-double'), $next->id));
        $this->assertSame(1, CourseCheckIn::count());
    }

    public function test_r58_no_teacher_administrator_or_platform_role_can_read_a_private_check_in(): void
    {
        $course = $this->published();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(10, 6));
        $this->send('apprenant-double', $course, ['summary' => self::PRIVATE_TEXT, 'points_to_review' => self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        $checkIn = CourseCheckIn::sole();
        $url = '/planning/occurrences/'.$course->id.'/retour';

        foreach ([['enseignant-a', 'A'], ['admin-a', 'A'], ['admin-b', 'B'], ['plateforme', null], ['editeur', null], ['independant', null]] as [$name, $letter]) {
            $actor = fn () => in_array($name, ['plateforme', 'editeur'], true)
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            $actor()->get($url)->assertNotFound();
            $actor()->put($url, ['action' => 'save', 'expected_version' => 1, 'summary' => 'Écrasement fictif'])->assertNotFound();
            $actor()->get('/retours')->assertOk()->assertDontSee('texte privé fictif')->assertInertia(fn (Assert $page) => $page->has('history', 0)->has('pending', 0));
            $actor()->get('/')->assertOk()->assertDontSee('texte privé fictif');
            $this->assertTrue(Gate::forUser($this->user($name))->denies('view', $checkIn));
            $this->assertTrue(Gate::forUser($this->user($name))->denies('update', $checkIn));
        }
        foreach (['/planning?view=day&date=2026-11-16&source=draft', '/planning?view=day&date=2026-11-16&source=published', '/planning/publication', '/administration/etablissement', '/administration/etablissement/membres/export'] as $adminUrl) {
            $response = $this->as('admin-a', 'A')->get($adminUrl)->assertOk();
            $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
            $this->assertStringNotContainsString('texte privé fictif', (string) $content);
        }
        $this->as('enseignant-a', 'A')->get('/planning?view=day&date=2026-11-16')->assertDontSee('texte privé fictif')->assertInertia(fn (Assert $page) => $page->where('rows.0.canCheckIn', false)->where('rows.0.checkIn', null));

        $this->assertTrue(Gate::forUser($this->user('apprenant-double'))->allows('view', $checkIn));
        $this->assertSame(self::PRIVATE_TEXT, $checkIn->fresh()->summary);
        $routes = collect(app('router')->getRoutes())->map->uri()->filter(fn (string $uri) => str_contains($uri, 'retour'))->values()->all();
        // La route de dépôt de pièce jointe (lot 14) est elle aussi résolue pour le seul compte connecté : elle ne désigne jamais le retour d'autrui.
        $this->assertEqualsCanonicalizing(['retours', 'planning/occurrences/{occurrence}/retour', 'planning/occurrences/{occurrence}/retour', 'planning/occurrences/{occurrence}/retour/pieces-jointes', 'planning/publication/{version}/retour'], $routes);
        foreach ([AuditLog::query()->get(), DomainEvent::query()->get(), CourseCheckInEvent::query()->get()] as $records) {
            $this->assertStringNotContainsString('privé', json_encode($records->toArray(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_guests_and_suspended_accounts_cannot_reach_check_ins(): void
    {
        $course = $this->personal();
        $this->freshDevice()->get('/retours')->assertRedirect('/connexion');
        $this->freshDevice()->put('/planning/occurrences/'.$course->id.'/retour', ['action' => 'nothing', 'expected_version' => 0])->assertRedirect('/connexion');
        DB::table('users')->where('id', $this->user('independant')->id)->update(['status' => 'suspended']);
        $this->as('independant')->get('/retours')->assertRedirect('/connexion');
        $this->assertFalse(app(CourseCheckIns::class)->shouldRemind($this->user('independant'), $course->id));
        $this->assertSame(0, CourseCheckIn::count());
    }
}
