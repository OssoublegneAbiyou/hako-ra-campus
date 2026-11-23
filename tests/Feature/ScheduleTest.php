<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\CourseOccurrenceRevision;
use App\Models\CourseSeries;
use App\Models\Group;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\ScheduleManager;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleTest extends TestCase
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

    private function member(string $name): Membership
    {
        return $this->user($name)->memberships()->firstOrFail();
    }

    private function login(string $name = 'independant', bool $context = false): static
    {
        return $this->freshDevice()->actingAs($this->user($name))->withSession($context ? [ActiveContext::SESSION_KEY => $this->member($name)->id] : []);
    }

    private function rules(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'title' => 'Cours fictif', 'kind' => 'weekly', 'valid_from' => '2026-11-16', 'valid_until' => '2027-02-28', 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []], $overrides);
    }

    private function institutional(array $overrides = []): array
    {
        $m = $this->member('enseignant-a');

        return $this->rules(array_replace(['subject_id' => Subject::where('institution_id', $m->institution_id)->firstOrFail()->id, 'teacher_membership_id' => $m->id, 'group_ids' => [Group::where('code', 'A-G1')->firstOrFail()->id]], $overrides));
    }

    private function create(array $data): CourseSeries
    {
        $this->post('/planning/series', $data)->assertSessionHasNoErrors()->assertRedirect();

        return CourseSeries::where('request_key', $data['request_key'])->firstOrFail();
    }

    public function test_r11_weekly_generation_is_bounded_renewable_and_idempotent(): void
    {
        $this->login();
        $data = $this->rules();
        $s = $this->create($data);
        $this->assertSame(8, $s->occurrences()->count());
        $ids = $s->occurrences()->pluck('id')->all();
        $this->post('/planning/series', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, CourseSeries::count());
        $window = ['window_from' => '2026-11-16', 'window_until' => '2027-01-10'];
        $this->post('/planning/series/'.$s->id.'/fenetre', $window)->assertSessionHasNoErrors();
        $this->assertSame($ids, $s->occurrences()->pluck('id')->all());
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2027-01-11', 'window_until' => '2027-02-28'])->assertSessionHasNoErrors();
        $this->assertSame(15, $s->occurrences()->count());
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2026-11-16', 'window_until' => '2027-02-28'])->assertSessionHasErrors('window_until');
        $this->assertSame(15, CourseOccurrenceRevision::count());
    }

    public function test_r11_weekends_holidays_non_working_days_and_active_weeks(): void
    {
        $this->login();
        $s = $this->create($this->rules(['weekdays' => [6, 7], 'valid_from' => '2026-11-21', 'valid_until' => '2026-12-13', 'non_working_days' => [7], 'excluded_dates' => ['2026-11-28'], 'active_weeks' => ['2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'], 'holidays' => [['from' => '2026-12-05', 'until' => '2026-12-06']]]));
        $this->assertSame(['2026-11-21', '2026-12-12'], $s->occurrences()->orderBy('original_date')->get()->map(fn ($o) => $o->original_date->toDateString())->all());
        $single = $this->create($this->rules(['title' => 'Dimanche libre', 'kind' => 'once', 'valid_from' => '2026-11-22', 'valid_until' => '2026-11-22', 'weekdays' => [1]]));
        $this->assertSame(1, $single->occurrences()->count());
        $this->assertSame(7, $single->occurrences()->first()->starts_at->dayOfWeekIso);
    }

    public function test_r11_utc_instants_follow_spring_and_autumn_timezone_changes(): void
    {
        $this->login();
        $spring = $this->create($this->rules(['timezone' => 'Europe/Paris', 'valid_from' => '2027-03-21', 'valid_until' => '2027-04-04', 'weekdays' => [7]]));
        $rows = $spring->occurrences()->orderBy('original_date')->get();
        $this->assertSame(['08:00', '07:00', '07:00'], $rows->map(fn ($o) => $o->starts_at->format('H:i'))->all());
        $this->assertSame(['09:00', '09:00', '09:00'], $rows->map(fn ($o) => $o->starts_at->setTimezone('Europe/Paris')->format('H:i'))->all());
        $autumn = $this->create($this->rules(['title' => 'Automne fictif', 'timezone' => 'Europe/Paris', 'valid_from' => '2027-10-24', 'valid_until' => '2027-11-07', 'weekdays' => [7]]));
        $this->assertSame(['07:00', '08:00', '08:00'], $autumn->occurrences()->orderBy('original_date')->get()->map(fn ($o) => $o->starts_at->format('H:i'))->all());
    }

    public function test_dst_skipped_and_ambiguous_hours_are_rejected_atomically(): void
    {
        $this->login();
        foreach (['2027-03-28', '2027-10-31'] as $date) {
            $this->post('/planning/series', $this->rules(['kind' => 'once', 'valid_from' => $date, 'valid_until' => $date, 'timezone' => 'Europe/Paris', 'start_time' => '02:30', 'end_time' => '03:30']))->assertSessionHasErrors('start_time');
        }
        $this->assertSame(0, CourseSeries::count());
        $this->assertSame(0, CourseOccurrence::count());
    }

    public function test_r17_reschedule_keeps_id_original_key_series_and_history(): void
    {
        $this->login();
        $s = $this->create($this->rules());
        $o = $s->occurrences()->orderBy('original_date')->first();
        $other = $s->occurrences()->orderBy('original_date')->skip(1)->first();
        $this->patch('/planning/occurrences/'.$o->id, ['action' => 'reschedule', 'date' => '2026-11-17', 'start_time' => '11:00', 'end_time' => '12:00', 'reason' => 'Report fictif', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16', $o->fresh()->original_date->toDateString());
        $this->assertSame('2026-11-17 11:00', $o->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame($s->id, $o->fresh()->course_series_id);
        $this->assertTrue($o->fresh()->is_exception);
        $this->assertSame(2, $o->revisions()->count());
        $this->assertSame(1, $other->fresh()->lock_version);
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2026-11-16', 'window_until' => '2027-01-10'])->assertSessionHasNoErrors();
        $this->assertSame(8, $s->occurrences()->count());
        $this->assertSame('11:00', $o->fresh()->starts_at->format('H:i'));
        $this->get('/planning/occurrences/'.$o->id.'/historique')->assertInertia(fn (Assert $p) => $p->has('revisions', 2));
    }

    public function test_r18_cancellation_is_not_regenerated_and_has_no_destructive_delete(): void
    {
        $this->login();
        $s = $this->create($this->rules());
        $o = $s->occurrences()->firstOrFail();
        $this->patch('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Annulation fictive', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2026-11-16', 'window_until' => '2027-01-10'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame(8, $s->occurrences()->count());
        $this->assertSame('active', $o->revisions()->orderBy('version')->first()->after['status']);
    }

    public function test_future_revision_keeps_manual_exceptions_and_existing_ids(): void
    {
        $this->login();
        $data = $this->rules();
        $s = $this->create($data);
        $manual = $s->occurrences()->where('original_date', '2026-11-23')->firstOrFail();
        $this->patch('/planning/occurrences/'.$manual->id, ['action' => 'cancel', 'reason' => 'Exception fictive', 'lock_version' => 1])->assertSessionHasNoErrors();
        $normal = $s->occurrences()->where('original_date', '2026-11-30')->firstOrFail();
        $first = $s->occurrences()->where('original_date', '2026-11-16')->firstOrFail();
        $this->patch('/planning/series/'.$s->id.'/futur', [...$data, 'start_time' => '12:00', 'end_time' => '13:00', 'effective_from' => '2026-11-23', 'reason' => 'Nouvel horaire futur', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $manual->fresh()->status);
        $this->assertSame('09:00', $first->fresh()->starts_at->format('H:i'));
        $this->assertSame('12:00', $normal->fresh()->starts_at->format('H:i'));
        $this->assertSame($normal->id, $s->occurrences()->where('original_date', '2026-11-30')->first()->id);
        $this->assertSame(2, $s->fresh()->lock_version);
        $this->patch('/planning/series/'.$s->id.'/futur', [...$data, 'effective_from' => '2026-11-23', 'reason' => 'Rejeu périmé', 'lock_version' => 1])->assertStatus(409);
    }

    public function test_future_day_change_reconciles_the_entire_materialized_horizon(): void
    {
        $this->login();
        $data = $this->rules();
        $s = $this->create($data);
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2027-01-11', 'window_until' => '2027-02-28'])->assertSessionHasNoErrors();
        $old = $s->occurrences()->where('original_date', '2027-02-22')->firstOrFail();
        $this->patch('/planning/series/'.$s->id.'/futur', [...$data, 'weekdays' => [2], 'effective_from' => '2026-11-23', 'reason' => 'Passage au mardi', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('calendar_excluded', $old->fresh()->status);
        $this->assertSame(1, $s->occurrences()->where('original_date', '2027-02-23')->where('status', 'active')->count());
        $this->assertSame($old->id, $s->occurrences()->where('original_date', '2027-02-22')->first()->id);
    }

    public function test_r15_group_teacher_and_room_conflicts_require_a_motivated_confirmation(): void
    {
        $this->login('admin-a', true);
        $data = $this->institutional(['room' => 'A 101']);
        $s = $this->create($data);
        $conflict = $this->institutional(['title' => 'Autre cours', 'start_time' => '09:30', 'end_time' => '10:30', 'room' => 'A 101']);
        $this->post('/planning/series', $conflict)->assertSessionHasErrors('conflict');
        $this->assertSame(1, CourseSeries::count());
        $this->post('/planning/series', [...$conflict, 'confirm_conflicts' => true, 'conflict_reason' => 'Court'])->assertSessionHasErrors('conflict');
        $second = $this->create([...$conflict, 'confirm_conflicts' => true, 'conflict_reason' => 'Atelier commun fictif autorisé']);
        $this->assertSame(8, $second->occurrences()->count());
        $this->assertTrue(AuditLog::where('action', 'schedule.conflict.confirmed')->exists());
        $this->post('/planning/series', [...$data, 'request_key' => (string) Str::uuid(), 'confirm_conflicts' => true, 'conflict_reason' => 'Même un motif ne doit pas autoriser un doublon'])->assertSessionHasErrors('conflict');
        $this->assertSame(2, CourseSeries::count());
    }

    public function test_institutional_cross_scope_and_unassigned_teacher_are_rejected(): void
    {
        $this->login('admin-a', true);
        $this->post('/planning/series', $this->institutional(['group_ids' => [Group::where('code', 'B-G1')->firstOrFail()->id]]))->assertNotFound();
        $this->post('/planning/series', $this->institutional(['group_ids' => [Group::where('code', 'A-G2')->firstOrFail()->id]]))->assertSessionHasErrors('teacher_membership_id');
        $this->post('/planning/series', $this->institutional(['subject_id' => Subject::where('institution_id', $this->member('admin-b')->institution_id)->firstOrFail()->id]))->assertNotFound();
        $this->assertSame(0, CourseOccurrence::count());
    }

    public function test_r19_learner_cannot_mutate_institutional_schedule_but_can_create_personal_weekend(): void
    {
        $this->login('admin-a', true);
        $s = $this->create($this->institutional());
        $o = $s->occurrences()->first();
        $this->login('apprenant-double', true)->patch('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Tentative apprenant', 'lock_version' => 1])->assertForbidden();
        $this->post('/planning/series', $this->institutional())->assertForbidden();
        $this->get('/planning?date=2026-11-16')->assertInertia(fn (Assert $p) => $p->where('draftsHidden', true)->has('rows', 0));
        $this->login('apprenant-double')->create($this->rules(['kind' => 'once', 'valid_from' => '2026-11-22', 'valid_until' => '2026-11-22']));
        $this->assertSame(1, CourseSeries::where('scope', 'personal')->count());
    }

    public function test_teacher_preview_only_assigned_drafts_and_no_mutation_rights(): void
    {
        $this->login('admin-a', true);
        $s = $this->create($this->institutional());
        $o = $s->occurrences()->first();
        // Depuis le lot 08, l'enseignant voit le planning publié par défaut ; le brouillon se consulte avec source=draft.
        $this->login('enseignant-a', true)->get('/planning?date=2026-11-16')->assertInertia(fn (Assert $p) => $p->where('source', 'published')->where('editable', false)->has('rows', 0));
        $this->get('/planning?date=2026-11-16&source=draft')->assertInertia(fn (Assert $p) => $p->where('source', 'draft')->where('editable', false)->has('rows', 1));
        $this->patch('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Refus enseignant', 'lock_version' => 1])->assertForbidden();
        TeachingAssignment::where('membership_id', $this->member('enseignant-a')->id)->get()->each->close();
        $this->get('/planning?date=2026-11-16&source=draft')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    }

    public function test_personal_privacy_and_institution_b_is_not_found_from_a_or_platform(): void
    {
        $this->login();
        $personal = $this->create($this->rules());
        $o = $personal->occurrences()->first();
        foreach (['admin-a', 'plateforme', 'editeur'] as $name) {
            $this->login($name)->get('/planning/occurrences/'.$o->id.'/historique')->assertNotFound();
            $this->patch('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Tentative privée', 'lock_version' => 1])->assertNotFound();
        }
        $this->login('admin-a', true);
        $institution = $this->create($this->institutional());
        $this->login('admin-b', true)->post('/planning/series/'.$institution->id.'/fenetre', ['window_from' => '2026-11-16', 'window_until' => '2026-11-22'])->assertNotFound();
    }

    public function test_context_withdrawal_and_archived_year_block_immediately_without_private_loss(): void
    {
        $this->login('admin-a');
        $personal = $this->create($this->rules());
        $this->login('admin-a', true);
        $official = $this->create($this->institutional());
        $o = $official->occurrences()->first();
        $this->member('admin-a')->revoke();
        $this->patch('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Après retrait', 'lock_version' => 1])->assertNotFound();
        $this->assertSame(8, $personal->occurrences()->count());
        $this->get('/planning?date=2026-11-16')->assertInertia(fn (Assert $p) => $p->where('institutional', false)->has('rows', 1));
    }

    public function test_versions_idempotency_and_mfa_default_are_enforced(): void
    {
        $this->login();
        $data = $this->rules();
        $s = $this->create($data);
        $o = $s->occurrences()->first();
        $this->post('/planning/series', [...$data, 'title' => 'Autre contenu même clé'])->assertStatus(409);
        $this->patchJson('/planning/occurrences/'.$o->id, ['action' => 'cancel', 'reason' => 'Version périmée', 'lock_version' => 99])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->login('admin-a', true);
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->post('/planning/series', $this->institutional())->assertForbidden();
        $this->get('/planning')->assertForbidden();
    }

    public function test_invalid_dates_url_protocols_and_large_generation_are_rejected(): void
    {
        $this->login();
        $this->post('/planning/series', $this->rules(['valid_until' => '2030-01-01']))->assertSessionHasErrors('valid_until');
        $this->post('/planning/series', $this->rules(['meeting_url' => 'javascript:alert(1)']))->assertSessionHasErrors('meeting_url');
        $this->post('/planning/series', $this->rules(['start_time' => '23:00', 'end_time' => '01:00']))->assertSessionHasErrors('end_time');
        $this->post('/planning/series', $this->rules(['active_weeks' => ['2026-11-17']]))->assertSessionHasErrors('active_weeks');
        $this->assertSame(0, CourseSeries::count());
    }

    public function test_mysql_ownership_constraints_and_history_restrict_deletion(): void
    {
        $this->login();
        $s = $this->create($this->rules());
        $o = $s->occurrences()->first();
        try {
            DB::table('course_series')->where('id', $s->id)->delete();
            $this->fail('FK attendue');
        } catch (QueryException $e) {
            $this->assertSame(1451, (int) $e->errorInfo[1]);
        }
        try {
            DB::table('course_occurrences')->where('id', $o->id)->update(['owner_id' => $this->user('admin-a')->id]);
            $this->fail('FK attendue');
        } catch (QueryException $e) {
            $this->assertSame(1452, (int) $e->errorInfo[1]);
        }
        $this->expectException(\LogicException::class);
        $o->revisions()->first()->update(['reason' => 'Écrasement interdit']);
    }

    public function test_day_week_and_list_queries_are_bounded_and_searchable(): void
    {
        $this->login();
        $s = $this->create($this->rules());
        foreach (['day', 'week', 'list'] as $view) {
            $this->get('/planning?date=2026-11-16&view='.$view)->assertInertia(fn (Assert $p) => $p->component('Schedule')->where('view', $view)->has('rows', 1));
        }
        $this->get('/planning?date=2026-11-16&q=Introuvable')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
        $this->get('/planning?date=invalide')->assertSessionHasErrors('date');
    }

    public function test_each_conflict_resource_is_detected_independently(): void
    {
        $this->login('admin-a', true);
        $data = $this->institutional(['kind' => 'once', 'valid_until' => '2026-11-16', 'room' => 'SALLE-1']);
        $this->create($data);
        $acting = $this->member('admin-a');
        $group2 = Group::where('code', 'A-G2')->firstOrFail();
        $teacher2 = Membership::factory()->create(['institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'role' => 'teacher', 'starts_at' => '2026-09-01']);
        foreach ([$this->member('enseignant-a'), $teacher2] as $teacher) {
            foreach ([Group::where('code', 'A-G1')->firstOrFail(), $group2] as $group) {
                TeachingAssignment::firstOrCreate(['membership_id' => $teacher->id, 'group_id' => $group->id, 'subject_id' => $data['subject_id'], 'status' => 'active'], ['user_id' => $teacher->user_id, 'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'starts_at' => '2026-09-01']);
            }
        }
        $common = ['request_key' => (string) Str::uuid(), 'title' => 'Cours différent', 'start_time' => '09:30', 'end_time' => '10:30'];
        $this->post('/planning/series', [...$data, ...$common, 'group_ids' => [$group2->id], 'room' => 'AUTRE'])->assertSessionHasErrors('conflict');
        $this->post('/planning/series', [...$data, ...$common, 'teacher_membership_id' => $teacher2->id, 'room' => 'AUTRE'])->assertSessionHasErrors('conflict');
        $this->post('/planning/series', [...$data, ...$common, 'group_ids' => [$group2->id], 'teacher_membership_id' => $teacher2->id])->assertSessionHasErrors('conflict');
        $this->assertSame(1, CourseSeries::count());
    }

    public function test_future_conflict_rolls_back_revision_occurrences_and_horizon(): void
    {
        $this->login();
        $data = $this->rules();
        $s = $this->create($data);
        $this->create($this->rules(['title' => 'Autre séance', 'weekdays' => [2]]));
        $before = $s->occurrences()->pluck('id')->all();
        $this->patch('/planning/series/'.$s->id.'/futur', [...$data, 'weekdays' => [2], 'effective_from' => '2026-11-23', 'lock_version' => 1, 'reason' => 'Révision qui entre en conflit'])->assertSessionHasErrors('conflict');
        $this->assertSame(1, $s->fresh()->lock_version);
        $this->assertSame(1, $s->revisions()->count());
        $this->assertSame($before, $s->occurrences()->pluck('id')->all());
        $this->assertSame(0, $s->occurrences()->where('status', 'calendar_excluded')->count());
    }

    public function test_archived_year_and_revoked_teacher_prevent_generation_and_replay(): void
    {
        $this->login('admin-a', true);
        $s = $this->create($this->institutional());
        $this->member('enseignant-a')->revoke();
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2027-01-11', 'window_until' => '2027-01-17'])->assertNotFound();
        $this->assertSame(8, $s->occurrences()->count());
        $this->member('admin-a')->academicYear->forceFill(['archived_at' => now()])->save();
        $this->post('/planning/series/'.$s->id.'/fenetre', ['window_from' => '2027-01-11', 'window_until' => '2027-01-17'])->assertNotFound();
    }

    public function test_owner_calendar_conflict_and_personal_subject_isolation(): void
    {
        $this->login();
        $data = $this->rules();
        $this->create($data);
        $this->post('/planning/series', $this->rules(['title' => 'Second événement', 'start_time' => '09:30', 'end_time' => '10:30']))->assertSessionHasErrors('conflict');
        $foreign = PersonalSubject::factory()->create();
        $this->post('/planning/series', $this->rules(['personal_subject_id' => $foreign->id]))->assertNotFound();
        $this->assertSame(1, CourseSeries::count());
    }

    public function test_pagination_bounds_the_week_and_history_without_hiding_other_pages(): void
    {
        $this->login();
        for ($i = 0; $i < 51; $i++) {
            $series = CourseSeries::factory()->create(['owner_id' => $this->user('independant')->id, 'created_by' => $this->user('independant')->id]);
            $start = now()->setTime(9, 0)->addMinutes($i * 5);
            CourseOccurrence::factory()->create(['course_series_id' => $series->id, 'owner_id' => $series->owner_id, 'original_date' => '2026-11-16', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(5)]);
        }
        $this->get('/planning?date=2026-11-16')->assertInertia(fn (Assert $p) => $p->has('rows', 50)->where('pagination.total', 2));
        $this->get('/planning?date=2026-11-16&page=2')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('pagination.page', 2));
        $o = CourseOccurrence::firstOrFail();
        for ($v = 1; $v <= 21; $v++) {
            CourseOccurrenceRevision::create(['course_occurrence_id' => $o->id, 'version' => $v, 'after' => app(ScheduleManager::class)->snapshot($o), 'actor_id' => $this->user('independant')->id, 'reason' => 'Révision fictive pour pagination']);
        }
        $this->get('/planning/occurrences/'.$o->id.'/historique')->assertInertia(fn (Assert $p) => $p->has('revisions', 20)->where('pagination.total', 2));
    }

    public function test_latest_future_edit_supersedes_an_older_edit_scheduled_later(): void
    {
        $this->login();
        $data = $this->rules();
        $series = $this->create($data);
        $this->patch('/planning/series/'.$series->id.'/futur', [...$data, 'start_time' => '12:00', 'end_time' => '13:00', 'effective_from' => '2026-12-14', 'reason' => 'Première révision différée', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->patch('/planning/series/'.$series->id.'/futur', [...$data, 'start_time' => '14:00', 'end_time' => '15:00', 'effective_from' => '2026-11-23', 'reason' => 'Nouvelle révision de tout le futur', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('14:00', $series->occurrences()->where('original_date', '2026-12-21')->firstOrFail()->starts_at->format('H:i'));
        $this->assertSame(3, $series->fresh()->lock_version);
        $this->assertSame(3, $series->revisions()->count());
    }

    public function test_viewer_day_and_week_include_a_course_crossing_midnight_after_timezone_conversion(): void
    {
        $this->login();
        $series = $this->create($this->rules(['kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16', 'timezone' => 'Europe/Paris', 'start_time' => '00:00', 'end_time' => '02:00']));
        $o = $series->occurrences()->firstOrFail();
        $this->assertSame('2026-11-15 23:00', $o->starts_at->format('Y-m-d H:i'));
        foreach (['/planning?date=2026-11-15&view=day', '/planning?date=2026-11-16&view=day', '/planning?date=2026-11-16&view=week'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.id', $o->id));
        }
    }
}
