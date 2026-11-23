<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PersonalSpace;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\GoogleSignIn;
use App\Services\SessionRegistry;
use App\Support\MinorsPathway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Connexion, création de compte, rattachement et confirmation par compte Google. Le second facteur d'un compte Campus reste exigé
 * après une connexion par Google. Aucun rôle, établissement ou matricule ne s'obtient ici.
 */
class GoogleSignInController extends Controller
{
    private const PENDING_KEY = 'campus.google.pending';

    private const GENERIC_REFUSAL = 'La connexion par Google n’a pas abouti. Réessayez, ou connectez-vous avec votre mot de passe.';

    public function __construct(private GoogleSignIn $google) {}

    /**
     * Départ vers Google pour se connecter ou créer un compte.
     */
    public function redirect(Request $request): HttpResponse
    {
        abort_unless($this->google->enabled(), 404);

        return Inertia::location($this->google->authorizationUrl($request, 'login'));
    }

    /**
     * Départ vers Google pour relier son compte Campus (mot de passe saisi récemment) ou confirmer une action sensible.
     */
    public function start(Request $request, string $intent): HttpResponse
    {
        abort_unless($this->google->enabled() && in_array($intent, ['link', 'confirm'], true), 404);
        if ($intent === 'confirm') {
            abort_unless($request->user()->socialIdentities()->where('provider', 'google')->exists(), 404);
        }

        return Inertia::location($this->google->authorizationUrl($request, $intent));
    }

    public function callback(Request $request, SessionRegistry $sessions): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);
        $identity = $this->google->complete($request);
        $user = $request->user();
        if ($identity === null) {
            return $user ? redirect()->route('profile')->with('problem', 'La vérification par Google n’a pas abouti. Rien n’a été modifié.')
                : redirect()->route('login')->withErrors(['login' => self::GENERIC_REFUSAL]);
        }

        return match (true) {
            $identity['intent'] === 'link' && $user !== null => $this->link($user, $identity),
            $identity['intent'] === 'confirm' && $user !== null => $this->confirm($request, $user, $identity),
            $identity['intent'] === 'login' && $user === null => $this->signIn($request, $identity, $sessions),
            default => redirect()->route($user ? 'profile' : 'login'),
        };
    }

    /**
     * Écran de création : un compte Google inconnu de Campus ne crée un compte qu'après la même déclaration que l'inscription.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending) || ! config('campus.identity.registration_enabled')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/GoogleRegistration', ['email' => $pending['email'], 'name' => $pending['name'], 'minorsPathwayOpen' => MinorsPathway::isOpen()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('campus.identity.registration_enabled'), 404);
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! is_array($pending)) {
            return redirect()->route('login');
        }
        $minorsOpen = MinorsPathway::isOpen();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'adult_declaration' => $minorsOpen ? ['boolean'] : ['accepted'],
        ], ['adult_declaration.accepted' => 'L’inscription des mineurs n’est pas encore ouverte : cette déclaration est nécessaire.']);
        $request->session()->forget(self::PENDING_KEY);
        // Entre-temps, l'adresse ou le compte Google a pu être pris : aucune seconde identité n'est créée.
        if (User::query()->where('email', $pending['email'])->exists() || SocialIdentity::query()->where('provider', 'google')->where('subject', $pending['subject'])->exists()) {
            return redirect()->route('login')->withErrors(['login' => self::GENERIC_REFUSAL]);
        }
        $user = DB::transaction(function () use ($data, $pending, $request): User {
            // Compte sans mot de passe utilisable : une valeur aléatoire jamais communiquée. La personne peut en définir un par « mot de passe oublié ».
            $user = new User(['name' => $data['name'], 'email' => $pending['email'], 'password' => Str::random(64)]);
            $user->forceFill(['adult_declared_at' => $request->boolean('adult_declaration') ? now() : null, 'email_verified_at' => now()])->save();
            PersonalSpace::create(['owner_id' => $user->id]);
            SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'subject' => $pending['subject'], 'email' => $pending['email']]);
            AuditLog::record($user, 'google.account_created', $user);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile')->with('status', 'Compte créé avec Google. Votre adresse est déjà vérifiée.');
    }

    /**
     * @param  array{intent: string, subject: string, email: string, name: string, fresh: bool}  $identity
     */
    private function signIn(Request $request, array $identity, SessionRegistry $sessions): RedirectResponse
    {
        $refusal = redirect()->route('login')->withErrors(['login' => self::GENERIC_REFUSAL]);
        $linked = SocialIdentity::query()->where('provider', 'google')->where('subject', $identity['subject'])->first();
        $user = $linked?->user;
        $notice = null;
        if ($linked === null) {
            $user = User::query()->where('email', $identity['email'])->first();
            if ($user === null) {
                if (! config('campus.identity.registration_enabled')) {
                    return redirect()->route('login')->withErrors(['login' => 'Aucun compte Campus n’est relié à ce compte Google, et la création de compte n’est pas ouverte.']);
                }
                $request->session()->put(self::PENDING_KEY, ['subject' => $identity['subject'], 'email' => $identity['email'], 'name' => $identity['name']]);

                return redirect()->route('google.create');
            }
            if ($user->status !== 'active' || $user->socialIdentities()->where('provider', 'google')->exists()) {
                return $refusal;
            }
            DB::transaction(function () use ($user, $identity, $sessions, &$notice): void {
                if ($user->email_verified_at === null) {
                    // Compte créé avec cette adresse mais jamais vérifié : rien ne prouve que son mot de passe a été choisi par la
                    // personne qui possède l'adresse. Google le prouve maintenant : l'ancien mot de passe et les sessions sont annulés.
                    $user->forceFill(['email_verified_at' => now(), 'password' => Str::random(64), 'password_changed_at' => null, 'remember_token' => null])->save();
                    if ($sessions->supported()) {
                        $sessions->revokeAll($user);
                    }
                    $notice = 'Votre adresse est maintenant vérifiée par Google. Le mot de passe choisi avant cette vérification a été annulé : utilisez Google, ou « Mot de passe oublié » pour en définir un nouveau.';
                }
                SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'subject' => $identity['subject'], 'email' => $identity['email']]);
                AuditLog::record($user, 'google.linked', $user, null, null, ['by' => 'matching_verified_email']);
            });
        }
        if ($user === null || $user->status !== 'active') {
            return $refusal;
        }
        Auth::login($user);
        $request->session()->regenerate();
        $redirect = redirect()->intended(route('profile', absolute: false));

        return $notice === null ? $redirect : $redirect->with('status', $notice);
    }

    /**
     * @param  array{intent: string, subject: string, email: string, name: string, fresh: bool}  $identity
     */
    private function link(User $user, array $identity): RedirectResponse
    {
        $taken = SocialIdentity::query()->where('provider', 'google')->where('subject', $identity['subject'])->first();
        if ($taken !== null && $taken->user_id !== $user->id) {
            return redirect()->route('profile')->with('problem', 'Ce compte Google est déjà relié à un autre compte Campus. Rien n’a été modifié.');
        }
        if ($user->socialIdentities()->where('provider', 'google')->where('subject', '!=', $identity['subject'])->exists()) {
            return redirect()->route('profile')->with('problem', 'Un autre compte Google est déjà relié à ce compte Campus. Rien n’a été modifié.');
        }
        if ($taken === null) {
            SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'subject' => $identity['subject'], 'email' => $identity['email']]);
            AuditLog::record($user, 'google.linked', $user, null, null, ['by' => 'profile']);
        }

        return redirect()->route('profile')->with('status', 'Compte Google relié. Vous pouvez maintenant vous connecter avec Google.');
    }

    /**
     * Confirmation d'une action sensible par une authentification Google récente, à la place du mot de passe.
     *
     * @param  array{intent: string, subject: string, email: string, name: string, fresh: bool}  $identity
     */
    private function confirm(Request $request, User $user, array $identity): RedirectResponse
    {
        if (! $identity['fresh'] || ! $user->socialIdentities()->where('provider', 'google')->where('subject', $identity['subject'])->exists()) {
            return redirect()->route('password.confirm')->withErrors(['password' => 'La confirmation par Google n’a pas abouti : ce n’est pas le compte Google relié, ou l’authentification n’est pas récente.']);
        }
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile', absolute: false));
    }
}
