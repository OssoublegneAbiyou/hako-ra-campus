<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    public function show(Request $request, GoogleSignIn $google): Response
    {
        // Un compte relié à Google peut confirmer par une authentification Google récente : utile aux comptes créés sans mot de passe.
        return Inertia::render('auth/ConfirmPassword', [
            'googleAvailable' => $google->enabled() && $request->user()->socialIdentities()->where('provider', 'google')->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $throttleKey = 'confirm-password|'.$request->user()->getKey();
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('campus.identity.login_max_attempts'))) {
            throw ValidationException::withMessages([
                'password' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }
        if (! Hash::check($data['password'], $request->user()->password)) {
            RateLimiter::hit($throttleKey, (int) config('campus.identity.login_decay_seconds'));
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile', absolute: false));
    }
}
