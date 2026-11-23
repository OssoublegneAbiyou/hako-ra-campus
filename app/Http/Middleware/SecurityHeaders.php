<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses du site : pas d'affichage dans un cadre tiers, pas de devinette du type de contenu,
 * pas de fuite de l'adresse d'origine, et une politique de contenu qui n'autorise que les scripts, styles, images et appels du site lui-même.
 * Une réponse qui fixe déjà sa propre politique (téléchargement d'une pièce jointe) la conserve.
 */
class SecurityHeaders
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        ."worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        // Pendant le développement avec rechargement à chaud, Vite injecte un script en ligne et un serveur local : la politique de contenu
        // ne s'applique alors pas. Elle s'applique dès que les fichiers sont construits, y compris en local.
        if (! is_file(public_path('hot'))) {
            $headers['Content-Security-Policy'] = self::POLICY;
        }
        // HTTPS imposé au navigateur seulement quand la requête est déjà sécurisée.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
