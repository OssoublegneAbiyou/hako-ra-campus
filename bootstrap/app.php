<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePrivilegedAccess;
use App\Http\Middleware\EnsureSecondFactorVerified;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventPrivateCaching;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePerRoute;
use App\Support\JsonErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Un compte qui a activé un second facteur ne voit rien, après son mot de passe, avant d'avoir présenté son code.
        $middleware->web(append: [EnsureSecondFactorVerified::class, HandleInertiaRequests::class, PreventPrivateCaching::class]);
        // En-têtes de sécurité sur toutes les réponses, pages d'erreur comprises.
        $middleware->append(SecurityHeaders::class);
        // Hors local et tests, seules les requêtes adressées au nom du site (APP_URL) sont acceptées : un en-tête Host forgé ne peut pas
        // se retrouver dans un lien d'invitation ou de réinitialisation.
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST), '/').'$'], subdomains: false);
        $middleware->alias([
            // Chaque limite de débit vaut pour sa route : le compteur standard était commun à toutes les routes d'un compte.
            'throttle' => ThrottlePerRoute::class,
            'account.active' => EnsureAccountIsActive::class,
            'privileged' => EnsurePrivilegedAccess::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('profile'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->isMethod('GET') && $request->header('X-Inertia') && $request->is('etudes/*', 'planning/*', 'taches', 'taches/*', 'notes', 'notes/*', 'notifications', 'notifications/*', 'pieces-jointes/*', 'opportunites', 'opportunites/*', 'administration/catalogue', 'administration/catalogue/*') && in_array($response->getStatusCode(), [403, 404, 409, 419, 429], true)) {
                $message = match ($response->getStatusCode()) {
                    409 => 'La référence a changé. Rechargez les données avant de réessayer.',
                    419 => 'Votre session a expiré. Rechargez la page avant de réessayer.',
                    429 => 'Trop de demandes. Réessayez plus tard.',
                    default => 'La référence est indisponible ou cette action n’est pas autorisée.',
                };

                return back()->withErrors(['reference' => $message]);
            }
            $status = $response->getStatusCode();
            if (! $request->isMethod('GET') && $request->header('X-Inertia') && in_array($status, [403, 404, 409, 419, 429], true)) {
                // Autres écrans : l'action n'a pas abouti, la personne revient où elle était avec une explication et sa saisie.
                return back()->with('problem', match ($status) {
                    409 => 'Cet élément a changé entre-temps. Rechargez la page avant de réessayer.',
                    419 => 'Votre session a expiré. Rechargez la page, puis recommencez : rien n’a été enregistré.',
                    429 => 'Trop de demandes en peu de temps. Patientez une minute avant de réessayer.',
                    default => 'Cette action n’est pas autorisée, ou l’élément n’existe plus. Rien n’a été modifié.',
                });
            }
            if (! $request->expectsJson() && in_array($status, [403, 404, 419, 429, 500, 503], true) && ($status < 500 || ! config('app.debug'))) {
                // Page d'erreur de Campus, autonome : elle ne dépend d'aucune donnée de compte et propose toujours une reprise.
                return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
            }

            return JsonErrorResponse::render($response, $exception, $request);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
