<?php

namespace App\Http\Controllers;

use App\Services\ActiveContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ContextController extends Controller
{
    /**
     * Écrans communs à tous les contextes : après un changement de contexte, la personne y reste au lieu d'être envoyée ailleurs.
     */
    private const STAY_ON = ['home', 'planning', 'tasks', 'grades', 'opportunities', 'academic.index', 'profile'];

    /**
     * Le choix envoyé est vérifié contre les appartenances effectives du compte ; il ne prouve rien par lui-même.
     */
    public function update(Request $request, ActiveContext $context): RedirectResponse
    {
        $data = $request->validate(['membership_id' => ['nullable', 'integer']]);
        $membership = $context->switchTo($request, $data['membership_id'] ?? null);

        return redirect()->route($this->origin($request))->with('status', 'Contexte actif : '.$context->label($membership).'.');
    }

    /**
     * Écran d'où vient la demande, s'il fait partie des écrans communs ; sinon le profil. Seul le nom de la route est repris :
     * ni l'adresse reçue, ni ses paramètres ne servent à la redirection.
     */
    private function origin(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        if ($referer === '' || parse_url($referer, PHP_URL_HOST) !== $request->getHost()) {
            return 'profile';
        }
        try {
            $name = Route::getRoutes()->match(Request::create((string) parse_url($referer, PHP_URL_PATH)))->getName();
        } catch (HttpExceptionInterface) {
            return 'profile';
        }

        return in_array($name, self::STAY_ON, true) ? $name : 'profile';
    }
}
