<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SecondFactors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seconde étape de la connexion des comptes qui ont activé un second facteur : code de l'application, ou code de secours.
 */
class SecondFactorChallengeController extends Controller
{
    public function __construct(private SecondFactors $factors) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->secondFactor?->isConfirmed() !== true || $this->factors->sessionVerified($request, $user)) {
            return redirect()->route('profile');
        }

        return Inertia::render('auth/SecondFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        $throttleKey = 'second-factor|'.$user->getKey();
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('campus.identity.login_max_attempts'))) {
            throw ValidationException::withMessages(['code' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.']);
        }
        $method = $this->factors->verify($user, $data['code']);
        if ($method === null) {
            RateLimiter::hit($throttleKey, (int) config('campus.identity.login_decay_seconds'));
            throw ValidationException::withMessages(['code' => 'Code incorrect, expiré ou déjà utilisé.']);
        }
        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $this->factors->markVerified($request, $user);
        $redirect = redirect()->intended(route('profile', absolute: false));

        return $method === 'recovery'
            ? $redirect->with('status', 'Connexion par code de secours : ce code ne servira plus. Il vous en reste '.$this->factors->remainingRecoveryCodes($user->refresh()).'.')
            : $redirect;
    }
}
