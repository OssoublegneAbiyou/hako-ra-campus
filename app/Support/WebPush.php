<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Envoi d'un signal Web Push sans contenu : le navigateur affiche un message générique et renvoie vers le centre
 * de notifications. Seuls les services de push de la liste fermée sont appelés, jamais une adresse arbitraire.
 */
class WebPush
{
    /**
     * L'adresse d'abonnement désigne-t-elle un service de push reconnu, en HTTPS, sans identifiants ni port particulier ?
     */
    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443) || ! isset($parts['host'], $parts['path'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ((array) config('campus.notifications.push_hosts') as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed) && strlen($host) > strlen($allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Résultat : accepted (accepté par le service), gone (abonnement à révoquer), rejected (refus définitif),
     * retry (indisponibilité annoncée par le service) ou uncertain (aucune réponse : livraison possible, jamais renvoyée à l'aveugle).
     *
     * @return array{outcome: string, code: string}
     */
    public static function send(string $endpoint, string $topic, int $ttlSeconds): array
    {
        if (! self::allowed($endpoint)) {
            return ['outcome' => 'rejected', 'code' => 'endpoint_not_allowed'];
        }
        try {
            $response = Http::timeout((int) config('campus.notifications.push_timeout_seconds'))->connectTimeout(5)->withoutRedirecting()->withHeaders([
                'TTL' => (string) max(0, $ttlSeconds), 'Urgency' => 'normal',
                // Topic identique pour un même rappel : le service remplace un signal encore en attente au lieu d'en empiler un second.
                'Topic' => substr(Vapid::base64Url(hash('sha256', $topic, true)), 0, 32),
                'Authorization' => Vapid::authorization($endpoint, now()->getTimestamp()),
            ])->send('POST', $endpoint);
        } catch (ConnectionException) {
            return ['outcome' => 'uncertain', 'code' => 'push_no_response'];
        }
        $status = $response->status();

        return match (true) {
            $status >= 200 && $status < 300 => ['outcome' => 'accepted', 'code' => 'push_'.$status],
            in_array($status, [404, 410], true) => ['outcome' => 'gone', 'code' => 'push_'.$status],
            $status === 429 || $status >= 500 => ['outcome' => 'retry', 'code' => 'push_'.$status],
            default => ['outcome' => 'rejected', 'code' => 'push_'.$status],
        };
    }
}
