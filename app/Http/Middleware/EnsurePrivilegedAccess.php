<?php

namespace App\Http\Middleware;

use App\Support\PrivilegedAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilegedAccess
{
    /**
     * Refuse les actions privilégiées tant que le compte n'a pas de second facteur actif, présenté dans cette session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            PrivilegedAccess::available(),
            403,
            'Action indisponible : activez le second facteur de votre compte depuis votre profil pour utiliser les rôles d’enseignement, d’administration et d’édition.',
        );

        return $next($request);
    }
}
