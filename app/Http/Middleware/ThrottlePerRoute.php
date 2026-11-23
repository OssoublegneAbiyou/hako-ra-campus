<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Limite de débit propre à chaque route. Le limiteur standard n'identifie un compte connecté que par son numéro : toutes les routes
 * limitées partageaient donc un seul compteur par compte, et une route à faible plafond se retrouvait bloquée par d'autres actions.
 * Ici le compteur porte aussi sur la route : chaque plafond écrit dans les routes vaut pour cette route seulement.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $name = $route?->getName() ?? implode('|', $route?->methods() ?? []).' '.($route?->uri() ?? $request->path());

        return sha1(($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip()).'|'.$name);
    }
}
