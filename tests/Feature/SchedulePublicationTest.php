<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\CourseOccurrenceRevision;
use App\Models\CourseSeries;
use App\Models\DomainEvent;
use App\Models\Group;
use App\Models\Membership;
use App\Models\PublishedOccurrence;
use App\Models\SchedulePublication;
use App\Models\SchedulePublicationChange;
use App\Models\Subject;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\ScheduleAccess;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class SchedulePublicationTest extends TestCase
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

    private function as(string $name, ?string $letter = 'A'): static
    {
        return $this->freshDevice()->actingAs($this->user($name))
            ->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    /** Crée une série hebdomadaire institutionnelle dans le brouillon de A : huit lundis (ou autre jour) à partir du 16 novembre 2026. */
    private function series(array $overrides = []): CourseSeries
    {
        $teacher = $this->member('enseignant-a');
        $data = array_replace([
            'request_key' => (string) Str::uuid(), 'title' => 'Cours fictif publié', 'kind' => 'weekly', 'valid_from' => '2026-11-16', 'valid_until' => '2027-02-28',
            'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [],
            'active_weeks' => [], 'holidays' => [], 'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id],
            'subject_id' => Subject::where('institution_id', $teacher->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $teacher->id,
        ], $overrides);
        $this->as('admin-a')->post('/planning/series', $data)->assertSessionHasNoErrors()->assertRedirect();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail();
    }

    /** @return array{hash: string, version: int, counts: array<string, int>, total: int} */
    private function preview(string $admin = 'admin-a', string $letter = 'A'): array
    {
        $props = $this->as($admin, $letter)->get('/planning/publication')->assertOk()->viewData('page')['props'];

        return ['hash' => $props['preview']['hash'], 'version' => $props['current']['version'] ?? 0, 'counts' => $props['preview']['counts'], 'total' => $props['preview']['total']];
    }

    private function publish(array $overrides = []): TestResponse
    {
        $preview = $this->preview();

        return $this->as('admin-a')->post('/planning/publication', array_replace([
            'request_key' => (string) Str::uuid(), 'expected_version' => $preview['version'], 'preview_hash' => $preview['hash'], 'reason' => 'Publication fictive de test',
        ], $overrides));
    }

    private function rollback(int $version, array $overrides = []): TestResponse
    {
        return $this->as('admin-a')->post('/planning/publication/'.$version.'/retour', array_replace([
            'request_key' => (string) Str::uuid(), 'expected_version' => $this->preview()['version'], 'reason' => 'Retour arrière fictif',
        ], $overrides));
    }

    private function occurrence(CourseSeries $series, string $date): CourseOccurrence
    {
        return $series->occurrences()->where('original_date', $date)->firstOrFail();
    }

    private function change(CourseOccurrence $occurrence, array $data): void
    {
        $this->as('admin-a')->patch('/planning/occurrences/'.$occurrence->id, ['lock_version' => $occurrence->fresh()->lock_version, 'reason' => 'Modification fictive', ...$data])
            ->assertSessionHasNoErrors();
    }

    /** @return list<array<string, mixed>> */
    private function learnerRows(string $date, string $name = 'apprenant-double', string $letter = 'A'): array
    {
        return $this->as($name, $letter)->get('/planning?view=day&date='.$date)->assertOk()->viewData('page')['props']['rows'];
    }

    private function events(SchedulePublication $publication)
    {
        return DomainEvent::where('type', 'schedule.occurrence.changed')->where('dedup_key', 'like', 'schedule-publication:'.$publication->id.':%')->get();
    }

    public function test_r16_a_previewed_publication_creates_an_immutable_version_seen_only_by_concerned_members(): void
    {
        $series = $this->series();
        $this->assertSame([], $this->learnerRows('2026-11-23'));
        $this->as('apprenant-double')->get('/planning?date=2026-11-23&source=draft')->assertInertia(fn (Assert $page) => $page
            ->where('source', 'published')->where('publication', null)->where('editable', false)->has('rows', 0));

        $preview = $this->preview();
        $this->assertSame(['added' => 8], $preview['counts']);
        $this->assertSame(0, $preview['version']);
        $this->as('admin-a')->post('/planning/publication', ['request_key' => (string) Str::uuid()])->assertSessionHasErrors(['expected_version', 'preview_hash', 'reason']);
        $this->publish(['preview_hash' => str_repeat('0', 64)])->assertStatus(409);
        $this->publish(['expected_version' => 3])->assertStatus(409);
        $this->assertSame(0, SchedulePublication::count());
        $this->assertSame(0, DomainEvent::count());

        $key = (string) Str::uuid();
        $this->publish(['request_key' => $key])->assertRedirect('/planning/publication');
        $publication = SchedulePublication::sole();
        $this->assertSame([1, 'publish', $this->user('admin-a')->id, 'Publication fictive de test', ['added' => 8]],
            [$publication->version, $publication->kind, $publication->author_id, $publication->reason, $publication->summary]);
        $this->assertSame(8, $publication->changes()->count());
        $this->assertSame(8, PublishedOccurrence::count());
        $this->assertSame(8, $this->events($publication)->count());
        $this->assertSame(1, DomainEvent::where('type', 'schedule.published')->count());
        $this->assertSame(1, AuditLog::where('action', 'schedule.publication.publish')->count());

        $rows = $this->learnerRows('2026-11-23');
        $this->assertCount(1, $rows);
        $this->assertSame([$this->occurrence($series, '2026-11-23')->id, 'active', 1, 'institutional'], [$rows[0]['id'], $rows[0]['status'], $rows[0]['version'], $rows[0]['provenance']]);
        $this->assertCount(1, $this->learnerRows('2026-11-23', 'polyvalent'));
        $this->assertSame([], $this->learnerRows('2026-11-23', 'apprenant-double', 'B'));
        $this->as('admin-b', 'B')->get('/planning?view=day&date=2026-11-23&source=published')->assertInertia(fn (Assert $page) => $page->has('rows', 0)->where('publication', null));
        $this->as('apprenant-double', null)->get('/planning?view=day&date=2026-11-23')->assertInertia(fn (Assert $page) => $page->where('source', 'personal')->has('rows', 0));

        $this->publish(['request_key' => $key, 'preview_hash' => $publication->content_hash, 'expected_version' => 0])->assertRedirect('/planning/publication');
        $this->publish()->assertSessionHasErrors('publication');
        $this->assertSame(1, SchedulePublication::count());
        $this->assertSame(9, DomainEvent::count());

        try {
            $publication->update(['reason' => 'réécrit']);
            $this->fail('Une version publiée a été modifiée.');
        } catch (LogicException) {
            $this->assertSame('Publication fictive de test', $publication->fresh()->reason);
        }
        $this->expectException(LogicException::class);
        SchedulePublicationChange::firstOrFail()->delete();
    }

    public function test_r16_rollback_restores_future_courses_as_a_new_version_and_keeps_identifiers_and_history(): void
    {
        $series = $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $cancelled = $this->occurrence($series, '2026-11-30');
        $moved = $this->occurrence($series, '2026-12-07');
        $this->change($cancelled, ['action' => 'cancel']);
        $this->change($moved, ['action' => 'reschedule', 'date' => '2026-12-08', 'start_time' => '14:00', 'end_time' => '15:00', 'room' => 'B12']);

        $this->assertSame('active', $this->learnerRows('2026-11-30')[0]['status']);
        $this->assertCount(1, $this->learnerRows('2026-12-07'));
        $this->assertSame(['cancelled' => 1, 'updated' => 1], collect($this->preview()['counts'])->sortKeys()->all());
        $this->as('admin-a')->get('/planning/publication')->assertInertia(fn (Assert $page) => $page
            ->where('preview.changes', fn ($changes) => collect($changes)->firstWhere('id', $moved->id)['before']['startsAt'] === '2026-12-07T09:00:00Z'
                && collect($changes)->firstWhere('id', $moved->id)['after']['startsAt'] === '2026-12-08T14:00:00Z'
                && collect($changes)->firstWhere('id', $moved->id)['after']['room'] === 'B12'
                && collect($changes)->firstWhere('id', $cancelled->id)['change'] === 'cancelled'));

        $this->publish(['reason' => 'Annulation et report fictifs'])->assertSessionHasNoErrors();
        $second = SchedulePublication::where('version', 2)->sole();
        $this->assertSame(2, $this->events($second)->count());
        $this->assertSame('cancelled', $this->learnerRows('2026-11-30')[0]['status']);
        $this->assertSame([], $this->learnerRows('2026-12-07'));
        $this->assertSame(2, $this->learnerRows('2026-12-08')[0]['version']);
        $this->as('admin-a')->get('/planning/publication?version=2')->assertInertia(fn (Assert $page) => $page
            ->where('detail.version', 2)->where('detail.reason', 'Annulation et report fictifs')->has('detail.changes', 2));

        $ids = CourseOccurrence::orderBy('id')->pluck('id')->all();
        $revisions = CourseOccurrenceRevision::count();
        $this->rollback(5)->assertNotFound();
        $this->rollback(2)->assertNotFound();
        $this->rollback(1, ['expected_version' => 1])->assertStatus(409);
        $key = (string) Str::uuid();
        $this->rollback(1, ['request_key' => $key])->assertRedirect('/planning/publication');

        $third = SchedulePublication::where('version', 3)->sole();
        $this->assertSame(['rollback', 1, ['restored' => 1, 'updated' => 1]], [$third->kind, $third->source_version, collect($third->summary)->sortKeys()->all()]);
        $this->assertSame(3, SchedulePublication::count());
        $this->assertSame('Publication fictive de test', SchedulePublication::where('version', 1)->sole()->reason);
        $this->assertSame($ids, CourseOccurrence::orderBy('id')->pluck('id')->all());
        $this->assertSame($revisions + 2, CourseOccurrenceRevision::count());
        $this->assertSame(['active', '2026-11-30'], [$cancelled->fresh()->status, $cancelled->fresh()->original_date->toDateString()]);
        $this->assertSame(['2026-12-07 09:00:00', null, '2026-12-07'], [$moved->fresh()->starts_at->format('Y-m-d H:i:s'), $moved->fresh()->room, $moved->fresh()->original_date->toDateString()]);
        $this->assertSame('active', $this->learnerRows('2026-11-30')[0]['status']);
        $this->assertSame($moved->id, $this->learnerRows('2026-12-07')[0]['id']);
        $this->assertSame([], $this->learnerRows('2026-12-08'));
        $events = $this->events($third);
        $this->assertSame([$cancelled->id, $moved->id], $events->pluck('subject_uuid')->sort()->values()->all() === collect([$cancelled->id, $moved->id])->sort()->values()->all() ? [$cancelled->id, $moved->id] : []);
        $this->assertTrue($events->every(fn (DomainEvent $event) => $event->payload['kind'] === 'rollback' && $event->payload['affects_future'] === true));

        $this->assertSame(0, $this->preview()['total']);
        $this->publish()->assertSessionHasErrors('publication');
        $this->rollback(1, ['request_key' => $key, 'expected_version' => 2])->assertRedirect('/planning/publication');
        $this->rollback(1)->assertSessionHasErrors('publication');
        $this->assertSame(3, SchedulePublication::count());
        $this->assertSame(8 + 1 + 2 + 1 + 2 + 1, DomainEvent::count());
    }

    public function test_rollback_withdraws_courses_added_later_without_deleting_them_and_leaves_the_past_untouched(): void
    {
        $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $added = $this->series(['title' => 'Cours fictif ajouté ensuite', 'weekdays' => [2], 'valid_from' => '2026-11-17', 'start_time' => '11:00', 'end_time' => '12:00']);
        $this->publish()->assertSessionHasNoErrors();
        $this->assertCount(1, $this->learnerRows('2026-11-17'));
        $this->assertCount(1, $this->learnerRows('2026-11-24'));

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(8, 0));
        $occurrences = CourseOccurrence::count();
        $this->rollback(1)->assertSessionHasNoErrors();
        $third = SchedulePublication::where('version', 3)->sole();

        $this->assertSame(['removed' => 7], $third->summary);
        $this->assertSame($occurrences, CourseOccurrence::count());
        $past = $this->occurrence($added, '2026-11-17');
        $future = $this->occurrence($added, '2026-11-24');
        $this->assertSame(['active', false], [$past->status, $past->is_exception]);
        $this->assertSame(['calendar_excluded', true], [$future->status, $future->is_exception]);
        $this->assertSame('active', PublishedOccurrence::findOrFail($past->id)->status);
        $this->assertSame('calendar_excluded', PublishedOccurrence::findOrFail($future->id)->status);
        $this->assertCount(1, $this->learnerRows('2026-11-17'));
        $this->assertSame([], $this->learnerRows('2026-11-24'));
        $this->assertFalse($this->events($third)->pluck('subject_uuid')->contains($past->id));

        $this->assertSame(0, $this->preview()['total']);
        $this->as('admin-a')->post('/planning/series/'.$added->id.'/fenetre', ['window_from' => '2026-11-18', 'window_until' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertSame($occurrences, CourseOccurrence::count());
        $this->assertSame(0, $this->preview()['total']);
        $this->publish()->assertSessionHasErrors('publication');
        $this->assertSame([], $this->learnerRows('2026-11-24'));
    }

    public function test_rollback_is_not_blocked_but_skips_invalid_references_and_reports_overlaps(): void
    {
        $series = $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $moved = $this->occurrence($series, '2026-11-30');
        $cancelled = $this->occurrence($series, '2026-12-07');
        $this->change($moved, ['action' => 'reschedule', 'date' => '2026-12-01', 'start_time' => '10:00', 'end_time' => '11:00']);
        $this->change($cancelled, ['action' => 'cancel']);
        $this->publish()->assertSessionHasNoErrors();
        $draftOnly = $this->series(['title' => 'Cours fictif resté en brouillon', 'kind' => 'once', 'valid_from' => '2026-11-30', 'valid_until' => '2026-11-30', 'start_time' => '09:30', 'end_time' => '10:30']);
        DB::table('teaching_assignments')->where('membership_id', $this->member('enseignant-a')->id)->update(['ends_at' => '2026-12-03 00:00:00']);

        $this->rollback(1)->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $status) => str_contains($status, '1 cours non rétablis') && str_contains($status, '1 cours rétablis chevauchent'));
        $third = SchedulePublication::where('version', 3)->sole();
        $this->assertSame(['overlaps' => 1, 'skipped' => 1, 'updated' => 1], collect($third->summary)->sortKeys()->all());
        $this->assertSame('2026-11-30 09:00:00', $moved->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('cancelled', PublishedOccurrence::findOrFail($cancelled->id)->status);
        $this->assertSame('cancelled', $this->learnerRows('2026-12-07')[0]['status']);
        $this->assertSame([$moved->id], $this->events($third)->pluck('subject_uuid')->all());
        $this->assertSame('active', $this->occurrence($draftOnly, '2026-11-30')->status);
        $this->assertNull(PublishedOccurrence::find($this->occurrence($draftOnly, '2026-11-30')->id));
        $this->as('admin-a')->get('/planning/publication')->assertInertia(fn (Assert $page) => $page
            ->where('changeLabels.skipped', 'Non rétabli (référence devenue invalide)')->where('versions.0.summary.skipped', 1));
    }

    public function test_rollback_with_only_invalid_references_changes_nothing_and_says_why(): void
    {
        $series = $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $cancelled = $this->occurrence($series, '2026-12-07');
        $this->change($cancelled, ['action' => 'cancel']);
        $this->publish()->assertSessionHasNoErrors();
        DB::table('groups')->where('code', 'A-G1')->update(['archived_at' => now()]);

        $this->rollback(1)->assertSessionHasErrors(['publication' => 'Rien à rétablir : les 1 cours concernés référencent un enseignant, un groupe ou une matière qui n’est plus valide.']);
        $this->assertSame(2, SchedulePublication::count());
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('cancelled', PublishedOccurrence::findOrFail($cancelled->id)->status);
    }

    public function test_a_platform_context_shows_the_personal_schedule_and_references_instead_of_refusing(): void
    {
        $this->series();
        $this->publish()->assertSessionHasNoErrors();
        foreach (['plateforme', 'editeur'] as $name) {
            $actor = fn () => $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id]);
            $actor()->get('/planning?view=day&date=2026-11-23')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('source', 'personal')->where('institutional', false)->where('editable', true)->has('rows', 0)->where('campus.context.kind', 'platform'));
            $actor()->get('/planning?view=day&date=2026-11-23&source=published')->assertOk()->assertInertia(fn (Assert $page) => $page->where('source', 'personal')->has('rows', 0));
            $actor()->get('/etudes/referentiels')->assertOk()->assertInertia(fn (Assert $page) => $page->where('institutional', false));
            $actor()->get('/planning/publication')->assertForbidden();
        }
    }

    public function test_r20_a_teacher_report_stays_pending_until_the_administration_publishes_its_decision(): void
    {
        $series = $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $course = $this->occurrence($series, '2026-11-23');
        $url = '/planning/occurrences/'.$course->id.'/indisponibilite';

        $this->as('enseignant-a')->get('/planning?view=day&date=2026-11-23')->assertInertia(fn (Assert $page) => $page
            ->where('source', 'published')->where('canReport', true)->where('editable', false)->has('rows', 1));
        $this->as('apprenant-double')->post($url, ['reason' => 'Tentative fictive'])->assertForbidden();
        $this->as('polyvalent', 'B')->post($url, ['reason' => 'Tentative fictive'])->assertNotFound();
        $this->as('enseignant-a')->post($url, ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->as('enseignant-a')->post($url, ['reason' => 'Formation fictive ce jour-là'])->assertSessionHasNoErrors();
        $this->as('enseignant-a')->post($url, ['reason' => 'Second envoi fictif'])->assertSessionHasErrors('reason');

        $report = TeacherUnavailability::sole();
        $this->assertSame(['pending', $this->member('enseignant-a')->id], [$report->status, $report->teacher_membership_id]);
        $this->assertSame('active', $course->fresh()->status);
        $this->assertSame('active', $this->learnerRows('2026-11-23')[0]['status']);
        $this->assertSame(1, SchedulePublication::count());

        $decide = '/planning/indisponibilites/'.$report->id.'/decision';
        $this->as('enseignant-a')->post($decide, ['decision' => 'accept_cancel'])->assertForbidden();
        $this->as('enseignant-a')->post('/planning/publication', ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'preview_hash' => str_repeat('0', 64), 'reason' => 'Tentative'])->assertForbidden();
        $this->as('enseignant-a')->patch('/planning/occurrences/'.$course->id, ['lock_version' => 1, 'action' => 'cancel', 'reason' => 'Tentative'])->assertForbidden();
        $this->as('admin-b', 'B')->post($decide, ['decision' => 'accept_cancel'])->assertNotFound();
        $this->assertSame('pending', $report->fresh()->status);

        $this->as('admin-a')->get('/planning/publication')->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)->where('reports.0.teacher', 'Enseignant fictif A')->where('reports.0.reason', 'Formation fictive ce jour-là'));
        $this->as('admin-a')->post($decide, ['decision' => 'accept_cancel', 'note' => 'Cours annulé'])->assertSessionHasNoErrors();
        $this->as('admin-a')->post($decide, ['decision' => 'decline'])->assertSessionHasErrors('decision');
        $this->assertSame(['accepted', 'accept_cancel', $this->user('admin-a')->id], [$report->fresh()->status, $report->fresh()->decision, $report->fresh()->decided_by]);
        $this->assertSame('cancelled', $course->fresh()->status);
        $this->assertSame('active', $this->learnerRows('2026-11-23')[0]['status']);
        $this->assertSame('active', PublishedOccurrence::findOrFail($course->id)->status);

        $this->assertSame(['cancelled' => 1], $this->preview()['counts']);
        $this->publish(['reason' => 'Indisponibilité acceptée'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $this->learnerRows('2026-11-23')[0]['status']);
        $event = $this->events(SchedulePublication::where('version', 2)->sole())->sole();
        $this->assertSame(['cancelled', $course->id, 'active', 'cancelled'], [$event->payload['change'], $event->subject_uuid, $event->payload['before']['status'], $event->payload['after']['status']]);

        $learner = $this->member('apprenant-double');
        $recipients = fn () => app(ScheduleAccess::class)->recipients($event->institution_id, $event->academic_year_id, $event->payload['after']['group_ids'], [$event->payload['after']['teacher_membership_id']])->all();
        $expected = collect([$this->user('apprenant-double')->id, $this->user('polyvalent')->id, $this->user('enseignant-a')->id])->sort()->values()->all();
        $this->assertSame($expected, $recipients());
        $learner->revoke();
        $this->assertNotContains($this->user('apprenant-double')->id, $recipients());
        $this->assertNotContains($this->user('admin-b')->id, $recipients());
        $this->as('enseignant-a')->get('/planning?view=day&date=2026-11-23')->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.status', 'accepted')->where('reports.0.note', 'Cours annulé'));
    }

    public function test_publication_rights_are_checked_on_the_server_for_every_role_and_institution(): void
    {
        $this->series();
        $this->publish()->assertSessionHasNoErrors();
        $payload = ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'preview_hash' => str_repeat('0', 64), 'reason' => 'Tentative fictive'];

        foreach ([['apprenant-double', 'A'], ['enseignant-a', 'A'], ['plateforme', null], ['independant', null]] as [$name, $letter]) {
            $actor = $name === 'plateforme'
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            $actor->get('/planning/publication')->assertForbidden();
            $actor->post('/planning/publication', $payload)->assertForbidden();
            $actor->post('/planning/publication/1/retour', $payload)->assertForbidden();
        }
        $this->as('admin-b', 'B')->get('/planning/publication?institution_id='.$this->member('admin-a')->institution_id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('current', null)->where('preview.total', 0)->has('versions', 0)->where('institution', 'Établissement fictif B'));
        $this->as('admin-b', 'B')->get('/planning/publication?version=1')->assertNotFound();
        $this->as('admin-b', 'B')->post('/planning/publication/1/retour', $payload)->assertNotFound();
        $this->as('admin-b', 'B')->post('/planning/publication', [...$payload, 'expected_version' => 0])->assertStatus(409);

        config(['campus.identity.privileged_without_mfa' => false]);
        $this->as('admin-a')->get('/planning/publication')->assertForbidden();
        $this->as('admin-a')->post('/planning/publication', $payload)->assertForbidden();
        $this->as('enseignant-a')->post('/planning/occurrences/'.CourseOccurrence::firstOrFail()->id.'/indisponibilite', ['reason' => 'Tentative fictive'])->assertForbidden();
        $this->assertCount(1, $this->learnerRows('2026-11-23'));
        $this->assertSame(1, SchedulePublication::count());
        $this->assertSame(0, TeacherUnavailability::count());
    }

    public function test_concurrent_publications_cannot_both_win_and_the_database_refuses_duplicate_versions_and_events(): void
    {
        $series = $this->series();
        $stale = $this->preview();
        $this->change($this->occurrence($series, '2026-11-30'), ['action' => 'cancel']);
        $attempt = ['request_key' => (string) Str::uuid(), 'expected_version' => $stale['version'], 'preview_hash' => $stale['hash'], 'reason' => 'Publication fictive périmée'];
        $this->as('admin-a')->post('/planning/publication', $attempt)->assertStatus(409);
        $this->assertSame(0, SchedulePublication::count());

        $fresh = $this->preview();
        $this->assertSame(['added' => 7], $fresh['counts']);
        $first = [...$attempt, 'preview_hash' => $fresh['hash'], 'request_key' => (string) Str::uuid()];
        $this->as('admin-a')->post('/planning/publication', $first)->assertRedirect('/planning/publication');
        $this->as('admin-a')->post('/planning/publication', [...$first, 'request_key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(1, SchedulePublication::count());
        $this->assertSame(7, PublishedOccurrence::count());

        $publication = SchedulePublication::sole();
        $row = ['institution_id' => $publication->institution_id, 'academic_year_id' => $publication->academic_year_id, 'kind' => 'publish', 'author_id' => $publication->author_id,
            'reason' => 'Doublon fictif', 'content_hash' => str_repeat('a', 64), 'summary' => '{}', 'published_at' => now()];
        foreach ([[...$row, 'version' => 1, 'request_key' => (string) Str::uuid()], [...$row, 'version' => 2, 'request_key' => $publication->request_key]] as $duplicate) {
            try {
                DB::table('schedule_publications')->insert($duplicate);
                $this->fail('La base a accepté une version ou une demande en double.');
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }
        }
        $event = DomainEvent::where('type', 'schedule.occurrence.changed')->firstOrFail();
        try {
            DB::table('domain_events')->insert(['type' => $event->type, 'dedup_key' => $event->dedup_key, 'payload' => '{}', 'occurred_at' => now()]);
            $this->fail('La base a accepté un événement en double.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
    }
}
