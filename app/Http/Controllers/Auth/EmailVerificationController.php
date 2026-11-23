<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('profile');
        }

        return Inertia::render('auth/VerifyEmail', [
            'email' => $request->user()->email,
            'mailConfigured' => ! in_array(config('mail.default'), ['array', 'log'], true),
            'expiresInMinutes' => (int) config('auth.verification.expire'),
        ]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('profile')->with('status', 'Adresse électronique vérifiée.');
    }

    /**
     * Le message ne prétend pas qu'un courriel a été reçu : seul l'envoi a été demandé.
     */
    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('profile');
        }
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Un nouveau lien de vérification a été demandé.');
    }
}
