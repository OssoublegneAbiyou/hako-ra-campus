<?php

namespace App\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Identification du serveur auprès des services Web Push (VAPID, RFC 8292), avec la seule extension OpenSSL.
 * La clé privée vient de la configuration (PEM encodé en base64) ; elle n'est jamais journalisée ni renvoyée au navigateur.
 */
class Vapid
{
    public static function configured(): bool
    {
        return is_string(config('campus.notifications.push_private_key')) && config('campus.notifications.push_private_key') !== ''
            && is_string(config('campus.notifications.push_subject')) && config('campus.notifications.push_subject') !== '';
    }

    /**
     * Clé publique au format attendu par le navigateur : point non compressé P-256, base64url.
     */
    public static function publicKey(): string
    {
        $details = openssl_pkey_get_details(self::key());
        if (! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('La clé Web Push n’est pas une clé P-256.');
        }

        return self::base64Url("\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
    }

    /**
     * En-tête Authorization pour un service de push donné (audience = origine de l'adresse d'abonnement).
     */
    public static function authorization(string $endpoint, int $now): string
    {
        $parts = parse_url($endpoint);
        $header = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode(['aud' => $parts['scheme'].'://'.$parts['host'], 'exp' => $now + 12 * 3600,
            'sub' => config('campus.notifications.push_subject')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! openssl_sign($header.'.'.$claims, $der, self::key(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature Web Push impossible.');
        }

        return 'vapid t='.$header.'.'.$claims.'.'.self::base64Url(self::rawSignature($der)).', k='.self::publicKey();
    }

    /**
     * Nouvelle clé privée P-256, PEM encodé en base64, à placer dans CAMPUS_PUSH_PRIVATE_KEY.
     */
    public static function generate(): string
    {
        // Sous Windows, OpenSSL ne trouve pas toujours son fichier de configuration : celui livré avec PHP est essayé ensuite.
        foreach ([[], ['config' => dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf']] as $options) {
            $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, ...$options]);
            if ($key !== false && @openssl_pkey_export($key, $pem, null, $options === [] ? null : $options)) {
                return base64_encode($pem);
            }
        }
        throw new RuntimeException('Génération de clé impossible : OpenSSL n’est pas correctement configuré.');
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private((string) base64_decode((string) config('campus.notifications.push_private_key'), true));
        if ($key === false) {
            throw new RuntimeException('La clé Web Push configurée est illisible.');
        }

        return $key;
    }

    /**
     * Signature ECDSA DER (deux entiers) convertie en r || s sur 64 octets, format exigé par JWT ES256.
     */
    private static function rawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
        $integers = [];
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integers[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $integers[0].$integers[1];
    }
}
