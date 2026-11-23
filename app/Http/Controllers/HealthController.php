<?php

namespace App\Http\Controllers;

use App\Services\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Point de supervision pour une sonde externe. Fermé tant qu'aucun jeton n'est configuré ; le jeton se présente dans un en-tête, jamais dans l'adresse.
 * Une adresse par composant permet une alerte distincte pour chacun : `/sante/worker`, `/sante/backup`…
 * La réponse ne contient que des états et des mesures techniques.
 */
class HealthController extends Controller
{
    public function __invoke(Request $r, HealthChecks $health, ?string $check = null): JsonResponse
    {
        $token = config('campus.monitoring.token');
        // Sans jeton configuré, ou avec un mauvais jeton : introuvable, sans indice.
        abort_unless(is_string($token) && strlen($token) >= 32 && hash_equals($token, (string) $r->header('X-Monitoring-Token')), 404);
        abort_if($check !== null && ! in_array($check, HealthChecks::CHECKS, true), 404);
        $report = $health->run($check === null ? null : [$check]);

        return response()->json($report, $report['healthy'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
