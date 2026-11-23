<?php

namespace Tests\Feature;

use App\Jobs\DeliverInvitation;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\ActiveContext;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionProperty;
use Tests\TestCase;

class InvitationContextTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
    }

    private function person(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function membership(string $person, string $role): Membership
    {
        return $this->person($person)->memberships()->where('role', $role)->firstOrFail();
    }

    private function as(User $actor, ?Membership $context = null, bool $confirmed = true): static
    {
        return $this->freshDevice()->actingAs($actor)->withSession(array_filter([
            'auth.password_confirmed_at' => $confirmed ? time() : null,
            ActiveContext::SESSION_KEY => $context?->id,
        ]));
    }

    private function asAdmin(string $letter, bool $confirmed = true): static
    {
        $name = 'admin-'.strtolower($letter);

        return $this->as($this->person($name), $this->membership($name, 'institution_admin'), $confirmed);
    }

    private function asPlatform(bool $confirmed = true): static
    {
        return $this->as($this->person('plateforme'), $this->membership('plateforme', 'platform_admin'), $confirmed);
    }

    /** Crée une invitation par l'écran d'administration et retourne le secret du lien affiché une seule fois. */
    private function invite(string $letter, array $data): string
    {
        $this->asAdmin($letter)->post('/administration/invitations', $data)->assertSessionHasNoErrors()->assertRedirect();

        return basename(session('oneTimeLink')['url']);
    }

    private function accept(User $user, string $token, array $payload = []): TestResponse
    {
        return $this->as($user)->post('/invitation/'.$token.'/acceptation', $payload);
    }

    private function group(string $code): Group
    {
        return Group::where('code', $code)->firstOrFail();
    }

    public function test_r03_an_existing_account_accepts_without_a_second_identity_and_only_gets_the_invited_role(): void
    {
        $adminA = $this->membership('admin-a', 'institution_admin');
        $adminB = $this->membership('admin-b', 'institution_admin');
        $token = $this->invite('A', ['role' => 'learner', 'local_identifier' => 'MAT-500', 'group_id' => $this->group('A-G2')->id]);
        $invitation = Invitation::latest('id')->firstOrFail();
        $this->assertSame(Invitation::hashToken($token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('invitations')->where('id', $invitation->id)->first()));
        $this->assertStringNotContainsString($token, json_encode(AuditLog::query()->get()->toArray()));

        $this->freshDevice()->get('/invitation/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('InvitationShow')->where('invitation.perimeter', 'Établissement fictif A')
            ->where('invitation.role', 'Apprenant')->where('account', null));
        $this->assertSame(route('invitations.show', $token), session('url.intended'));

        $user = $this->person('independant');
        $users = User::count();
        $this->accept($user, $token, ['role' => 'institution_admin', 'institution_id' => $adminB->institution_id, 'scope' => 'platform'])
            ->assertRedirect('/profil');

        $this->assertSame($users, User::count());
        $granted = $user->memberships()->sole();
        $this->assertSame(['learner', $adminA->institution_id, $adminA->academic_year_id, 'MAT-500', 'active'],
            [$granted->role, $granted->institution_id, $granted->academic_year_id, $granted->local_identifier, $granted->status]);
        $this->assertSame($this->group('A-G2')->id, $granted->enrollments()->sole()->group_id);
        $this->assertSame(['accepted', $user->id, $granted->id], [$invitation->fresh()->status, $invitation->fresh()->accepted_by, $invitation->fresh()->membership_id]);
        $this->assertNotNull($user->personalSpace);
        $this->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campus.context.kind', 'institution')->where('campus.context.label', 'Établissement fictif A')
            ->where('campus.context.description', 'Apprenant')->has('campus.contexts', 1));

        $this->accept($this->person('editeur'), $token)->assertRedirect('/invitation/'.$token);
        $this->accept($user, $token)->assertRedirect('/invitation/'.$token);
        $this->assertSame(1, Membership::where('institution_id', $adminA->institution_id)->where('local_identifier', 'MAT-500')->count());
        $this->assertSame(1, $this->person('editeur')->memberships()->count());
    }

    public function test_r04_expired_revoked_or_unknown_invitations_create_no_membership_and_answer_alike(): void
    {
        $user = $this->person('independant');
        $expired = $this->invite('A', ['role' => 'learner']);
        $revoked = $this->invite('A', ['role' => 'teacher']);
        $revokedId = Invitation::latest('id')->value('id');
        $this->asAdmin('A')->post('/administration/invitations/'.$revokedId.'/revocation')->assertSessionHasNoErrors();
        $this->asAdmin('A')->post('/administration/invitations/'.$revokedId.'/revocation')->assertSessionHasErrors('invitation');
        $this->travel(15)->days();

        $messages = [];
        foreach ([$expired, $revoked, 'jeton-invente-jeton-invente-123'] as $token) {
            $this->freshDevice()->get('/invitation/'.$token)->assertNotFound()->assertInertia(function (Assert $page) use (&$messages) {
                $page->where('invitation', null);
                $messages[] = $page->toArray()['props']['invalidMessage'];
            });
            $this->assertNull(session('url.intended'));
            $this->accept($user, $token)->assertRedirect('/invitation/'.$token);
        }
        $this->assertCount(1, array_unique($messages));
        $this->assertSame(0, $user->memberships()->count());
        $this->assertSame('pending', Invitation::where('token_hash', Invitation::hashToken($expired))->value('status'));
    }

    public function test_r05_same_local_identifier_coexists_across_institutions_and_conflicts_stay_pending(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->accept($first, $this->invite('A', ['role' => 'learner', 'local_identifier' => 'MAT-777']))->assertRedirect('/profil');
        $this->accept($second, $this->invite('B', ['role' => 'learner', 'local_identifier' => 'MAT-777']))->assertRedirect('/profil');
        $this->assertSame('MAT-777', $first->memberships()->sole()->local_identifier);
        $this->assertSame('MAT-777', $second->memberships()->sole()->local_identifier);
        $this->assertNotSame($first->memberships()->sole()->institution_id, $second->memberships()->sole()->institution_id);

        $candidate = $this->person('independant');
        $conflict = $this->invite('A', ['role' => 'learner', 'local_identifier' => 'MAT-001']);
        $conflictId = Invitation::latest('id')->value('id');
        $this->accept($candidate, $conflict)->assertRedirect('/profil')->assertSessionHas('status');
        $this->assertSame(0, $candidate->memberships()->count());
        $this->assertSame(['pending_review', 'identifier_conflict', $candidate->id], array_values(Invitation::findOrFail($conflictId)->only(['status', 'review_reason', 'accepted_by'])));
        $this->as($candidate)->get('/profil')->assertInertia(fn (Assert $page) => $page->where('campus.contexts', [])->where('campus.context.kind', 'individual'));
        $this->accept($this->person('editeur'), $conflict)->assertRedirect('/invitation/'.$conflict);

        $this->asAdmin('B')->post('/administration/invitations/'.$conflictId.'/decision', ['decision' => 'approve'])->assertNotFound();
        $this->asAdmin('A', confirmed: false)->post('/administration/invitations/'.$conflictId.'/decision', ['decision' => 'approve'])->assertRedirect('/confirmation-mot-de-passe');
        $this->asAdmin('A')->post('/administration/invitations/'.$conflictId.'/decision', ['decision' => 'approve'])->assertSessionHasErrors('invitation');
        $this->assertSame(0, $candidate->memberships()->count());
        $this->asAdmin('A')->post('/administration/invitations/'.$conflictId.'/decision', ['decision' => 'reject'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', Invitation::findOrFail($conflictId)->status);
        $this->assertSame(1, Membership::where('local_identifier', 'MAT-001')->where('institution_id', $this->membership('admin-a', 'institution_admin')->institution_id)->count());

        Notification::fake();
        $mismatch = $this->invite('A', ['role' => 'teacher', 'email' => 'Autre-Fictif@example.test']);
        $mismatchId = Invitation::latest('id')->value('id');
        $this->accept($candidate, $mismatch)->assertRedirect('/profil');
        $this->assertSame(['pending_review', 'email_mismatch'], array_values(Invitation::findOrFail($mismatchId)->only(['status', 'review_reason'])));
        $this->assertSame(0, $candidate->memberships()->count());
        $this->asAdmin('A')->post('/administration/invitations/'.$mismatchId.'/decision', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('teacher', $candidate->memberships()->sole()->role);
        $this->assertSame(1, AuditLog::where('action', 'membership.granted')->where('actor_id', $this->person('admin-a')->id)->count());
    }

    public function test_r06_one_account_switches_between_explicit_contexts_verified_on_the_server(): void
    {
        $user = $this->person('apprenant-double');
        $a = $user->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-A'))->firstOrFail();
        $b = $user->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-B'))->firstOrFail();
        $foreign = $this->membership('admin-a', 'institution_admin');

        $this->as($user)->get('/profil')->assertInertia(fn (Assert $page) => $page
            ->where('campus.context.kind', 'individual')->where('campus.context.membershipId', null)->has('campus.contexts', 2));
        $this->post('/contexte', ['membership_id' => $a->id])->assertRedirect('/profil');
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page
            ->where('campus.context.label', 'Établissement fictif A')->where('campus.context.membershipId', $a->id));
        $this->post('/contexte', ['membership_id' => $b->id])->assertRedirect('/profil');
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page->where('campus.context.label', 'Établissement fictif B'));

        $this->post('/contexte', ['membership_id' => $foreign->id])->assertNotFound();
        $this->post('/contexte', ['membership_id' => 999999])->assertNotFound();
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page->where('campus.context.membershipId', $b->id));
        $this->post('/contexte', ['membership_id' => null])->assertRedirect('/profil');
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page->where('campus.context.kind', 'individual'));

        $this->as($user, $foreign)->get('/administration/etablissement')->assertForbidden();
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page
            ->where('campus.context.kind', 'individual')->where('campus.administration', []));
        $this->assertNull(session(ActiveContext::SESSION_KEY));
        $this->as($user, $a)->get('/administration/etablissement')->assertForbidden();
        $this->as($this->person('polyvalent'), $this->membership('polyvalent', 'teacher'))->get('/administration/etablissement')->assertForbidden();
    }

    public function test_r06_administration_pages_and_export_only_show_the_active_perimeter(): void
    {
        $adminB = $this->membership('admin-b', 'institution_admin');
        $this->asAdmin('A')->get('/administration/etablissement?institution_id='.$adminB->institution_id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/Institution')
                ->where('institution', 'Établissement fictif A')
                ->where('campus.administration.0.label', 'Établissement')
                ->where('members', fn ($members) => collect($members)->pluck('name')->contains('Enseignant fictif A')
                    && ! collect($members)->pluck('name')->contains('Administrateur fictif B')
                    && collect($members)->where('name', 'Administrateur fictif A')->first()['revocable'] === false));

        $csv = $this->asAdmin('A')->get('/administration/etablissement/membres/export?institution_id='.$adminB->institution_id.'&membership='.$adminB->id)
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
        $this->assertStringContainsString('Enseignant fictif A', $csv);
        $this->assertStringContainsString('MAT-001', $csv);
        $this->assertStringNotContainsString('Administrateur fictif B', $csv);
        $this->assertStringNotContainsString('@example.test', $csv);
        $this->assertSame(1, AuditLog::where('action', 'members.exported')->where('institution_id', '!=', $adminB->institution_id)->count());

        $this->person('enseignant-a')->update(['name' => '=HYPERLINK("http://exemple.invalid")']);
        $csv = $this->asAdmin('A')->get('/administration/etablissement/membres/export')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_r07_role_escalation_attempts_are_refused_by_the_server_and_traced(): void
    {
        $teacher = $this->person('enseignant-a');
        $teacherMembership = $this->membership('enseignant-a', 'teacher');
        $adminA = $this->membership('admin-a', 'institution_admin');
        $invitations = Invitation::count();

        $this->as($teacher, $teacherMembership)->post('/administration/invitations', [
            'role' => 'institution_admin', 'institution_id' => $adminA->institution_id, 'academic_year_id' => $adminA->academic_year_id,
        ])->assertForbidden();
        $this->as($teacher, $teacherMembership)->post('/administration/invitations', ['role' => 'learner'])->assertForbidden();
        $this->as($teacher)->post('/administration/invitations', ['role' => 'teacher'])->assertForbidden();
        $this->as($teacher, $teacherMembership)->get('/administration/etablissement')->assertForbidden();
        $this->as($teacher, $teacherMembership)->get('/administration/plateforme')->assertForbidden();
        $this->as($teacher, $teacherMembership)->post('/administration/appartenances/'.$adminA->id.'/retrait')->assertForbidden();
        foreach (['institution_admin', 'catalogue_editor', 'platform_admin'] as $role) {
            $this->asAdmin('A')->post('/administration/invitations', ['role' => $role])->assertForbidden();
        }
        $this->asAdmin('A')->post('/administration/plateforme/etablissements', ['code' => 'PIRATE', 'name' => 'Fictif'])->assertForbidden();
        $this->asPlatform()->post('/administration/invitations', ['role' => 'platform_admin'])->assertForbidden();
        $this->asPlatform()->post('/administration/invitations', ['role' => 'teacher'])->assertForbidden();

        $this->assertSame($invitations, Invitation::count());
        $this->assertSame(['teacher'], $teacher->memberships()->pluck('role')->all());
        $denied = AuditLog::where('action', 'privilege.escalation_denied')->get();
        $this->assertSame(7, $denied->count());
        $this->assertTrue($denied->contains(fn (AuditLog $log) => $log->actor_id === $teacher->id
            && $log->metadata == ['requested_role' => 'institution_admin', 'acting_role' => 'teacher']));
        $this->assertDatabaseMissing('institutions', ['code' => 'PIRATE']);
    }

    public function test_r08_withdrawal_closes_access_at_once_and_keeps_the_account_and_private_space(): void
    {
        $user = $this->person('apprenant-double');
        $a = $user->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-A'))->firstOrFail();
        $b = $user->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-B'))->firstOrFail();
        $preferences = $user->personalSpace->preferences;

        $this->asAdmin('A', confirmed: false)->post('/administration/appartenances/'.$a->id.'/retrait')->assertRedirect('/confirmation-mot-de-passe');
        $this->assertSame('active', $a->fresh()->status);
        $this->asAdmin('A')->post('/administration/appartenances/'.$a->id.'/retrait')->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('closed', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertSame(0, $a->enrollments()->where('status', 'active')->count());
        $this->assertSame(1, AuditLog::where('action', 'membership.revoked')->where('subject_id', $a->id)->count());
        $this->as($user, $a)->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campus.context.kind', 'individual')->has('campus.contexts', 1)->has('memberships', 1));
        $this->post('/contexte', ['membership_id' => $a->id])->assertNotFound();
        $this->post('/contexte', ['membership_id' => $b->id])->assertRedirect('/profil');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
        $this->assertSame($preferences, $user->personalSpace()->firstOrFail()->preferences);
        $this->asAdmin('A')->post('/administration/appartenances/'.$a->id.'/retrait')->assertNotFound();

        $adminA = $this->membership('admin-a', 'institution_admin');
        $this->asAdmin('A')->get('/administration/etablissement')->assertOk();
        $this->asPlatform()->post('/administration/appartenances/'.$adminA->id.'/retrait')->assertSessionHasNoErrors();
        $this->as($this->person('admin-a'), $adminA)->get('/administration/etablissement')->assertForbidden();
        $this->as($this->person('admin-a'), $adminA)->get('/administration/etablissement/membres/export')->assertForbidden();
        $this->as($this->person('admin-a'), $adminA)->post('/administration/invitations', ['role' => 'learner'])->assertForbidden();
        $this->as($this->person('admin-a'))->get('/profil')->assertOk();
    }

    public function test_r08_a_member_can_leave_and_only_their_own_membership(): void
    {
        $user = $this->person('polyvalent');
        $teacher = $this->membership('polyvalent', 'teacher');
        $foreign = $this->membership('enseignant-a', 'teacher');

        $this->as($user, confirmed: false)->post('/profil/appartenances/'.$teacher->id.'/depart')->assertRedirect('/confirmation-mot-de-passe');
        $this->as($user)->post('/profil/appartenances/'.$foreign->id.'/depart')->assertNotFound();
        $this->as($user)->post('/profil/appartenances/'.$teacher->id.'/depart')->assertRedirect('/profil');
        $this->assertSame('closed', $teacher->fresh()->status);
        $this->assertSame('active', $foreign->fresh()->status);
        $this->assertSame(0, $teacher->teachingAssignments()->where('status', 'active')->count());
        $this->assertSame(['learner'], $user->memberships()->effective()->pluck('role')->all());
        $this->assertSame(1, AuditLog::where('action', 'membership.left')->where('actor_id', $user->id)->count());
    }

    public function test_r09_direct_identifiers_of_another_institution_are_not_found_for_mutations(): void
    {
        $adminB = $this->membership('admin-b', 'institution_admin');
        $learnerB = $this->person('apprenant-double')->memberships()->where('institution_id', $adminB->institution_id)->firstOrFail();
        $this->invite('B', ['role' => 'learner']);
        $invitationB = Invitation::latest('id')->firstOrFail();

        foreach ([$learnerB->id, $adminB->id, 999999] as $id) {
            $this->asAdmin('A')->post('/administration/appartenances/'.$id.'/retrait')->assertNotFound();
        }
        foreach ([$invitationB->id, 999999] as $id) {
            $this->asAdmin('A')->post('/administration/invitations/'.$id.'/revocation')->assertNotFound();
            $this->asAdmin('A')->post('/administration/invitations/'.$id.'/decision', ['decision' => 'reject'])->assertNotFound();
        }
        $this->assertSame('active', $learnerB->fresh()->status);
        $this->assertSame('pending', $invitationB->fresh()->status);

        $this->asAdmin('A')->post('/administration/invitations', ['role' => 'learner', 'group_id' => $this->group('B-G1')->id])
            ->assertSessionHasErrors('group_id');
        $this->asAdmin('A')->post('/administration/invitations', [
            'role' => 'learner', 'institution_id' => $adminB->institution_id, 'academic_year_id' => $adminB->academic_year_id,
        ])->assertSessionHasNoErrors();
        $created = Invitation::latest('id')->firstOrFail();
        $this->assertSame($this->membership('admin-a', 'institution_admin')->institution_id, $created->institution_id);
        $this->assertNotSame($adminB->institution_id, $created->institution_id);
    }

    public function test_r09_the_delivery_job_rechecks_the_sender_rights_when_it_runs(): void
    {
        Notification::fake();
        $adminA = $this->membership('admin-a', 'institution_admin');
        $this->assertInstanceOf(ShouldBeEncrypted::class, new DeliverInvitation(1, 1, 1, 'x'));

        $legit = $this->invite('A', ['role' => 'learner', 'email' => 'invite-a@example.test']);
        Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'invite-a@example.test' && str_ends_with($notification->url, '/invitation/'.$legit));
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);

        $tokenB = $this->invite('B', ['role' => 'learner', 'email' => 'invite-b@example.test']);
        $invitationB = Invitation::latest('id')->firstOrFail();
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
        DeliverInvitation::dispatchSync($invitationB->id, $this->person('admin-a')->id, $adminA->id, $tokenB);
        DeliverInvitation::dispatchSync($invitationB->id, $this->person('admin-a')->id, $this->membership('admin-b', 'institution_admin')->id, $tokenB);
        DeliverInvitation::dispatchSync($invitationB->id, $this->person('admin-b')->id, $this->membership('admin-b', 'institution_admin')->id, 'mauvais-secret');
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
        $this->assertSame(2, AuditLog::where('action', 'invitation.delivery_refused')->where('subject_id', $invitationB->id)->count());

        $invitationA = Invitation::where('token_hash', Invitation::hashToken($legit))->firstOrFail();
        $adminA->revoke();
        DeliverInvitation::dispatchSync($invitationA->id, $this->person('admin-a')->id, $adminA->id, $legit);
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
    }

    public function test_only_the_platform_creates_institutions_and_empowers_their_administration(): void
    {
        $payload = ['code' => 'fictif-c', 'name' => 'Établissement fictif C', 'timezone' => 'Africa/Abidjan',
            'year_name' => 'Année fictive 2026–2027', 'year_code' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31'];
        $this->asPlatform(confirmed: false)->post('/administration/plateforme/etablissements', $payload)->assertRedirect('/confirmation-mot-de-passe');
        $this->asPlatform()->post('/administration/plateforme/etablissements', [...$payload, 'ends_on' => '2026-01-01'])->assertSessionHasErrors('ends_on');
        $this->asPlatform()->post('/administration/plateforme/etablissements', $payload)->assertSessionHasNoErrors();
        $institution = Institution::where('code', 'FICTIF-C')->firstOrFail();
        $year = $institution->academicYears()->sole();
        $this->assertSame(1, AuditLog::where('action', 'institution.created')->where('subject_id', $institution->id)->count());

        $this->asPlatform()->post('/administration/invitations', ['role' => 'institution_admin', 'institution_id' => $institution->id, 'academic_year_id' => 999999])
            ->assertSessionHasErrors('academic_year_id');
        $this->asPlatform(confirmed: false)->post('/administration/invitations', ['role' => 'institution_admin', 'institution_id' => $institution->id, 'academic_year_id' => $year->id])
            ->assertRedirect('/confirmation-mot-de-passe');
        $this->asPlatform()->post('/administration/invitations', ['role' => 'institution_admin', 'institution_id' => $institution->id, 'academic_year_id' => $year->id])
            ->assertSessionHasNoErrors();
        $token = basename(session('oneTimeLink')['url']);
        $this->assertTrue(AuditLog::where('action', 'invitation.created')->latest('id')->firstOrFail()->metadata['privileged']);

        $newAdmin = $this->person('independant');
        $this->accept($newAdmin, $token)->assertRedirect('/profil');
        $granted = $newAdmin->memberships()->sole();
        $this->assertSame(['institution_admin', $institution->id], [$granted->role, $granted->institution_id]);
        $this->as($newAdmin, $granted)->get('/administration/etablissement')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('institution', 'Établissement fictif C')->has('members', 1));

        $this->asPlatform()->get('/administration/plateforme')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Platform')->has('institutions', 3)->where('campus.administration.0.label', 'Plateforme'));
        $this->asPlatform()->get('/administration/etablissement')->assertForbidden();
        $this->asPlatform()->get('/administration/etablissement/membres/export')->assertForbidden();
    }

    public function test_r58_no_role_reads_another_private_space_and_there_is_no_global_bypass(): void
    {
        $space = $this->person('independant')->personalSpace;
        foreach (['plateforme', 'admin-a', 'enseignant-a', 'editeur'] as $name) {
            $this->assertTrue(Gate::forUser($this->person($name))->denies('view', $space));
            $this->assertTrue(Gate::forUser($this->person($name))->denies('update', $space));
        }
        $this->assertTrue(Gate::forUser($this->person('independant'))->allows('view', $space));
        $this->assertSame([], (new ReflectionProperty(app(GateContract::class), 'beforeCallbacks'))->getValue(app(GateContract::class)));
        $routes = collect(app('router')->getRoutes())->map->uri()->implode(' ');
        $this->assertStringNotContainsString('impersonat', $routes);
        $this->assertStringNotContainsString('support', $routes);
    }

    public function test_administration_stays_closed_and_hidden_without_the_second_factor(): void
    {
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->asAdmin('A')->get('/administration/etablissement')->assertForbidden();
        $this->asAdmin('A')->post('/administration/invitations', ['role' => 'learner'])->assertForbidden();
        $this->asPlatform()->get('/administration/plateforme')->assertForbidden();
        $this->asAdmin('A')->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('campus.administration', [])->where('campus.context.description', 'Administration de l’établissement'));
    }
}
