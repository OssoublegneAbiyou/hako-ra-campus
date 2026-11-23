<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Un compte suspendu perd sa session à la requête suivante, sans attendre son expiration.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Votre session a été fermée. Reconnectez-vous ou contactez l’assistance.');
        }

        // Dernière activité, à la journée près : utile à la seule revue d'inactivité.
        if ($request->user() && ($request->user()->last_seen_at === null || $request->user()->last_seen_at->lt(now()->subDay()))) {
            $request->user()->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
