<?php

namespace App\Support;

/**
 * Codes temporaires à six chiffres (RFC 6238, HMAC-SHA1, pas de 30 secondes), compatibles avec Google Authenticator et les applications
 * équivalentes. Écrit sans dépendance : le calcul tient en quelques lignes et se vérifie contre les vecteurs de la RFC.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Secret aléatoire de 160 bits, en base32 (32 caractères), tel que l'attendent les applications d'authentification.
     */
    public static function generateSecret(): string
    {
        return self::encode(random_bytes(20));
    }

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[\s=-]/', '', $secret) ?? '')) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->unix(), self::PERIOD);
    }

    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Pas de temps correspondant au code saisi, ou null. Tolérance d'un pas avant et après (horloge du téléphone décalée) ;
     * un pas déjà utilisé, ou plus ancien, est refusé.
     */
    public static function match(string $secret, string $input, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $input) !== 1 || self::decode($secret) === '') {
            return null;
        }
        $current = self::step($timestamp);
        $found = null;
        // Les trois pas sont toujours calculés : la durée ne dépend pas de celui qui correspond.
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if (hash_equals(self::code($secret, $step), $input) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $found ??= $step;
            }
        }

        return $found;
    }

    /**
     * Adresse de configuration lue par les applications d'authentification.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }
}
