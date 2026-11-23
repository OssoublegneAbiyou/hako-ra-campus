<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventPrivateCaching
{
    /**
     * Toute réponse servie à un compte connecté est marquée « ne pas conserver » : ni le navigateur, ni un intermédiaire,
     * ni un service worker ne doit garder une page, un export ou un fichier d'un compte pour le resservir à un autre.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
