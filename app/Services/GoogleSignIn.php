<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connexion par compte Google (OpenID Connect, flux par code d'autorisation avec PKCE), sans dépendance. Campus n'appelle que deux
 * adresses fixes de Google, jamais une adresse fournie par une personne, et ne conserve aucun jeton : seul l'identifiant stable du
 * compte Google sert ensuite à reconnaître la personne.
 */
class GoogleSignIn
{
    public const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const INTENTS = ['login', 'link', 'confirm'];

    private const SESSION_KEY = 'campus.google.flow';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** Durée de validité d'une demande en cours, en secondes. */
    private const FLOW_TTL = 600;

    /** Ancienneté maximale de l'authentification Google pour valoir confirmation d'une action sensible, en secondes. */
    private const FRESH_AUTH = 300;

    public function enabled(): bool
    {
        return $this->clientId() !== '' && (string) config('services.google.client_secret') !== '';
    }

    /**
     * Prépare la demande (état, nonce, vérificateur PKCE gardés en session) et rend l'adresse Google vers laquelle envoyer la personne.
     */
    public function authorizationUrl(Request $request, string $intent): string
    {
        $flow = ['intent' => $intent, 'state' => Str::random(40), 'nonce' => Str::random(40), 'verifier' => Str::random(64), 'at' => now()->unix()];
        $request->session()->put(self::SESSION_KEY, $flow);
        $query = [
            'client_id' => $this->clientId(), 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $flow['state'], 'nonce' => $flow['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ];
        if ($intent === 'confirm') {
            // Confirmation d'une action sensible : Google doit redemander l'authentification maintenant.
            $query += ['prompt' => 'login', 'max_age' => 0];
        } else {
            $query += ['prompt' => 'select_account'];
        }

        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Termine la demande : contrôle l'état, échange le code auprès de Google et contrôle le jeton d'identité reçu.
     * Rend l'intention et l'identité, ou null si quoi que ce soit ne correspond pas. La demande ne sert qu'une fois.
     *
     * @return array{intent: string, subject: string, email: string, name: string, fresh: bool}|null
     */
    public function complete(Request $request): ?array
    {
        $flow = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($flow) || ! is_string($state) || ! is_string($code) || $code === '' || strlen($code) > 2048
            || ! hash_equals((string) ($flow['state'] ?? ''), $state) || now()->unix() - (int) ($flow['at'] ?? 0) > self::FLOW_TTL
            || ! in_array($flow['intent'] ?? null, self::INTENTS, true)) {
            return null;
        }
        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->connectTimeout(5)->withoutRedirecting()->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $this->clientId(),
                'client_secret' => (string) config('services.google.client_secret'), 'redirect_uri' => $this->redirectUri(), 'code_verifier' => $flow['verifier'],
            ]);
        } catch (Throwable) {
            return null;
        }
        $token = $response->successful() ? $response->json('id_token') : null;
        $claims = is_string($token) ? $this->claims($token) : null;
        // Le jeton vient d'être reçu de Google lui-même, sur une liaison chiffrée et authentifiée par le secret du client : son émetteur,
        // son destinataire, sa validité et le nonce de cette demande sont contrôlés ici.
        if ($claims === null || ! in_array($claims['iss'] ?? null, self::ISSUERS, true) || ($claims['aud'] ?? null) !== $this->clientId()
            || (int) ($claims['exp'] ?? 0) <= now()->unix() || ! hash_equals((string) $flow['nonce'], (string) ($claims['nonce'] ?? ''))
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 191
            || ! in_array($claims['email_verified'] ?? false, [true, 'true'], true)
            || ! is_string($claims['email'] ?? null) || filter_var($claims['email'], FILTER_VALIDATE_EMAIL) === false || strlen($claims['email']) > 255) {
            return null;
        }

        return [
            'intent' => $flow['intent'], 'subject' => $claims['sub'], 'email' => Str::lower($claims['email']),
            'name' => Str::limit(trim(is_string($claims['name'] ?? null) ? $claims['name'] : ''), 120, ''),
            'fresh' => isset($claims['auth_time']) && now()->unix() - (int) $claims['auth_time'] <= self::FRESH_AUTH,
        ];
    }

    public function redirectUri(): string
    {
        return route('google.callback');
    }

    private function clientId(): string
    {
        return (string) config('services.google.client_id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);

        return is_array($claims) ? $claims : null;
    }
}
