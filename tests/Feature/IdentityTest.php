<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\User;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Motdepasse-fictif-2026';

    private const WRONG = 'Identifiant ou mot de passe incorrect.';

    private function account(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
    }

    /** @return array<string, mixed> */
    private function registration(array $overrides = []): array
    {
        return [
            'name' => 'Personne fictive', 'email' => 'inscription-fictive@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'adult_declaration' => true, ...$overrides,
        ];
    }

    /**
     * Simule un appareil distinct : cookie de session propre et garde d'authentification réinitialisée.
     */
    private function device(?string $sessionId): static
    {
        $this->freshDevice();
        if ($sessionId !== null) {
            $this->defaultCookies = [config('session.cookie') => $sessionId];
        }

        return $this;
    }

    private function sessionIdFrom(TestResponse $response): string
    {
        return $response->getCookie(config('session.cookie'))->getValue();
    }

    private function loginOnNewDevice(User $user): string
    {
        $response = $this->device(null)->post('/connexion', ['login' => $user->email, 'password' => self::PASSWORD]);
        $response->assertRedirect('/profil');

        return $this->sessionIdFrom($response);
    }

    public function test_r01_registration_creates_a_private_space_without_any_role_and_requires_verification(): void
    {
        Notification::fake();

        $this->post('/inscription', $this->registration([
            'role' => 'institution_admin', 'institution_id' => 1, 'status' => 'suspended', 'email_verified_at' => now()->toDateTimeString(),
        ]))->assertRedirect('/verification-email');

        $user = User::where('email', 'inscription-fictive@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->memberships()->count());
        $this->assertNotNull($user->personalSpace);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNotSame(self::PASSWORD, $user->password);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/profil')->assertRedirect('/verification-email');
        $this->get('/verification-email')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/VerifyEmail')->where('mailConfigured', false));
    }

    public function test_r01_verification_link_expires_and_only_a_valid_link_opens_the_account(): void
    {
        Notification::fake();
        $this->post('/inscription', $this->registration());
        $user = User::where('email', 'inscription-fictive@example.test')->firstOrFail();
        $link = fn (): string => Notification::sent($user, VerifyEmail::class)->last()->toMail($user)->actionUrl;
        $expired = $link();

        $this->get(preg_replace('/signature=[a-f0-9]{4}/', 'signature=0000', $expired))->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($expired)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->post('/verification-email/renvoi')->assertSessionHas('status');
        $this->get($link())->assertRedirect('/profil');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Profile')->where('account.email', 'inscription-fictive@example.test')->where('memberships', []));
    }

    public function test_r01_a_verification_link_cannot_verify_another_account(): void
    {
        Notification::fake();
        $owner = $this->account(['email_verified_at' => null]);
        $other = $this->account(['email_verified_at' => null]);
        $owner->sendEmailVerificationNotification();
        $link = Notification::sent($owner, VerifyEmail::class)->last()->toMail($owner)->actionUrl;

        $this->actingAs($other)->get($link)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_minors_pathway_stays_closed_without_a_validated_framework_reference(): void
    {
        Notification::fake();
        $this->post('/inscription', $this->registration(['adult_declaration' => false]))->assertSessionHasErrors('adult_declaration');
        config(['campus.minors.enabled' => true]);
        $this->post('/inscription', $this->registration(['adult_declaration' => false]))->assertSessionHasErrors('adult_declaration');
        $this->assertDatabaseMissing('users', ['email' => 'inscription-fictive@example.test']);

        config(['campus.minors.framework_reference' => 'Référence fictive de test']);
        $this->post('/inscription', $this->registration(['adult_declaration' => false]))->assertRedirect('/verification-email');
        $this->assertNull(User::where('email', 'inscription-fictive@example.test')->firstOrFail()->adult_declared_at);
    }

    public function test_registration_can_be_closed_and_rejects_weak_passwords(): void
    {
        $this->post('/inscription', $this->registration(['password' => 'court1', 'password_confirmation' => 'court1']))
            ->assertSessionHasErrors('password');
        config(['campus.identity.registration_enabled' => false]);
        $this->get('/inscription')->assertNotFound();
        $this->post('/inscription', $this->registration())->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'inscription-fictive@example.test']);
    }

    public function test_r02_repeated_wrong_secrets_are_throttled_with_the_same_answer_for_unknown_accounts(): void
    {
        $user = $this->account();
        foreach ([$user->email, 'inconnu-fictif@example.test'] as $login) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->post('/connexion', ['login' => $login, 'password' => 'Mauvais-secret-1'])
                    ->assertSessionHasErrors(['login' => self::WRONG]);
            }
            $blocked = $this->post('/connexion', ['login' => $login, 'password' => self::PASSWORD]);
            $blocked->assertSessionHasErrors('login');
            $this->assertStringStartsWith('Trop de tentatives.', session('errors')->first('login'));
        }
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/connexion', ['login' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/profil');
        $this->assertAuthenticatedAs($user);
    }

    public function test_r02_suspended_accounts_get_the_generic_answer_and_lose_their_open_session(): void
    {
        $user = $this->account();
        $this->actingAs($user)->get('/profil')->assertOk();
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

        $this->freshDevice()->actingAs($user->fresh())->get('/profil')->assertRedirect('/connexion');
        $this->assertGuest();
        $this->freshDevice()->post('/connexion', ['login' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['login' => self::WRONG]);
    }

    public function test_r02_password_recovery_answers_identically_whether_the_account_exists_or_not(): void
    {
        Notification::fake();
        $user = $this->account();

        foreach ([$user->email, 'inconnu-fictif@example.test'] as $email) {
            $this->from('/mot-de-passe-oublie')->post('/mot-de-passe-oublie', ['email' => $email])
                ->assertRedirect('/mot-de-passe-oublie')
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', PasswordResetController::GENERIC_STATUS);
        }
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);

        for ($request = 1; $request <= 6; $request++) {
            $this->post('/mot-de-passe-oublie', ['email' => 'inconnu-'.$request.'@example.test'])
                ->assertSessionHas('status', PasswordResetController::GENERIC_STATUS);
        }
        $other = $this->account();
        $this->post('/mot-de-passe-oublie', ['email' => $other->email])
            ->assertSessionHas('status', PasswordResetController::GENERIC_STATUS);
        Notification::assertNotSentTo($other, ResetPassword::class);
    }

    public function test_r02_reset_links_expire_are_single_use_and_close_every_session(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->account();
        $staleSession = $this->loginOnNewDevice($user);
        $this->device(null);
        $reset = fn (string $token, string $password): TestResponse => $this->post('/reinitialisation', [
            'token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password,
        ]);

        $expired = Password::createToken($user);
        $this->travel(61)->minutes();
        $reset($expired, 'Nouveau-secret-2026')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        $token = Password::createToken($user);
        $reset('jeton-invente', 'Nouveau-secret-2026')->assertSessionHasErrors('email');
        $reset($token, 'Nouveau-secret-2026')->assertRedirect('/connexion');
        $this->assertTrue(Hash::check('Nouveau-secret-2026', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->device($staleSession)->get('/profil')->assertRedirect('/connexion');
        $this->device(null);
        $reset($token, 'Autre-secret-2026')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Nouveau-secret-2026', $user->fresh()->password));
    }

    public function test_sessions_are_listed_and_a_revoked_device_is_really_signed_out(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->account();
        $stranger = $this->account();
        $deviceA = $this->loginOnNewDevice($user);
        $deviceB = $this->loginOnNewDevice($user);
        $strangerDevice = $this->loginOnNewDevice($stranger);
        $handle = fn (string $sessionId): string => hash('sha256', $sessionId);

        $this->device($deviceA)->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('sessionsSupported', true)->has('sessions', 2)
            ->where('sessions', fn ($sessions) => collect($sessions)->where('current', true)->count() === 1
                && ! collect($sessions)->pluck('handle')->contains($deviceA)
                && collect($sessions)->pluck('handle')->contains($handle($deviceB))));

        $this->device($deviceA)->delete('/profil/sessions/'.$handle($deviceB))->assertRedirect('/confirmation-mot-de-passe');
        $this->device($deviceB)->get('/profil')->assertOk();

        $this->device($deviceA)->post('/confirmation-mot-de-passe', ['password' => 'Mauvais-secret-1'])->assertSessionHasErrors('password');
        $this->device($deviceA)->post('/confirmation-mot-de-passe', ['password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->device($deviceA)->delete('/profil/sessions/'.$handle($strangerDevice))->assertNotFound();
        $this->device($deviceA)->delete('/profil/sessions/'.$handle($deviceA))->assertNotFound();
        $this->device($deviceA)->delete('/profil/sessions/'.$handle($deviceB))->assertRedirect('/profil');

        $this->device($deviceB)->get('/profil')->assertRedirect('/connexion');
        $this->device($strangerDevice)->get('/profil')->assertOk();
        $this->device($deviceA)->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page->has('sessions', 1));

        $deviceC = $this->loginOnNewDevice($user);
        $this->device($deviceA)->delete('/profil/sessions')->assertRedirect('/profil');
        $this->device($deviceC)->get('/profil')->assertRedirect('/connexion');
        $this->device($deviceA)->get('/profil')->assertOk();
    }

    public function test_logout_closes_the_session_and_login_renews_the_session_identifier(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->account();
        $anonymous = $this->sessionIdFrom($this->device(null)->get('/connexion'));
        $response = $this->device($anonymous)->post('/connexion', ['login' => $user->email, 'password' => self::PASSWORD]);
        $authenticated = $this->sessionIdFrom($response);
        $this->assertNotSame($anonymous, $authenticated);
        $this->device($anonymous)->get('/profil')->assertRedirect('/connexion');

        $this->device($authenticated)->post('/deconnexion')->assertRedirect('/');
        $this->device($authenticated)->get('/profil')->assertRedirect('/connexion');
    }

    public function test_r10_privileged_actions_stay_closed_without_a_second_factor(): void
    {
        $this->seed(CampusDemoSeeder::class);
        $admin = User::where('email', 'admin-a@example.test')->firstOrFail();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $membership = $admin->memberships()->firstOrFail();
        $url = '/etablissement/'.$membership->id.'/comptes';

        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('privilegedBlocked', true)->where('memberships.0.accountsHref', null));

        config(['campus.identity.privileged_without_mfa' => true]);
        $this->get($url)->assertOk();
        app()->instance('env', 'production');
        $this->get($url)->assertForbidden();
    }

    public function test_session_cookies_are_http_only_same_site_and_secure_by_default_in_production(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $configuration = file_get_contents(config_path('session.php'));
        $this->assertStringContainsString("env('APP_ENV', 'production') === 'production' ? true : null", $configuration);
    }
}
