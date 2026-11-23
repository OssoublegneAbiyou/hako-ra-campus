<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SecondFactors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecondFactorVerified
{
    /** Routes accessibles avant la présentation du code : le défi lui-même et la déconnexion. */
    private const OPEN_ROUTES = ['second-factor.challenge', 'second-factor.verify', 'logout'];

    public function __construct(private SecondFactors $factors) {}

    /**
     * Un compte qui a activé un second facteur n'accède à rien, après son mot de passe, tant que le code n'a pas été présenté dans
     * cette session. Vaut aussi pour une session rouverte par « se souvenir de moi ».
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->secondFactor?->isConfirmed() !== true || $this->factors->sessionVerified($request, $user)
            || in_array($request->route()?->getName(), self::OPEN_ROUTES, true)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Second facteur requis : présentez votre code avant de continuer.');
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('second-factor.challenge');
    }
}
