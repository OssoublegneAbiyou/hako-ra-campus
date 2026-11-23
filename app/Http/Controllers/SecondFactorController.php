<?php

namespace App\Http\Controllers;

use App\Services\SecondFactors;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglage du second facteur par la personne elle-même. Toutes les écritures exigent le mot de passe saisi récemment.
 */
class SecondFactorController extends Controller
{
    public function __construct(private SecondFactors $factors) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $factor = $user->secondFactor;
        $pending = $factor !== null && ! $factor->isConfirmed();

        return Inertia::render('SecondFactor', [
            'enabled' => $factor?->isConfirmed() === true,
            'enabledAt' => $factor?->confirmed_at?->utc()->toIso8601String(),
            'required' => $user->hasPrivilegedRole(),
            'remainingRecoveryCodes' => $this->factors->remainingRecoveryCodes($user),
            // Le secret n'est montré que pendant l'activation, avant sa confirmation, à la personne qui vient de saisir son mot de passe.
            'setup' => $pending ? ['secret' => trim(chunk_split($factor->secret, 4, ' ')),
                'uri' => Totp::uri($factor->secret, $user->email ?? (string) $user->login_identifier, (string) config('app.name'))] : null,
            // Codes de secours : transmis une seule fois, juste après leur création.
            'recoveryCodes' => $request->session()->get('second_factor_recovery_codes'),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $this->factors->begin($request->user());

        return redirect()->route('second-factor.show');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->factors->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré. Vérifiez l’heure de votre téléphone, puis saisissez le code affiché maintenant.']);
        }
        $request->session()->regenerate();
        $this->factors->markVerified($request, $request->user());

        return redirect()->route('second-factor.show')->with('second_factor_recovery_codes', $codes)->with('status', 'Second facteur activé.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        return redirect()->route('second-factor.show')->with('second_factor_recovery_codes', $this->factors->regenerateRecoveryCodes($request->user()))
            ->with('status', 'Nouveaux codes de secours créés. Les anciens ne fonctionnent plus.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->secondFactor?->isConfirmed() === true) {
            $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
            if ($this->factors->verify($user, $data['code']) === null) {
                throw ValidationException::withMessages(['code' => 'Code incorrect, expiré ou déjà utilisé.']);
            }
        }
        $this->factors->remove($user, $user);
        $request->session()->forget(SecondFactors::SESSION_KEY);

        return redirect()->route('second-factor.show')->with('status', 'Second facteur retiré.');
    }
}
