<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountActivationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Activate');
    }

    /**
     * Le secret temporaire n'ouvre jamais de session : il ne sert qu'à fixer le mot de passe personnel.
     */
    public function store(Request $request, AccountProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:40'],
            'secret' => ['required', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $throttleKey = 'activation|'.Str::upper(trim($data['identifier'])).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('campus.identity.activation_max_attempts'))) {
            throw ValidationException::withMessages([
                'identifier' => 'Trop de tentatives. Réessayez dans '.ceil(RateLimiter::availableIn($throttleKey) / 60).' minutes.',
            ]);
        }

        $user = $provisioner->activate($data['identifier'], $data['secret'], $data['password']);
        if (! $user) {
            RateLimiter::hit($throttleKey, (int) config('campus.identity.activation_decay_seconds'));
            throw ValidationException::withMessages(['identifier' => 'Identifiant ou secret temporaire invalide ou expiré.']);
        }

        RateLimiter::clear($throttleKey);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile')->with('status', 'Compte activé. Votre mot de passe personnel remplace le secret temporaire.');
    }
}
