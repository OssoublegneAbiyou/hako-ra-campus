<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SecondFactor;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\GoogleSignIn;
use App\Support\Totp;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Connexion par compte Google. Google n'est jamais appelé : sa réponse est fabriquée par le test. Aucun identifiant réel n'est utilisé.
 */
class GoogleSignInTest extends TestCase
{
    use DatabaseTransactions;

    private const CLIENT_ID = 'client-fictif.apps.example.test';

    private const PASSWORD = 'Motdepasse-fictif-2026';

    /** @var array<string, string> */
    private array $flow = [];

    /** @var array{body: array<string, mixed>, status: int} Réponse que « Google » rendra au prochain échange. */
    private array $answer = ['body' => [], 'status' => 500];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampusDemoSeeder::class);
        config(['services.google.client_id' => self::CLIENT_ID, 'services.google.client_secret' => 'secret-fictif-de-test', 'campus.identity.privileged_without_mfa' => false]);
        Http::preventStrayRequests();
        // Une seule fabrication, relue à chaque échange : chaque retour reçoit la réponse préparée pour lui.
        Http::fake([GoogleSignIn::TOKEN_ENDPOINT => fn () => Http::response($this->answer['body'], $this->answer['status'])]);
    }

    /**
     * Départ vers Google : rend les paramètres de l'adresse vers laquelle la personne est envoyée.
     *
     * @return array<string, string>
     */
    private function depart(string $path = '/connexion/google'): array
    {
        $location = (string) $this->get($path)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(GoogleSignIn::AUTHORIZATION_ENDPOINT.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->flow = $query;
    }

    /**
     * Retour de Google : la réponse de Google est fabriquée avec les revendications indiquées.
     *
     * @param  array<string, mixed>  $claims
     */
    private function retour(array $claims = [], ?string $state = null, int $status = 200): TestResponse
    {
        $claims += ['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => now()->unix() + 600, 'nonce' => $this->flow['nonce'] ?? '',
            'sub' => 'google-sujet-fictif-1', 'email' => 'Nouvelle.Personne@example.test', 'email_verified' => true, 'name' => 'Nouvelle Personne', 'auth_time' => now()->unix()];
        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $this->answer = ['body' => ['access_token' => 'jeton-fictif', 'id_token' => $encode(['alg' => 'RS256']).'.'.$encode($claims).'.signature'], 'status' => $status];

        return $this->get('/connexion/google/reponse?state='.urlencode($state ?? ($this->flow['state'] ?? '')).'&code=code-fictif');
    }

    private function account(string $name, bool $verified = true): User
    {
        $user = User::where('email', $name.'@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => $verified ? now() : null, 'password' => self::PASSWORD])->save();

        return $user;
    }

    public function test_google_sign_in_is_closed_until_it_is_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/connexion')->assertOk()->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));
        $this->get('/connexion/google')->assertNotFound();
        $this->get('/connexion/google/reponse?state=x&code=y')->assertNotFound();
        config(['services.google.client_id' => self::CLIENT_ID]);
        $this->get('/connexion')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
        $this->get('/inscription')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
    }

    public function test_the_request_to_google_carries_state_nonce_and_pkce_and_never_the_secret(): void
    {
        $query = $this->depart();
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame(route('google.callback'), $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame(40, strlen($query['nonce']));
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertNotSame($query['state'], $this->depart()['state']);

        // À l'échange, Campus présente le vérificateur dont l'empreinte a été envoyée au départ, à l'adresse fixe de Google seulement.
        $this->retour(['email' => 'independant@example.test']);
        $this->account('independant');
        Http::assertSent(function (ClientRequest $request): bool {
            $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) $request['code_verifier'], true)), '+/', '-_'), '=');

            return $request->url() === GoogleSignIn::TOKEN_ENDPOINT && $request['grant_type'] === 'authorization_code' && $request['code'] === 'code-fictif'
                && $challenge === $this->flow['code_challenge'] && $request['redirect_uri'] === route('google.callback');
        });
        Http::assertSentCount(1);
    }

    public function test_an_unknown_google_account_creates_a_campus_account_only_after_the_adult_declaration(): void
    {
        $before = User::count();
        $this->depart();
        $this->retour()->assertRedirect('/connexion/google/creation');
        $this->assertGuest();
        $this->assertSame($before, User::count());
        $this->get('/connexion/google/creation')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/GoogleRegistration')
            ->where('email', 'nouvelle.personne@example.test')->where('name', 'Nouvelle Personne')->where('minorsPathwayOpen', false));

        // Sans la déclaration, rien n'est créé. Un rôle ou un établissement glissé dans la demande est ignoré.
        $this->post('/connexion/google/creation', ['name' => 'Nouvelle Personne'])->assertSessionHasErrors('adult_declaration');
        $this->assertSame($before, User::count());
        $this->post('/connexion/google/creation', ['name' => 'Personne fictive', 'adult_declaration' => true, 'role' => 'platform_admin', 'institution_id' => 1, 'email' => 'autre@example.test'])
            ->assertSessionHasNoErrors()->assertRedirect('/profil');

        $user = User::where('email', 'nouvelle.personne@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Personne fictive', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->adult_declared_at);
        $this->assertNull($user->password_changed_at);
        $this->assertSame(0, $user->memberships()->count());
        $this->assertDatabaseHas('personal_spaces', ['owner_id' => $user->id]);
        $this->assertDatabaseMissing('users', ['email' => 'autre@example.test']);
        // Campus ne garde que l'identifiant du compte Google et l'adresse : aucun jeton.
        $identity = SocialIdentity::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['id', 'user_id', 'provider', 'subject', 'email', 'created_at', 'updated_at'], array_keys($identity->getAttributes()));
        $this->assertSame(['google', 'google-sujet-fictif-1'], [$identity->provider, $identity->subject]);
        $this->assertSame(1, AuditLog::where('action', 'google.account_created')->where('actor_id', $user->id)->count());
        $this->get('/taches')->assertOk();

        // Connexion suivante : reconnue par l'identifiant Google, même si l'adresse Google a changé entre-temps. Aucun second compte.
        $this->post('/deconnexion');
        $this->freshDevice();
        $this->depart();
        $this->retour(['email' => 'adresse-changee@example.test'])->assertRedirect('/profil');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($before + 1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'adresse-changee@example.test']);
    }

    public function test_no_account_is_created_when_registration_is_closed(): void
    {
        config(['campus.identity.registration_enabled' => false]);
        $before = User::count();
        $this->depart();
        $this->retour()->assertRedirect('/connexion')->assertSessionHasErrors('login');
        $this->get('/connexion/google/creation')->assertRedirect('/connexion');
        $this->post('/connexion/google/creation', ['name' => 'Personne fictive', 'adult_declaration' => true])->assertNotFound();
        $this->assertSame($before, User::count());
        $this->assertGuest();
    }

    public function test_an_existing_verified_account_is_linked_by_its_address_and_keeps_its_password(): void
    {
        $user = $this->account('independant');
        $this->depart();
        $this->retour(['email' => 'Independant@Example.test'])->assertRedirect('/profil');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'subject' => 'google-sujet-fictif-1']);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->refresh()->password));
        $this->get('/profil')->assertInertia(fn (Assert $page) => $page->where('google.linked', true)->where('google.linkedEmail', 'independant@example.test'));

        // Un autre compte Google portant la même adresse ne remplace pas le premier.
        $this->post('/deconnexion');
        $this->freshDevice();
        $this->depart();
        $this->retour(['email' => 'independant@example.test', 'sub' => 'google-sujet-fictif-2'])->assertRedirect('/connexion')->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->assertSame(1, SocialIdentity::where('user_id', $user->id)->count());
    }

    public function test_an_unverified_account_taken_over_by_its_real_owner_loses_the_password_set_before_verification(): void
    {
        // Quelqu'un a créé un compte avec l'adresse d'une autre personne, sans jamais pouvoir la vérifier.
        $user = $this->account('independant', verified: false);
        $this->depart();
        $this->retour(['email' => 'independant@example.test'])->assertRedirect('/profil')->assertSessionHas('status');
        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(Hash::check(self::PASSWORD, $user->password));
        $this->post('/deconnexion');
        $this->freshDevice()->post('/connexion', ['login' => 'independant@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_anything_that_does_not_match_is_refused_without_signing_in_or_linking(): void
    {
        $user = $this->account('independant');
        $refused = function (TestResponse $response) use ($user): void {
            $response->assertRedirect('/connexion')->assertSessionHasErrors('login');
            $this->assertGuest();
            $this->assertSame(0, SocialIdentity::where('user_id', $user->id)->count());
        };
        $claims = ['email' => 'independant@example.test'];

        $this->depart();
        $refused($this->retour($claims, state: 'etat-invente'));
        // La demande ne sert qu'une fois : après un refus, le bon état ne vaut plus rien.
        $refused($this->retour($claims));
        // Sans départ préalable sur cet appareil.
        $this->freshDevice();
        $refused($this->get('/connexion/google/reponse?state=abc&code=def'));

        foreach ([
            ['nonce' => 'nonce-d-une-autre-demande'], ['aud' => 'autre-client.example.test'], ['iss' => 'https://faux-emetteur.example.test'],
            ['exp' => now()->unix() - 10], ['email_verified' => false], ['sub' => ''], ['email' => 'pas-une-adresse'],
        ] as $defect) {
            $this->freshDevice();
            $this->depart();
            $refused($this->retour([...$claims, ...$defect]));
        }
        // Google répond par une erreur, ou la personne a refusé chez Google.
        $this->freshDevice();
        $this->depart();
        $refused($this->retour($claims, status: 400));
        $this->depart();
        $refused($this->get('/connexion/google/reponse?error=access_denied&state='.$this->flow['state']));

        // Compte suspendu : même réponse, qu'il soit relié ou seulement reconnu par son adresse.
        \DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        $this->freshDevice();
        $this->depart();
        $refused($this->retour($claims));
    }

    public function test_r10_the_second_factor_is_still_required_after_a_google_sign_in(): void
    {
        $admin = $this->account('admin-a');
        $secret = Totp::generateSecret();
        SecondFactor::create(['user_id' => $admin->id, 'secret' => $secret, 'confirmed_at' => now()]);
        $url = '/etablissement/'.$admin->memberships()->firstOrFail()->id.'/comptes';

        $this->depart();
        $this->retour(['email' => 'admin-a@example.test']);
        $this->assertAuthenticatedAs($admin);
        $this->get($url)->assertRedirect('/second-facteur');
        $this->get('/profil')->assertRedirect('/second-facteur');
        $this->post('/second-facteur', ['code' => Totp::code($secret, Totp::step())])->assertSessionHasNoErrors();
        $this->get($url)->assertOk();

        // Sans second facteur sur le compte, Google seul n'ouvre aucun rôle privilégié.
        $other = $this->account('admin-b');
        $this->post('/deconnexion');
        $this->freshDevice();
        $this->depart();
        $this->retour(['email' => 'admin-b@example.test', 'sub' => 'google-sujet-fictif-b']);
        $this->assertAuthenticatedAs($other);
        $this->get('/etablissement/'.$other->memberships()->firstOrFail()->id.'/comptes')->assertForbidden();
    }

    public function test_linking_from_the_profile_needs_the_password_and_refuses_a_google_account_used_elsewhere(): void
    {
        $user = $this->account('independant');
        $other = $this->account('apprenant-double');
        SocialIdentity::create(['user_id' => $other->id, 'provider' => 'google', 'subject' => 'google-sujet-pris', 'email' => 'apprenant-double@example.test']);

        $this->freshDevice()->post('/connexion', ['login' => 'independant@example.test', 'password' => self::PASSWORD]);
        $this->get('/profil/google/rattachement')->assertRedirect('/confirmation-mot-de-passe');
        $this->post('/confirmation-mot-de-passe', ['password' => self::PASSWORD]);

        $this->depart('/profil/google/rattachement');
        $this->retour(['sub' => 'google-sujet-pris', 'email' => 'apprenant-double@example.test'])->assertRedirect('/profil')->assertSessionHas('problem');
        $this->assertSame(0, SocialIdentity::where('user_id', $user->id)->count());
        $this->assertAuthenticatedAs($user);

        // L'adresse du compte Google peut différer de celle du compte Campus : le rattachement est un choix de la personne connectée.
        $this->depart('/profil/google/rattachement');
        $this->retour(['sub' => 'google-sujet-libre', 'email' => 'autre-adresse@example.test'])->assertRedirect('/profil')->assertSessionHas('status');
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'subject' => 'google-sujet-libre']);
        $this->assertSame('independant@example.test', $user->refresh()->email);

        // Un second compte Google ne s'ajoute pas au même compte Campus.
        $this->depart('/profil/google/rattachement');
        $this->retour(['sub' => 'google-sujet-encore-un'])->assertSessionHas('problem');
        $this->assertSame(1, SocialIdentity::where('user_id', $user->id)->count());
    }

    public function test_a_recent_google_authentication_confirms_a_sensitive_action_instead_of_the_password(): void
    {
        $user = $this->account('independant');
        $this->freshDevice()->post('/connexion', ['login' => 'independant@example.test', 'password' => self::PASSWORD]);
        // Compte non relié : la confirmation par Google n'existe pas.
        $this->get('/confirmation-google')->assertNotFound();
        $this->get('/confirmation-mot-de-passe')->assertInertia(fn (Assert $page) => $page->where('googleAvailable', false));
        SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'subject' => 'google-sujet-fictif-1', 'email' => 'independant@example.test']);
        $this->get('/confirmation-mot-de-passe')->assertInertia(fn (Assert $page) => $page->where('googleAvailable', true));
        $sensitive = '/confidentialite/suppression';
        $this->get($sensitive)->assertRedirect('/confirmation-mot-de-passe');

        // Google doit redemander l'authentification ; une authentification ancienne, ou un autre compte Google, ne confirme rien.
        $query = $this->depart('/confirmation-google');
        $this->assertSame(['login', '0'], [$query['prompt'], $query['max_age']]);
        $this->retour(['auth_time' => now()->unix() - 3600])->assertRedirect('/confirmation-mot-de-passe')->assertSessionHasErrors('password');
        $this->get($sensitive)->assertRedirect('/confirmation-mot-de-passe');
        $this->depart('/confirmation-google');
        $this->retour(['sub' => 'google-sujet-d-un-autre'])->assertSessionHasErrors('password');
        $this->get($sensitive)->assertRedirect('/confirmation-mot-de-passe');

        $this->depart('/confirmation-google');
        $this->retour()->assertRedirect($sensitive);
        $this->get($sensitive)->assertOk();
    }
}
