<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login', [
            'registrationEnabled' => (bool) config('campus.identity.registration_enabled'),
            'googleEnabled' => app(GoogleSignIn::class)->enabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['boolean'],
        ]);
        $login = trim($data['login']);
        $throttleKey = 'login|'.Str::transliterate(Str::lower($login)).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('campus.identity.login_max_attempts'))) {
            throw ValidationException::withMessages([
                'login' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }

        $credentials = str_contains($login, '@')
            ? ['email' => Str::lower($login)]
            : ['login_identifier' => Str::upper($login)];
        if (! Auth::attempt([...$credentials, 'password' => $data['password'], 'status' => 'active'], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($throttleKey, (int) config('campus.identity.login_decay_seconds'));
            throw ValidationException::withMessages(['login' => 'Identifiant ou mot de passe incorrect.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('profile', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Vous êtes déconnecté.');
    }
}
