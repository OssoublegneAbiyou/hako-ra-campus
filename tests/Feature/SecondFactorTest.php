<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SecondFactor;
use App\Models\User;
use App\Services\SecondFactors;
use App\Support\PrivilegedAccess;
use App\Support\Totp;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecondFactorTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Motdepasse-fictif-2026';

    private User $admin;

    private string $privilegedUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampusDemoSeeder::class);
        // Aucune dérogation : ces tests exercent le second facteur réel.
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->admin = User::where('email', 'admin-a@example.test')->firstOrFail();
        $this->admin->forceFill(['email_verified_at' => now(), 'password' => self::PASSWORD])->save();
        $this->privilegedUrl = '/etablissement/'.$this->admin->memberships()->firstOrFail()->id.'/comptes';
        RateLimiter::clear('second-factor|'.$this->admin->id);
    }

    private function signIn(): void
    {
        $this->freshDevice()->post('/connexion', ['login' => 'admin-a@example.test', 'password' => self::PASSWORD])->assertSessionHasNoErrors();
    }

    private function confirmPassword(): void
    {
        $this->post('/confirmation-mot-de-passe', ['password' => self::PASSWORD])->assertSessionHasNoErrors();
    }

    private function currentCode(): string
    {
        return Totp::code($this->admin->secondFactor()->firstOrFail()->secret, Totp::step());
    }

    /**
     * Active le second facteur par les écrans, comme le ferait la personne. Rend les codes de secours affichés une fois.
     *
     * @return list<string>
     */
    private function enroll(): array
    {
        $this->signIn();
        $this->confirmPassword();
        $this->post('/profil/second-facteur/activation')->assertRedirect('/profil/second-facteur');
        $response = $this->post('/profil/second-facteur/confirmation', ['code' => $this->currentCode()])->assertSessionHasNoErrors()->assertRedirect('/profil/second-facteur');

        return $response->getSession()->get('second_factor_recovery_codes');
    }

    public function test_codes_follow_rfc_6238_and_refuse_replay_and_distant_steps(): void
    {
        // Vecteurs de la RFC 6238 (SHA-1), secret ASCII « 12345678901234567890 ».
        $secret = Totp::encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('12345678901234567890', Totp::decode($secret));
        foreach ([59 => '94287082', 1111111109 => '07081804', 1234567890 => '89005924', 2000000000 => '69279037'] as $time => $expected) {
            $this->assertSame($expected, Totp::code($secret, Totp::step($time), 8));
            $this->assertSame(substr($expected, 2), Totp::code($secret, Totp::step($time)));
        }

        $now = 1_800_000_000;
        $step = Totp::step($now);
        $this->assertSame($step, Totp::match($secret, Totp::code($secret, $step), null, $now));
        $this->assertSame($step - 1, Totp::match($secret, Totp::code($secret, $step - 1), null, $now));
        $this->assertSame($step + 1, Totp::match($secret, ' '.Totp::code($secret, $step + 1).' ', null, $now));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step - 2), null, $now));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step + 2), null, $now));
        // Un code déjà accepté, ou plus ancien, ne ressert pas.
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step), $step, $now));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step - 1), $step, $now));
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $invalid) {
            $this->assertNull(Totp::match($secret, $invalid, null, $now));
        }
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
        $this->assertStringStartsWith('otpauth://totp/Campus%3Aadmin%40example.test?secret='.$secret.'&issuer=Campus', Totp::uri($secret, 'admin@example.test', 'Campus'));
    }

    public function test_r10_a_privileged_role_opens_only_with_an_enrolled_factor_presented_in_the_session(): void
    {
        // Sans second facteur : le compte se connecte, son espace personnel fonctionne, ses rôles privilégiés restent fermés.
        $this->signIn();
        $this->get('/profil')->assertOk()->assertInertia(fn (Assert $page) => $page->where('privilegedBlocked', true)->where('secondFactorEnabled', false));
        $this->get($this->privilegedUrl)->assertForbidden();
        $this->get('/profil/second-facteur')->assertOk()->assertInertia(fn (Assert $page) => $page->where('enabled', false)->where('required', true)->where('setup', null));

        // L'activation exige le mot de passe saisi récemment.
        $this->post('/profil/second-facteur/activation')->assertRedirect('/confirmation-mot-de-passe');
        $this->assertDatabaseMissing('second_factors', ['user_id' => $this->admin->id]);
        $this->confirmPassword();
        $this->post('/profil/second-facteur/activation')->assertRedirect('/profil/second-facteur');

        // Secret préparé mais non confirmé : rien n'est ouvert, et la connexion ne demande encore aucun code.
        $secret = $this->admin->secondFactor()->firstOrFail()->secret;
        $this->get('/profil/second-facteur')->assertOk()->assertInertia(fn (Assert $page) => $page->where('enabled', false)
            ->where('setup.secret', trim(chunk_split($secret, 4, ' ')))->where('setup.uri', fn (string $uri) => str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret='.$secret)));
        $this->get($this->privilegedUrl)->assertForbidden();
        $this->post('/profil/second-facteur/confirmation', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($this->admin->secondFactor()->first()->confirmed_at);

        // Confirmation par un code exact : codes de secours montrés une fois, rôle ouvert dans cette session.
        $response = $this->post('/profil/second-facteur/confirmation', ['code' => $this->currentCode()])->assertSessionHasNoErrors();
        $codes = $response->getSession()->get('second_factor_recovery_codes');
        $this->assertCount(SecondFactors::RECOVERY_CODES, $codes);
        $this->get($this->privilegedUrl)->assertOk();
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page->where('privilegedBlocked', false)->where('secondFactorEnabled', true));
        // Le secret n'est plus jamais montré ; la base ne contient ni secret ni code de secours en clair.
        $this->get('/profil/second-facteur')->assertInertia(fn (Assert $page) => $page->where('enabled', true)->where('setup', null)->where('recoveryCodes', null)->where('remainingRecoveryCodes', 8));
        $row = (array) \DB::table('second_factors')->where('user_id', $this->admin->id)->first();
        $this->assertStringNotContainsString($secret, $row['secret']);
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), $row['recovery_codes']);
        $this->assertSame(1, AuditLog::where('action', 'second_factor.enabled')->where('actor_id', $this->admin->id)->count());

        // Nouvelle connexion : après le mot de passe, tout renvoie au code, et la mise en page ne montre aucune appartenance.
        $this->post('/deconnexion');
        $this->signIn();
        $this->get('/profil')->assertRedirect('/second-facteur');
        $this->get($this->privilegedUrl)->assertRedirect('/second-facteur');
        $this->get('/taches')->assertRedirect('/second-facteur');
        $this->get('/opportunites')->assertRedirect('/second-facteur');
        $this->postJson('/synchronisation/v1/operations', ['operations' => []])->assertForbidden();
        $this->post('/taches', ['client_key' => (string) \Str::uuid(), 'title' => 'Tâche fictive'])->assertRedirect('/second-facteur');
        $this->assertDatabaseMissing('tasks', ['title' => 'Tâche fictive']);
        $this->get('/second-facteur')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/SecondFactorChallenge')
            ->where('campus.contexts', [])->where('auth.accountKey', null)->where('campus.context.membershipId', null));

        // Code faux, puis code déjà utilisé à l'activation : refusés. Le code du pas suivant est accepté et ramène à la page demandée.
        $this->post('/second-facteur', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/second-facteur', ['code' => $this->currentCode()])->assertSessionHasErrors('code');
        $this->get($this->privilegedUrl)->assertRedirect('/second-facteur');
        $this->travel(31)->seconds();
        $this->post('/second-facteur', ['code' => $this->currentCode()])->assertSessionHasNoErrors()->assertRedirect($this->privilegedUrl);
        $this->get($this->privilegedUrl)->assertOk();
        $this->get('/second-facteur')->assertRedirect('/profil');

        // Un autre appareil, avec le seul mot de passe, n'hérite pas de cette vérification.
        $this->signIn();
        $this->get($this->privilegedUrl)->assertRedirect('/second-facteur');
    }

    public function test_r10_recovery_codes_work_once_and_failed_attempts_are_limited(): void
    {
        $codes = $this->enroll();
        $this->post('/deconnexion');

        $this->signIn();
        $this->post('/second-facteur', ['code' => 'AAAAA-AAAAA'])->assertSessionHasErrors('code');
        $this->post('/second-facteur', ['code' => strtolower($codes[0])])->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->get($this->privilegedUrl)->assertOk();
        $this->get('/profil/second-facteur')->assertInertia(fn (Assert $page) => $page->where('remainingRecoveryCodes', 7));
        $this->assertSame(1, AuditLog::where('action', 'second_factor.recovery_code_used')->where('actor_id', $this->admin->id)->count());

        // Le même code de secours ne sert pas deux fois.
        $this->post('/deconnexion');
        $this->signIn();
        $this->post('/second-facteur', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->get($this->privilegedUrl)->assertRedirect('/second-facteur');

        // De nouveaux codes invalident les anciens.
        $this->post('/second-facteur', ['code' => $codes[1]])->assertSessionHasNoErrors();
        $this->confirmPassword();
        $fresh = $this->post('/profil/second-facteur/codes-de-secours')->assertRedirect('/profil/second-facteur')->getSession()->get('second_factor_recovery_codes');
        $this->assertCount(8, $fresh);
        $this->assertEmpty(array_intersect($fresh, $codes));
        $this->post('/deconnexion');
        $this->signIn();
        $this->post('/second-facteur', ['code' => $codes[2]])->assertSessionHasErrors('code');

        // Après trop d'échecs, même un code exact est refusé pendant le délai.
        RateLimiter::clear('second-factor|'.$this->admin->id);
        for ($attempt = 0; $attempt < (int) config('campus.identity.login_max_attempts'); $attempt++) {
            $this->post('/second-facteur', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post('/second-facteur', ['code' => $fresh[0]])->assertSessionHasErrors('code');
        $this->get($this->privilegedUrl)->assertRedirect('/second-facteur');
        $this->assertSame(8, app(SecondFactors::class)->remainingRecoveryCodes($this->admin->refresh()));
    }

    public function test_r10_removing_the_factor_needs_a_code_and_closes_the_privileged_role_again(): void
    {
        $this->enroll();
        $this->get($this->privilegedUrl)->assertOk();
        $this->delete('/profil/second-facteur', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertDatabaseHas('second_factors', ['user_id' => $this->admin->id]);
        $this->travel(31)->seconds();
        $this->delete('/profil/second-facteur', ['code' => $this->currentCode()])->assertSessionHasNoErrors()->assertRedirect('/profil/second-facteur');
        $this->assertDatabaseMissing('second_factors', ['user_id' => $this->admin->id]);
        $this->get($this->privilegedUrl)->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'second_factor.disabled')->where('actor_id', $this->admin->id)->count());
    }

    public function test_r10_controlled_recovery_by_the_operator_is_traced_and_does_not_reopen_the_role(): void
    {
        $this->enroll();
        $this->artisan('campus:second-factor:reset', ['login' => 'admin-a@example.test'])->assertFailed();
        $this->artisan('campus:second-factor:reset', ['login' => 'admin-a@example.test', '--reason' => 'court'])->assertFailed();
        $this->assertDatabaseHas('second_factors', ['user_id' => $this->admin->id]);
        $this->artisan('campus:second-factor:reset', ['login' => 'inconnu@example.test', '--reason' => 'Identité vérifiée en personne (fictif)'])->assertFailed();

        $this->artisan('campus:second-factor:reset', ['login' => 'admin-a@example.test', '--reason' => 'Identité vérifiée en personne (fictif)'])->assertSuccessful();
        $this->assertDatabaseMissing('second_factors', ['user_id' => $this->admin->id]);
        $entry = AuditLog::where('action', 'second_factor.reset_by_operator')->latest('id')->firstOrFail();
        $this->assertSame('system', $entry->actor_type);
        $this->assertSame('Identité vérifiée en personne (fictif)', $entry->reason);

        // Le retrait ne rouvre rien : sans nouveau facteur, le rôle reste fermé, y compris avec le bon mot de passe.
        $this->signIn();
        $this->get('/profil')->assertOk();
        $this->get($this->privilegedUrl)->assertForbidden();
        $this->artisan('campus:second-factor:reset', ['login' => 'admin-a@example.test', '--reason' => 'Identité vérifiée en personne (fictif)'])->assertFailed();
    }

    public function test_background_work_and_the_local_waiver_follow_the_same_rule(): void
    {
        // Hors requête (traitement de fond) : seul un compte au second facteur confirmé garde ses droits.
        $this->assertFalse(PrivilegedAccess::available($this->admin));
        SecondFactor::create(['user_id' => $this->admin->id, 'secret' => Totp::generateSecret()]);
        $this->assertFalse(PrivilegedAccess::available($this->admin->refresh()));
        $this->admin->secondFactor->forceFill(['confirmed_at' => now()])->save();
        $this->assertTrue(PrivilegedAccess::available($this->admin->refresh()));
        $this->assertFalse(PrivilegedAccess::available());

        // La dérogation n'existe qu'en local et en test.
        $other = User::where('email', 'admin-b@example.test')->firstOrFail();
        config(['campus.identity.privileged_without_mfa' => true]);
        $this->assertTrue(PrivilegedAccess::available($other));
        app()->instance('env', 'production');
        $this->assertFalse(PrivilegedAccess::waived());
        $this->assertFalse(PrivilegedAccess::available($other));
        app()->instance('env', 'testing');
    }

    public function test_a_second_factor_is_optional_for_an_account_without_privileged_role(): void
    {
        $learner = User::where('email', 'independant@example.test')->firstOrFail();
        $learner->forceFill(['email_verified_at' => now(), 'password' => self::PASSWORD])->save();
        $this->freshDevice()->post('/connexion', ['login' => 'independant@example.test', 'password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->get('/taches')->assertOk();
        $this->get('/profil/second-facteur')->assertOk()->assertInertia(fn (Assert $page) => $page->where('required', false)->where('enabled', false));
        $this->confirmPassword();
        $this->post('/profil/second-facteur/activation');
        $secret = $learner->secondFactor()->firstOrFail()->secret;
        $this->post('/profil/second-facteur/confirmation', ['code' => Totp::code($secret, Totp::step())])->assertSessionHasNoErrors();
        $this->post('/deconnexion');
        $this->freshDevice()->post('/connexion', ['login' => 'independant@example.test', 'password' => self::PASSWORD]);
        $this->get('/taches')->assertRedirect('/second-facteur');
        // Abandonner une activation non confirmée ne demande aucun code ; ici le facteur est confirmé, le code est exigé.
        $this->travel(31)->seconds();
        $this->post('/second-facteur', ['code' => Totp::code($secret, Totp::step())])->assertSessionHasNoErrors();
        $this->get('/taches')->assertOk();
    }
}
