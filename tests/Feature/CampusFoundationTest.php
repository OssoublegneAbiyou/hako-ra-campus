<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalSpace;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ContextVisibility;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampusFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampusDemoSeeder::class);
    }

    private function person(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $person, string $institution, string $role = 'learner'): Membership
    {
        return $this->person($person)->memberships()->where('role', $role)
            ->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$institution))->firstOrFail();
    }

    private function group(string $code): Group
    {
        return Group::where('code', $code)->firstOrFail();
    }

    private function row(Model $model): array
    {
        return Arr::except($model->getRawOriginal(), ['id', 'scope_key', 'current_slot', 'current_local_identifier']);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La base a accepté une relation incohérente.');
        } catch (QueryException $error) {
            $this->assertContains((int) $error->errorInfo[1], [1048, 1062, 1451, 1452, 3819]);
        }
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Un périmètre étranger a été exposé.');
        } catch (ModelNotFoundException|AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_fixtures_are_repeatable_and_roles_do_not_live_on_the_account(): void
    {
        $before = [Institution::count(), User::count(), Membership::count(), PersonalSpace::count(), Enrollment::count()];
        $this->seed(CampusDemoSeeder::class);
        $this->assertSame($before, [Institution::count(), User::count(), Membership::count(), PersonalSpace::count(), Enrollment::count()]);
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertSame(0, $this->person('independant')->memberships()->count());
        $this->assertNotNull($this->person('independant')->personalSpace);
        $this->assertSame(2, $this->person('apprenant-double')->memberships()->count());
        $this->assertSame(['learner', 'teacher'], $this->person('polyvalent')->memberships()->orderBy('role')->pluck('role')->all());
    }

    public function test_same_local_identifier_is_scoped_to_institution_and_year(): void
    {
        $a = $this->member('apprenant-double', 'A');
        $b = $this->member('apprenant-double', 'B');
        $this->assertSame($a->local_identifier, $b->local_identifier);
        $other = User::factory()->create();
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert([...$this->row($a), 'user_id' => $other->id]));
        $a->revoke();
        $replacement = Membership::create([...$this->row($a->fresh()), 'user_id' => $other->id, 'status' => 'active', 'revoked_at' => null]);
        $this->assertSame('MAT-001', $replacement->local_identifier);
        $this->assertNotSame($replacement->user_id, $b->user_id);
    }

    public function test_current_and_suspended_memberships_cannot_be_duplicated_even_with_null_platform_ids(): void
    {
        $membership = $this->member('apprenant-double', 'A');
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert($this->row($membership)));
        $membership->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert([...$this->row($membership->fresh()), 'status' => 'active', 'suspended_at' => null]));
        $platform = $this->person('editeur')->memberships()->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert($this->row($platform)));
    }

    public function test_scope_checks_do_not_treat_null_or_platform_roles_as_public(): void
    {
        $membership = $this->member('apprenant-double', 'A');
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert([...$this->row($membership), 'scope' => 'platform', 'role' => 'catalogue_editor']));
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert([...$this->row($membership), 'institution_id' => null, 'academic_year_id' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('personal_spaces')->insert(['owner_id' => null, 'timezone' => 'Africa/Abidjan']));
    }

    public function test_mysql_rejects_groups_and_memberships_with_a_year_of_another_institution(): void
    {
        $a = $this->group('A-G1');
        $b = $this->group('B-G1');
        $this->assertDatabaseRejects(fn () => DB::table('groups')->insert([...$this->row($a), 'code' => 'CROISE', 'academic_year_id' => $b->academic_year_id]));
        $membership = $this->member('enseignant-a', 'A', 'teacher');
        $this->assertDatabaseRejects(fn () => DB::table('memberships')->insert([...$this->row($membership), 'academic_year_id' => $b->academic_year_id]));
    }

    public function test_mysql_rejects_cross_institution_and_cross_year_enrollments(): void
    {
        $enrollment = $this->member('apprenant-double', 'A')->enrollments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert([...$this->row($enrollment), 'group_id' => $this->group('B-G1')->id]));
        $year = AcademicYear::factory()->create(['institution_id' => $enrollment->institution_id]);
        $group = Group::factory()->create(['institution_id' => $year->institution_id, 'academic_year_id' => $year->id]);
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert([...$this->row($enrollment), 'group_id' => $group->id]));
        $otherMembership = $this->member('apprenant-double', 'B');
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert([...$this->row($enrollment), 'membership_id' => $otherMembership->id]));
    }

    public function test_mysql_rejects_wrong_roles_and_subjects_in_assignments(): void
    {
        $teacher = $this->member('enseignant-a', 'A', 'teacher');
        $enrollment = $this->member('apprenant-double', 'A')->enrollments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert([
            ...$this->row($enrollment), 'membership_id' => $teacher->id, 'user_id' => $teacher->user_id,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert([
            ...$this->row($enrollment), 'membership_id' => $teacher->id, 'user_id' => $teacher->user_id, 'membership_role' => 'teacher',
        ]));
        $assignment = $teacher->teachingAssignments()->firstOrFail();
        $subject = Subject::where('institution_id', $this->group('B-G1')->institution_id)->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('teaching_assignments')->insert([...$this->row($assignment), 'subject_id' => $subject->id]));
    }

    public function test_relations_can_be_closed_and_recreated_without_duplicate_current_rows(): void
    {
        $membership = $this->member('apprenant-double', 'A');
        $enrollment = $membership->enrollments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('enrollments')->insert($this->row($enrollment)));
        $enrollment->close();
        $replacement = Enrollment::create([...$this->row($enrollment->fresh()), 'status' => 'active', 'closed_at' => null, 'starts_at' => now(), 'ends_at' => null]);
        $this->assertNotSame($enrollment->id, $replacement->id);
        $this->assertNotNull($enrollment->fresh()->closed_at);
        $this->assertSame(1, $membership->enrollments()->effective()->count());
        $assignment = $this->member('enseignant-a', 'A', 'teacher')->teachingAssignments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('teaching_assignments')->insert($this->row($assignment)));
    }

    public function test_private_preferences_and_other_membership_survive_a_withdrawal(): void
    {
        $actor = $this->person('apprenant-double');
        $space = $actor->personalSpace;
        $preferences = $space->preferences;
        $a = $this->member('apprenant-double', 'A');
        $b = $this->member('apprenant-double', 'B');
        $visibility = app(ContextVisibility::class);
        $this->assertSame($this->group('A-G1')->id, $visibility->group($actor, $a->id, $this->group('A-G1')->id)->id);
        $a->revoke();
        $this->assertDenied(fn () => $visibility->institutionMembership($actor, $a->id));
        $this->assertSame($preferences, $visibility->personalSpace($actor, $space->id)->preferences);
        $this->assertSame($this->group('B-G1')->id, $visibility->group($actor, $b->id, $this->group('B-G1')->id)->id);
        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
        $this->assertDatabaseHas('enrollments', ['membership_id' => $a->id]);
    }

    public function test_private_spaces_have_no_platform_or_editor_bypass(): void
    {
        $visibility = app(ContextVisibility::class);
        $space = $this->person('independant')->personalSpace;
        foreach (['plateforme', 'editeur', 'admin-a', 'enseignant-a'] as $actor) {
            $this->assertDenied(fn () => $visibility->personalSpace($this->person($actor), $space->id));
        }
        $this->assertTrue($visibility->canEditCatalogue($this->person('editeur')));
        $this->assertFalse($visibility->canEditCatalogue($this->person('plateforme')));
    }

    public function test_groups_require_the_selected_membership_and_the_actual_assignment(): void
    {
        $visibility = app(ContextVisibility::class);
        $teacher = $this->person('enseignant-a');
        $teacherMembership = $this->member('enseignant-a', 'A', 'teacher');
        $this->assertSame([$this->group('A-G1')->id], $visibility->groups($teacher, $teacherMembership->id)->pluck('id')->all());
        $this->assertDenied(fn () => $visibility->group($teacher, $teacherMembership->id, $this->group('A-G2')->id));
        $this->assertDenied(fn () => $visibility->group($teacher, $teacherMembership->id, $this->group('B-G1')->id));
        $admin = $this->person('admin-a');
        $adminMembership = $this->member('admin-a', 'A', 'institution_admin');
        $this->assertSame(2, $visibility->groups($admin, $adminMembership->id)->count());
        $this->assertDenied(fn () => $visibility->group($admin, $adminMembership->id, $this->group('B-G1')->id));
        $this->assertDenied(fn () => $visibility->institutionMembership($teacher, $adminMembership->id));
    }

    public function test_expired_future_suspended_and_archived_contexts_remove_access(): void
    {
        $visibility = app(ContextVisibility::class);
        $actor = $this->person('apprenant-double');
        foreach ([
            ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()],
            ['starts_at' => now()->addDay()],
            ['status' => 'suspended', 'suspended_at' => now()],
        ] as $attributes) {
            $membership = Membership::factory()->create(['user_id' => $actor->id, ...$attributes]);
            $this->assertDenied(fn () => $visibility->institutionMembership($actor, $membership->id));
        }
        $membership = $this->member('apprenant-double', 'A');
        $membership->academicYear->update(['archived_at' => now()]);
        $this->assertDenied(fn () => $visibility->institutionMembership($actor, $membership->id));
        $membership->revoke();
        $this->assertSame('closed', $membership->fresh()->status);
    }

    public function test_account_suspension_is_checked_against_current_data_not_a_stale_user(): void
    {
        $actor = $this->person('independant');
        $space = $actor->personalSpace;
        DB::table('users')->where('id', $actor->id)->update(['status' => 'suspended']);
        $this->assertDenied(fn () => app(ContextVisibility::class)->personalSpace($actor, $space->id));
        $this->assertDatabaseHas('personal_spaces', ['owner_id' => $actor->id]);
    }

    public function test_referenced_parents_cannot_be_deleted_and_archiving_preserves_history(): void
    {
        $group = $this->group('A-G1');
        $this->assertDatabaseRejects(fn () => DB::table('groups')->where('id', $group->id)->delete());
        $owner = $this->person('independant');
        $this->assertDatabaseRejects(fn () => DB::table('users')->where('id', $owner->id)->delete());
        $group->update(['archived_at' => now()]);
        $membership = $this->member('apprenant-double', 'A');
        $this->assertDenied(fn () => app(ContextVisibility::class)->group($membership->user, $membership->id, $group->id));
        $assignment = $group->teachingAssignments()->firstOrFail();
        $assignment->close();
        $this->assertNotNull($assignment->fresh()->closed_at);
        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }

    public function test_dates_period_containment_and_immutable_context_are_validated(): void
    {
        $group = $this->group('A-G1');
        $year = $group->academicYear;
        try {
            Period::factory()->create(['institution_id' => $group->institution_id, 'academic_year_id' => $year->id, 'starts_on' => '2025-01-01']);
            $this->fail('Période extérieure acceptée.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('starts_on', $error->errors());
        }
        $this->assertDatabaseRejects(fn () => DB::table('academic_years')->insert([
            ...$this->row($year), 'code' => 'INVALIDE', 'starts_on' => '2027-08-31', 'ends_on' => '2026-09-01',
        ]));
        try {
            $group->update(['institution_id' => $this->group('B-G1')->institution_id]);
            $this->fail('Déplacement du contexte accepté.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('institution_id', $error->errors());
        }
    }

    public function test_factories_create_coherent_memberships_enrollments_and_assignments(): void
    {
        $enrollment = Enrollment::factory()->create();
        $assignment = TeachingAssignment::factory()->create();
        $platform = Membership::factory()->platform()->create();
        $period = Period::factory()->create();
        $this->assertSame($enrollment->institution_id, $enrollment->group->institution_id);
        $this->assertSame($assignment->institution_id, $assignment->subject->institution_id);
        $this->assertNull($platform->institution_id);
        $this->assertSame($period->institution_id, $period->academicYear->institution_id);
    }

    public function test_demo_seeder_is_refused_in_production(): void
    {
        app()->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        app(CampusDemoSeeder::class)->run();
    }

    public function test_period_parent_must_share_the_year_and_dates_cannot_invalidate_children(): void
    {
        $a = $this->group('A-G1');
        $b = $this->group('B-G1');
        $parent = Period::where('academic_year_id', $a->academic_year_id)->firstOrFail();
        $other = Period::where('academic_year_id', $b->academic_year_id)->firstOrFail();
        $this->assertDatabaseRejects(fn () => DB::table('periods')->insert([
            ...$this->row($parent), 'code' => 'CROISE', 'parent_period_id' => $other->id,
        ]));
        $this->assertDatabaseRejects(fn () => DB::table('periods')->insert([
            ...$this->row($parent), 'code' => 'POIDS', 'weight' => 0,
        ]));
        Period::factory()->create([
            'institution_id' => $a->institution_id, 'academic_year_id' => $a->academic_year_id,
            'parent_period_id' => $parent->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31',
        ]);
        foreach ([$parent, $a->academicYear] as $record) {
            try {
                $record->update(['ends_on' => '2026-11-30']);
                $this->fail('Modification qui invalide une période acceptée.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('ends_on', $error->errors());
            }
        }
    }

    public function test_future_assignment_can_be_closed_without_losing_the_effective_withdrawal_date(): void
    {
        $membership = $this->member('enseignant-a', 'A', 'teacher');
        $group = $this->group('A-G2');
        $subject = Subject::where('institution_id', $group->institution_id)->firstOrFail();
        $assignment = TeachingAssignment::factory()->create([
            'membership_id' => $membership->id, 'user_id' => $membership->user_id,
            'institution_id' => $membership->institution_id, 'academic_year_id' => $membership->academic_year_id,
            'group_id' => $group->id, 'subject_id' => $subject->id, 'starts_at' => now()->addWeek(),
        ]);
        $assignment->close();
        $this->assertSame('closed', $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->closed_at);
        $this->assertDenied(fn () => app(ContextVisibility::class)->teachingAssignment($membership->user, $membership->id, $assignment->id));
    }

    public function test_seeder_refuses_another_database_before_connecting_to_it(): void
    {
        $database = config('database.connections.mysql.database');
        $before = User::count();
        config(['database.connections.mysql.database' => 'foreign_project_do_not_connect']);
        try {
            app(CampusDemoSeeder::class)->run();
            $this->fail('Le seeder a accepté une autre base.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixtures réservées aux bases locales Campus dédiées.', $error->getMessage());
        } finally {
            config(['database.connections.mysql.database' => $database]);
        }
        $this->assertSame($before, User::count());
    }
}
