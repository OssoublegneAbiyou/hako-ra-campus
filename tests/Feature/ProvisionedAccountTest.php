<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProvisionedAccountTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Motdepasse-fictif-2026';

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

    private function adminMembership(string $letter): Membership
    {
        return $this->person('admin-'.strtolower($letter))->memberships()->firstOrFail();
    }

    private function asAdmin(string $letter, bool $confirmed = true): static
    {
        return $this->asPerson($this->person('admin-'.strtolower($letter)), $confirmed);
    }

    private function asPerson(User $actor, bool $confirmed = true): static
    {
        return $this->freshDevice()->actingAs($actor)
            ->withSession($confirmed ? ['auth.password_confirmed_at' => time()] : []);
    }

    private function provision(string $letter = 'A', array $overrides = []): TestResponse
    {
        return $this->asAdmin($letter)->post('/etablissement/'.$this->adminMembership($letter)->id.'/comptes', [
            'name' => 'Apprenant fictif sans adresse', 'local_identifier' => 'MAT-900', 'adult_attested' => true, ...$overrides,
        ]);
    }

    /** @return array{0: User, 1: string} */
    private function provisioned(): array
    {
        $this->provision()->assertSessionHasNoErrors();
        $flash = session('oneTimeSecret');

        return [User::where('login_identifier', $flash['identifier'])->firstOrFail(), $flash['secret']];
    }

    private function asGuest(): static
    {
        return $this->freshDevice();
    }

    private function activate(User $user, string $secret, string $password = self::PASSWORD): TestResponse
    {
        return $this->asGuest()->post('/activation', [
            'identifier' => $user->login_identifier, 'secret' => $secret,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_an_administrator_provisions_an_individual_account_attached_to_their_own_perimeter(): void
    {
        $admin = $this->adminMembership('A');
        $response = $this->provision('A', ['institution_id' => $this->adminMembership('B')->institution_id, 'role' => 'teacher', 'email' => 'force@example.test']);
        $response->assertRedirect('/etablissement/'.$admin->id.'/comptes');
        $flash = session('oneTimeSecret');
        $user = User::where('login_identifier', $flash['identifier'])->firstOrFail();

        $this->assertNull($user->email);
        $this->assertMatchesRegularExpression('/^HK-[A-Z2-9]{8}$/', $user->login_identifier);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $flash['secret']);
        $this->assertNotSame('MAT-900', $user->login_identifier);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNotNull($user->personalSpace);
        $membership = $user->memberships()->sole();
        $this->assertSame(['learner', $admin->institution_id, $admin->academic_year_id, 'MAT-900'],
            [$membership->role, $membership->institution_id, $membership->academic_year_id, $membership->local_identifier]);

        $raw = DB::table('users')->where('id', $user->id)->first();
        $plain = str_replace('-', '', $flash['secret']);
        $this->assertStringNotContainsString($plain, json_encode($raw));
        $this->assertTrue(Hash::check($plain, $raw->activation_secret_hash));
        $log = AuditLog::where('action', 'account.provisioned')->where('subject_id', $user->id)->sole();
        $this->assertSame($this->person('admin-a')->id, $log->actor_id);
        $this->assertStringNotContainsString($plain, json_encode($log->getAttributes()));

        $this->get('/etablissement/'.$admin->id.'/comptes')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('institution/ProvisionedAccounts')->has('accounts', 1)
            ->where('accounts.0.loginIdentifier', $user->login_identifier)->where('accounts.0.state', 'pending')
            ->where('flash.oneTimeSecret.secret', $flash['secret']));
        $this->get('/etablissement/'.$admin->id.'/comptes')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('flash.oneTimeSecret', null));
        $this->assertStringNotContainsString($plain, json_encode(session()->all()));
    }

    public function test_provisioning_requires_reauthentication_attestation_and_a_free_local_identifier(): void
    {
        $url = '/etablissement/'.$this->adminMembership('A')->id.'/comptes';
        $count = User::count();
        $this->asAdmin('A', confirmed: false)->post($url, ['name' => 'Fictif', 'local_identifier' => 'MAT-901', 'adult_attested' => true])
            ->assertRedirect('/confirmation-mot-de-passe');
        $this->provision('A', ['adult_attested' => false])->assertSessionHasErrors('adult_attested');
        $this->provision('A', ['local_identifier' => 'MAT-001'])->assertSessionHasErrors('local_identifier');
        $this->assertSame($count, User::count());
        $this->provision('B', ['local_identifier' => 'MAT-900'])->assertSessionHasNoErrors();
        $this->provision('A', ['local_identifier' => 'MAT-900'])->assertSessionHasNoErrors();
        $this->assertSame($count + 2, User::count());
    }

    public function test_other_roles_and_other_institutions_cannot_provision_or_list(): void
    {
        $adminB = $this->adminMembership('B');
        $payload = ['name' => 'Fictif', 'local_identifier' => 'MAT-902', 'adult_attested' => true];
        $count = User::count();

        $this->asAdmin('A')->post('/etablissement/'.$adminB->id.'/comptes', $payload)->assertNotFound();
        $this->asAdmin('A')->get('/etablissement/'.$adminB->id.'/comptes')->assertNotFound();
        foreach (['enseignant-a' => 'teacher', 'apprenant-double' => 'learner'] as $name => $role) {
            $actor = $this->person($name);
            $own = $actor->memberships()->where('role', $role)->firstOrFail();
            $this->asPerson($actor)->post('/etablissement/'.$own->id.'/comptes', $payload)->assertForbidden();
            $this->asPerson($actor)->post('/etablissement/'.$this->adminMembership('A')->id.'/comptes', $payload)->assertNotFound();
        }
        $this->asPerson($this->person('plateforme'))
            ->post('/etablissement/'.$this->adminMembership('A')->id.'/comptes', $payload)->assertNotFound();
        $this->asGuest()->post('/etablissement/'.$this->adminMembership('A')->id.'/comptes', $payload)->assertRedirect('/connexion');
        $this->assertSame($count, User::count());
    }

    public function test_activation_replaces_the_temporary_secret_which_never_works_as_a_password(): void
    {
        [$user, $secret] = $this->provisioned();

        foreach ([$secret, str_replace('-', '', $secret), 'MAT-900'] as $guess) {
            $this->asGuest()->post('/connexion', ['login' => $user->login_identifier, 'password' => $guess])->assertSessionHasErrors('login');
        }
        $this->asGuest()->post('/connexion', ['login' => 'MAT-900', 'password' => $secret])->assertSessionHasErrors('login');
        $this->activate($user, 'AAAA-BBBB-CCCC')->assertSessionHasErrors('identifier');
        $this->activate($user, $secret, 'court1')->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->activated_at);

        $this->activate($user, strtolower($secret))->assertRedirect('/profil');
        $this->assertAuthenticatedAs($user);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->activated_at);
        $this->assertNull($fresh->activation_secret_hash);
        $this->assertTrue($fresh->hasVerifiedEmail());
        $this->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account.provisioned', true)->where('account.email', null)->has('memberships', 1));

        $this->activate($user, $secret, 'Autre-secret-2026')->assertSessionHasErrors('identifier');
        $this->asGuest()->post('/connexion', ['login' => strtolower($user->login_identifier), 'password' => self::PASSWORD])->assertRedirect('/profil');
        $this->asGuest()->post('/mot-de-passe-oublie', ['email' => 'inconnu-fictif@example.test'])->assertSessionHasNoErrors();
    }

    public function test_temporary_secret_expires_and_guessing_is_throttled(): void
    {
        [$user, $secret] = $this->provisioned();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->activate($user, 'AAAA-BBBB-CCC'.$attempt)->assertSessionHasErrors('identifier');
        }
        $this->activate($user, $secret)->assertSessionHasErrors('identifier');
        $this->assertStringStartsWith('Trop de tentatives.', session('errors')->first('identifier'));
        $this->assertNull($user->fresh()->activated_at);

        $this->travel(73)->hours();
        $this->activate($user, $secret)->assertSessionHasErrors(['identifier' => 'Identifiant ou secret temporaire invalide ou expiré.']);
        $this->assertNull($user->fresh()->activated_at);
        $this->asAdmin('A')->get('/etablissement/'.$this->adminMembership('A')->id.'/comptes')
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.state', 'expired'));
    }

    public function test_assisted_recovery_needs_an_identity_check_invalidates_access_and_leaves_a_limited_trace(): void
    {
        config(['session.driver' => 'database']);
        [$user, $secret] = $this->provisioned();
        $this->activate($user, $secret)->assertRedirect('/profil');
        DB::table('sessions')->insert(['id' => 'session-fictive-recuperation', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $url = '/etablissement/'.$this->adminMembership('A')->id.'/comptes/'.$user->id.'/recuperation';
        $valid = ['identity_check' => 'in_person_document', 'identity_confirmed' => true];

        $this->asAdmin('A', confirmed: false)->post($url, $valid)->assertRedirect('/confirmation-mot-de-passe');
        $this->asAdmin('A')->post($url, ['identity_check' => 'in_person_document'])->assertSessionHasErrors('identity_confirmed');
        $this->asAdmin('A')->post($url, ['identity_check' => 'par_telephone', 'identity_confirmed' => true])->assertSessionHasErrors('identity_check');
        $this->asAdmin('B')->post('/etablissement/'.$this->adminMembership('B')->id.'/comptes/'.$user->id.'/recuperation', $valid)->assertNotFound();
        $this->asAdmin('A')->post('/etablissement/'.$this->adminMembership('A')->id.'/comptes/'.$this->person('apprenant-double')->id.'/recuperation', $valid)->assertNotFound();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertSame(0, AuditLog::where('action', 'account.assisted_recovery')->count());

        $this->asAdmin('A')->post($url, $valid)->assertSessionHasNoErrors();
        $newSecret = session('oneTimeSecret')['secret'];
        $this->assertNotSame($secret, $newSecret);
        $fresh = $user->fresh();
        $this->assertFalse(Hash::check(self::PASSWORD, $fresh->password));
        $this->assertNull($fresh->activated_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $log = AuditLog::where('action', 'account.assisted_recovery')->sole();
        $this->assertSame([$this->person('admin-a')->id, $user->id, 'in_person_document'], [$log->actor_id, $log->subject_id, $log->reason]);
        $this->assertStringNotContainsString(str_replace('-', '', $newSecret), json_encode($log->getAttributes()));

        $this->asGuest()->post('/connexion', ['login' => $user->login_identifier, 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->activate($user, $secret, 'Nouveau-secret-2026')->assertSessionHasErrors('identifier');
        $this->activate($user, $newSecret, 'Nouveau-secret-2026')->assertRedirect('/profil');
    }

    public function test_an_account_needs_an_email_or_an_identifier_and_identifiers_are_unique(): void
    {
        [$user] = $this->provisioned();
        $row = ['name' => 'Fictif', 'password' => 'x', 'status' => 'active'];
        foreach ([[...$row, 'email' => null, 'login_identifier' => null], [...$row, 'email' => null, 'login_identifier' => $user->login_identifier]] as $invalid) {
            try {
                DB::table('users')->insert($invalid);
                $this->fail('La base a accepté un compte sans moyen de connexion unique.');
            } catch (QueryException $error) {
                $this->assertContains((int) $error->errorInfo[1], [1062, 3819]);
            }
        }
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::record(null, 'test.fictif');
        $this->assertSame('system', $log->actor_type);
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'modifie']);
    }
}
