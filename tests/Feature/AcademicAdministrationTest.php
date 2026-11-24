<?php

namespace Tests\Feature;

use App\Models\AcademicPeriodType;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalPeriod;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Support\JsonErrorResponse;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AcademicAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function login(string $name, bool $context = true): static
    {
        return $this->freshDevice()->actingAs($this->user($name))->withSession($context ? [ActiveContext::SESSION_KEY => $this->member($name)->id, 'auth.password_confirmed_at' => time()] : []);
    }

    private function group(string $code): Group
    {
        return Group::where('code', $code)->firstOrFail();
    }

    private function payload(string $code = 'NEW'): array
    {
        return ['code' => $code, 'name' => 'Référence fictive'];
    }

    public function test_admin_creates_updates_searches_and_cannot_touch_b(): void
    {
        $this->login('admin-a')->post('/etudes/referentiels/groups', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $group = Group::where('code', 'NEW')->firstOrFail();
        $this->patch('/etudes/referentiels/groups/'.$group->id, [...$this->payload(), 'name' => 'Modifiée', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, $group->fresh()->lock_version);
        $this->withHeader('X-Inertia', 'true')->from('/etudes/referentiels?kind=groups')->patch('/etudes/referentiels/groups/'.$group->id, [...$this->payload(), 'lock_version' => 1])->assertRedirect('/etudes/referentiels?kind=groups')->assertSessionHasErrors('reference');
        $this->withHeader('X-Inertia', '');
        $this->patch('/etudes/referentiels/groups/'.$group->id, [...$this->payload(), 'lock_version' => 1])->assertStatus(409);
        $this->patch('/etudes/referentiels/groups/'.$this->group('B-G1')->id, [...$this->payload(), 'lock_version' => 1])->assertNotFound();
        $this->get('/etudes/referentiels?kind=groups&q=Modifiée')->assertInertia(fn (Assert $p) => $p->component('AcademicReferences')->has('rows', 1)->where('rows.0.name', 'Modifiée'));
        $this->get('/etudes/referentiels?kind=groups&q=absent')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    }

    public function test_teacher_sees_only_assigned_groups_and_cannot_modify(): void
    {
        $this->login('enseignant-a')->get('/etudes/referentiels?kind=groups')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.code', 'A-G1')->where('editable', false));
        $this->post('/etudes/referentiels/groups', $this->payload())->assertForbidden();
        $this->patch('/etudes/referentiels/groups/'.$this->group('A-G2')->id, ['action' => 'archive', 'lock_version' => 1])->assertForbidden();
        $this->get('/etudes/referentiels?kind=enrollments')->assertForbidden();
    }

    public function test_personal_references_are_private_without_institution_and_survive_departure(): void
    {
        $this->login('apprenant-double', false)->post('/etudes/referentiels/subjects', $this->payload())->assertSessionHasNoErrors();
        $private = PersonalSubject::where('owner_id', $this->user('apprenant-double')->id)->firstOrFail();
        $this->assertNull($private->institution_id);
        $this->login('plateforme', false)->patch('/etudes/referentiels/subjects/'.$private->id, [...$this->payload(), 'lock_version' => 1])->assertNotFound();
        $this->login('apprenant-double', false)->withSession(['auth.password_confirmed_at' => time()])->post('/profil/appartenances/'.$this->member('apprenant-double')->id.'/depart')->assertRedirect();
        $this->assertDatabaseHas('personal_subjects', ['id' => $private->id, 'owner_id' => $this->user('apprenant-double')->id]);
        $this->get('/etudes/referentiels?kind=subjects')->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    }

    public function test_a_subject_code_is_generated_from_its_name_and_stays_unique_in_its_perimeter(): void
    {
        $owner = $this->user('apprenant-double');
        $create = fn (string $name) => $this->login('apprenant-double', false)->post('/etudes/referentiels/subjects', ['name' => $name])->assertSessionHasNoErrors();
        $code = fn (string $name): string => PersonalSubject::where('owner_id', $owner->id)->where('name', $name)->latest('id')->firstOrFail()->code;
        $create('Mathématiques');
        $this->assertSame('MATH', $code('Mathématiques'));
        $create('Histoire-géographie');
        $this->assertSame('HIS-GEO', $code('Histoire-géographie'));
        $create('Sciences de la vie et de la Terre');
        $this->assertSame('SCI-VIE-TER', $code('Sciences de la vie et de la Terre'));
        // Même début de nom dans le même espace : un numéro distingue les codes, sans refus.
        $create('Mathématiques appliquées');
        $create('Maths');
        $this->assertSame('MATH-2', $code('Maths'));
        // Un autre compte reçoit le même code de son côté : les espaces ne se croisent pas.
        $this->login('independant', false)->post('/etudes/referentiels/subjects', ['name' => 'Mathématiques'])->assertSessionHasNoErrors();
        $this->assertSame('MATH', PersonalSubject::where('owner_id', $this->user('independant')->id)->firstOrFail()->code);
        // Matière d'établissement : même règle, dans le périmètre de l'établissement.
        $this->login('admin-a')->post('/etudes/referentiels/subjects', ['name' => 'Philosophie fictive'])->assertSessionHasNoErrors();
        $this->assertSame('PHI-FIC', Subject::where('name', 'Philosophie fictive')->firstOrFail()->code);
        // Le nom reste exigé ; un code saisi est respecté ; les autres référentiels reçoivent aussi leur code.
        $this->login('apprenant-double', false)->post('/etudes/referentiels/subjects', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/etudes/referentiels/subjects', ['code' => 'PERSO', 'name' => 'Matière au code choisi'])->assertSessionHasNoErrors();
        $this->assertSame('PERSO', $code('Matière au code choisi'));
        $this->login('admin-a')->post('/etudes/referentiels/types', ['name' => 'Trimestre'])->assertSessionHasNoErrors();
        $this->assertSame('TRIM', AcademicPeriodType::where('name', 'Trimestre')->firstOrFail()->code);
        $this->post('/etudes/referentiels/groups', ['name' => 'Terminale S 2'])->assertSessionHasNoErrors();
        $this->assertSame('TER-S-2', Group::where('name', 'Terminale S 2')->firstOrFail()->code);
        // Modifier une référence exige toujours son code : il n'est jamais régénéré en silence.
        $this->patch('/etudes/referentiels/groups/'.Group::where('name', 'Terminale S 2')->firstOrFail()->id, ['name' => 'Terminale S 3', 'lock_version' => 1])->assertSessionHasErrors('code');
    }

    public function test_period_overlap_parent_and_types_are_scoped(): void
    {
        $this->login('admin-a')->post('/etudes/referentiels/types', $this->payload('SEM'))->assertSessionHasNoErrors();
        $type = AcademicPeriodType::where('code', 'SEM')->firstOrFail();
        $parent = Period::where('institution_id', $this->member('admin-a')->institution_id)->firstOrFail();
        $d = [...$this->payload('CHILD'), 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'weight' => 1, 'parent_period_id' => $parent->id, 'period_type_id' => $type->id];
        $this->post('/etudes/referentiels/periods', $d)->assertSessionHasNoErrors();
        $this->post('/etudes/referentiels/periods', [...$d, 'code' => 'OVER'])->assertSessionHasErrors('starts_on');
        $this->post('/etudes/referentiels/periods', [...$d, 'code' => 'OUT', 'ends_on' => '2027-06-01'])->assertSessionHasErrors('starts_on');
        $this->post('/etudes/referentiels/periods', [...$d, 'code' => 'FOREIGN', 'parent_period_id' => Period::where('institution_id', $this->member('admin-b')->institution_id)->firstOrFail()->id])->assertNotFound();
        $this->patch('/etudes/referentiels/periods/'.$parent->id, ['action' => 'archive', 'lock_version' => 1])->assertSessionHasErrors('action');
    }

    public function test_personal_nested_periods_and_foreign_parents_are_protected(): void
    {
        $this->login('independant', false);
        $d = [...$this->payload('ROOT'), 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'weight' => 1, 'timezone' => 'Africa/Abidjan'];
        $this->post('/etudes/referentiels/periods', $d)->assertSessionHasNoErrors();
        $parent = PersonalPeriod::where('owner_id', $this->user('independant')->id)->firstOrFail();
        $this->post('/etudes/referentiels/periods', [...$d, 'code' => 'CHILD', 'parent_period_id' => $parent->id, 'starts_on' => '2026-02-01', 'ends_on' => '2026-03-01'])->assertSessionHasNoErrors();
        $this->post('/etudes/referentiels/periods', [...$d, 'code' => 'OVER'])->assertSessionHasErrors('starts_on');
        $this->login('apprenant-double', false)->post('/etudes/referentiels/periods', [...$d, 'parent_period_id' => $parent->id])->assertNotFound();
        $this->assertDatabaseCount('personal_periods', 2);
    }

    public function test_enrollment_duplicate_and_transfer_keep_history_atomically(): void
    {
        $member = $this->user('apprenant-double')->memberships()->where('institution_id', $this->member('admin-a')->institution_id)->firstOrFail();
        $previous = Enrollment::where('membership_id', $member->id)->firstOrFail();
        $d = ['membership_id' => $member->id, 'group_id' => $this->group('A-G1')->id, 'starts_at' => now()->format('Y-m-d\TH:i')];
        $this->login('admin-a')->post('/etudes/referentiels/enrollments', $d)->assertSessionHasErrors('membership_id');
        $this->post('/etudes/referentiels/enrollments', [...$d, 'group_id' => $this->group('B-G1')->id])->assertNotFound();
        $this->post('/etudes/referentiels/enrollments', [...$d, 'group_id' => $this->group('A-G2')->id, 'replace_enrollment_id' => $previous->id])->assertSessionHasNoErrors();
        $this->assertSame('closed', $previous->fresh()->status);
        $this->assertNotNull($previous->fresh()->ends_at);
        $this->assertSame(2, Enrollment::where('membership_id', $member->id)->count());
        $this->assertSame(1, Enrollment::where('membership_id', $member->id)->where('status', 'active')->count());
    }

    public function test_assignment_wrong_role_and_duplicate_refused_and_close_preserved(): void
    {
        $teacher = $this->member('enseignant-a');
        $old = TeachingAssignment::where('membership_id', $teacher->id)->firstOrFail();
        $d = ['membership_id' => $teacher->id, 'group_id' => $old->group_id, 'subject_id' => $old->subject_id, 'starts_at' => now()->format('Y-m-d\TH:i')];
        $this->login('admin-a')->post('/etudes/referentiels/assignments', $d)->assertSessionHasErrors('membership_id');
        $this->post('/etudes/referentiels/assignments', [...$d, 'membership_id' => $this->member('apprenant-double')->id])->assertNotFound();
        $this->post('/etudes/referentiels/assignments', [...$d, 'subject_id' => Subject::where('institution_id', $this->member('admin-b')->institution_id)->firstOrFail()->id])->assertNotFound();
        $this->patch('/etudes/referentiels/assignments/'.$old->id, ['action' => 'close', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('closed', $old->fresh()->status);
        $this->assertNotNull($old->fresh()->closed_at);
    }

    public function test_new_year_copies_selected_references_only_and_has_own_admin_context(): void
    {
        $acting = $this->member('admin-a');
        $parent = Period::where('academic_year_id', $acting->academic_year_id)->firstOrFail();
        $before = Enrollment::count();
        $this->login('admin-a')->withSession(['auth.password_confirmed_at' => 0])->post('/etudes/referentiels/years', [...$this->payload('NO-AUTH'), 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31'])->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('academic_years', ['code' => 'NO-AUTH']);
        $this->login('admin-a')->post('/etudes/referentiels/years', [...$this->payload('2027-2028'), 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31', 'copy_groups' => [$this->group('A-G1')->id], 'copy_periods' => [$parent->id]])->assertSessionHasNoErrors();
        $year = AcademicYear::where('code', '2027-2028')->firstOrFail();
        $this->assertSame(1, $year->groups()->count());
        $this->assertSame(1, $year->periods()->count());
        $this->assertSame('2027-09-01', $year->periods()->first()->starts_on->toDateString());
        $this->assertSame($before, Enrollment::count());
        $this->assertSame(1, Membership::where('academic_year_id', $year->id)->where('role', 'institution_admin')->count());
        $this->post('/etudes/referentiels/years', [...$this->payload('OVER'), 'starts_on' => '2027-01-01', 'ends_on' => '2028-01-01'])->assertSessionHasErrors('starts_on');
    }

    public function test_archive_year_revokes_context_and_blocks_further_changes(): void
    {
        $year = $this->member('admin-a')->academicYear;
        $this->login('admin-a')->patch('/etudes/referentiels/years/'.$year->id, ['action' => 'archive', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($year->fresh()->archived_at);
        $this->patch('/etudes/referentiels/groups/'.$this->group('A-G1')->id, [...$this->payload(), 'lock_version' => 1])->assertForbidden();
        $this->assertDatabaseHas('personal_spaces', ['owner_id' => $this->user('admin-a')->id]);
    }

    public function test_privileged_default_refusal_timezone_and_version(): void
    {
        $this->login('admin-a');
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->post('/etudes/referentiels/groups', $this->payload())->assertForbidden();
        config(['campus.identity.privileged_without_mfa' => true]);
        $this->patch('/etudes/fuseau', ['timezone' => 'Europe/Paris', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Europe/Paris', $this->member('admin-a')->institution->timezone);
        $this->patch('/etudes/fuseau', ['timezone' => 'Africa/Abidjan', 'lock_version' => 1])->assertStatus(409);
        $this->patch('/etudes/fuseau', ['timezone' => 'invalide', 'lock_version' => 2])->assertSessionHasErrors('timezone');
    }

    public function test_mysql_personal_scope_constraints_and_factories(): void
    {
        $a = PersonalPeriod::factory()->create();
        $b = PersonalPeriod::factory()->create();
        try {
            DB::table('personal_periods')->where('id', $a->id)->update(['parent_period_id' => $b->id]);
            $this->fail('FK attendue');
        } catch (QueryException $e) {
            $this->assertSame(1452, (int) $e->errorInfo[1]);
        }
        try {
            DB::table('academic_period_types')->insert(['code' => 'BAD', 'name' => 'BAD']);
            $this->fail('CHECK attendu');
        } catch (QueryException $e) {
            $this->assertSame(3819, (int) $e->errorInfo[1]);
        }
        $this->assertNotNull(PersonalSubject::factory()->create()->owner_id);
        $this->assertNotNull(AcademicPeriodType::factory()->create()->owner_id);
    }

    public function test_new_year_copy_failure_rolls_back_everything_and_archive_is_not_deletion(): void
    {
        $acting = $this->member('admin-a');
        $before = AcademicYear::count();
        $this->login('admin-a')->post('/etudes/referentiels/years', [...$this->payload('FAIL-COPY'), 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31', 'copy_groups' => [$this->group('B-G1')->id]])->assertNotFound();
        $this->assertSame($before, AcademicYear::count());
        $group = $this->group('A-G1');
        $count = Enrollment::where('group_id', $group->id)->count();
        $this->patch('/etudes/referentiels/groups/'.$group->id, ['action' => 'archive', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($group->fresh()->archived_at);
        $this->assertSame($count, Enrollment::where('group_id', $group->id)->count());
        $this->patch('/etudes/referentiels/groups/'.$group->id, [...$this->payload(), 'lock_version' => 2])->assertStatus(409);
    }

    public function test_lists_paginate_and_selector_search_never_includes_b(): void
    {
        $acting = $this->member('admin-a');
        Group::factory()->count(22)->create(['institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id]);
        $this->login('admin-a')->get('/etudes/referentiels?kind=groups&option_q=A-G2')->assertInertia(fn (Assert $p) => $p->has('rows', 20)->where('totalPages', 2)->has('options.groups', 1)->where('options.groups.0.code', 'A-G2'));
        $this->get('/etudes/referentiels?kind=groups&page=2')->assertInertia(fn (Assert $p) => $p->where('currentPage', 2)->has('rows', 4));
    }

    public function test_instants_use_institution_timezone_and_withdrawal_dates_history(): void
    {
        $acting = $this->member('admin-a');
        $acting->institution->forceFill(['timezone' => 'Europe/Paris'])->save();
        $teacher = $this->member('enseignant-a');
        $subject = Subject::where('institution_id', $acting->institution_id)->firstOrFail();
        $start = now()->setTimezone('Europe/Paris')->format('Y-m-d\TH:i');
        $this->login('admin-a')->post('/etudes/referentiels/assignments', ['membership_id' => $teacher->id, 'group_id' => $this->group('A-G2')->id, 'subject_id' => $subject->id, 'starts_at' => $start])->assertSessionHasNoErrors();
        $assignment = TeachingAssignment::latest('id')->firstOrFail();
        $this->assertSame($start, $assignment->starts_at->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'));
        $this->login('enseignant-a')->withSession(['auth.password_confirmed_at' => time()])->post('/profil/appartenances/'.$teacher->id.'/depart')->assertRedirect();
        $this->assertSame('closed', $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->ends_at);
        $this->assertSame(2, $assignment->fresh()->lock_version);
    }

    public function test_json_errors_have_stable_codes_french_messages_and_no_foreign_resource(): void
    {
        $this->getJson('/etudes/referentiels')->assertUnauthorized()->assertJsonPath('code', 'SESSION_ABSENT')->assertJsonStructure(['message', 'errors', 'request_id'])->assertHeader('X-Request-ID');
        $this->login('admin-a')->postJson('/etudes/referentiels/groups', [])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['name']])->assertJsonMissingPath('errors.code');
        $foreign = $this->patchJson('/etudes/referentiels/groups/'.$this->group('B-G1')->id, ['lock_version' => 1]);
        $foreign->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $unknown = $this->patchJson('/etudes/referentiels/groups/999999999', ['lock_version' => 1]);
        $this->assertSame($foreign->json('message'), $unknown->json('message'));
        $group = $this->group('A-G1');
        $this->patchJson('/etudes/referentiels/groups/'.$group->id, [...$this->payload(), 'lock_version' => 999])->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->login('enseignant-a')->postJson('/etudes/referentiels/groups', $this->payload())->assertForbidden()->assertJsonPath('code', 'ACCESS_FORBIDDEN');
        foreach ([413 => 'QUOTA_EXCEEDED', 429 => 'RATE_LIMITED', 500 => 'REQUEST_FAILED'] as $status => $code) {
            $request = Request::create('/etudes/referentiels', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
            $response = JsonErrorResponse::render(new Response('', $status, ['Retry-After' => '60']), new \RuntimeException('SECRET_FICTIF_A_NE_PAS_EXPOSER'), $request);
            $body = json_decode($response->getContent(), true);
            $this->assertSame($code, $body['code']);
            $this->assertStringNotContainsString('SECRET_FICTIF', $response->getContent());
            $this->assertSame('60', $response->headers->get('Retry-After'));
        }
    }
}
