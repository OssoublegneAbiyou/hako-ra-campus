<?php

namespace App\Http\Controllers;

use App\Services\OfflineSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Installation de Campus comme raccourci web et lecture hors connexion. Le serveur ne décide jamais de stocker quoi que ce soit
 * sur l'appareil : il ne fait que fournir, au compte connecté, l'instantané que l'appareil choisit ou non de conserver.
 */
class OfflineController extends Controller
{
    public function show(Request $r): Response
    {
        return Inertia::render('Offline', [
            'ttlDays' => max(1, (int) config('campus.offline.ttl_days')),
            'windowDays' => OfflineSnapshot::WINDOW_DAYS,
        ]);
    }

    /**
     * Instantané du compte connecté, jamais mis en cache par un intermédiaire ni par le service worker.
     */
    public function snapshot(Request $r, OfflineSnapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->build($r->user()), 200, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
