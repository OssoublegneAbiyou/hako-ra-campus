<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public const GENERIC_STATUS = 'Si un compte correspond à cette adresse, un lien de réinitialisation lui est adressé. Il expire après 60 minutes.';

    public function request(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    /**
     * Réponse identique que le compte existe ou non, y compris quand la fréquence est dépassée.
     */
    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $throttleKey = 'password-request|'.$request->ip();
        if (! RateLimiter::tooManyAttempts($throttleKey, (int) config('campus.identity.recovery_max_requests'))) {
            RateLimiter::hit($throttleKey, 60);
            Password::sendResetLink(['email' => Str::lower($data['email'])]);
        }

        return back()->with('status', self::GENERIC_STATUS);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request, SessionRegistry $sessions): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'password' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) use ($sessions): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'password_changed_at' => now(),
                ])->save();
                $sessions->revokeAll($user);
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.']);
        }

        return redirect()->route('login')->with('status', 'Mot de passe modifié. Vos autres sessions ont été fermées ; connectez-vous.');
    }
}
