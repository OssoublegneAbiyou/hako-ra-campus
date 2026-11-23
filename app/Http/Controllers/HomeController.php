<?php

namespace App\Http\Controllers;

use App\Services\ActiveContext;
use App\Services\HomeDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accueil : pour un visiteur, une présentation sans donnée ; pour un compte connecté, vérifié et actif, ses prochaines actions.
 */
class HomeController extends Controller
{
    public function __invoke(Request $r, HomeDashboard $dashboard, ActiveContext $contexts): Response
    {
        $user = $r->user();
        $member = $user !== null && $user->status === 'active' && $user->hasVerifiedEmail();

        return Inertia::render('Home', [
            'locale' => app()->getLocale(),
            'currentTime' => now()->utc()->toIso8601String(),
            'registrationEnabled' => (bool) config('campus.identity.registration_enabled'),
            'dashboard' => $member ? $dashboard->build($user, $contexts->current($r)) : null,
        ]);
    }
}
