<?php

namespace App\Support;

use RuntimeException;

/**
 * Chiffrement des sauvegardes par blocs (libsodium, flux authentifié XChaCha20-Poly1305). La clé ne vit jamais dans la sauvegarde :
 * elle est fournie par la configuration et doit être conservée à part. Un fichier modifié, tronqué ou chiffré avec une autre clé est refusé.
 */
class BackupCipher
{
    private const MAGIC = 'CAMPUSBK1';

    private const CHUNK = 65536;

    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Clé de sauvegarde absente ou de longueur incorrecte.');
        }
    }

    /**
     * Clé lue dans la configuration (32 octets en base64), ou null si elle n'est pas renseignée.
     */
    public static function configuredKey(): ?string
    {
        $encoded = config('campus.backup.key');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ? $key : null;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /**
     * Chiffre un fichier vers un autre. Renvoie l'empreinte du contenu en clair et celle du fichier chiffré.
     *
     * @return array{plain_sha256: string, cipher_sha256: string, plain_bytes: int, cipher_bytes: int}
     */
    public function encrypt(string $source, string $destination): array
    {
        $in = fopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Fichier de sauvegarde illisible ou destination non inscriptible.');
        }
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
        $plain = hash_init('sha256');
        $cipher = hash_init('sha256');
        $write = function (string $bytes) use ($out, $cipher): void {
            fwrite($out, $bytes);
            hash_update($cipher, $bytes);
        };
        $write(self::MAGIC.$header);
        $size = filesize($source);
        $read = 0;
        do {
            $chunk = (string) fread($in, self::CHUNK);
            $read += strlen($chunk);
            hash_update($plain, $chunk);
            $last = $read >= $size;
            $write(sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
        } while (! $last);
        fclose($in);
        fclose($out);

        return ['plain_sha256' => hash_final($plain), 'cipher_sha256' => hash_final($cipher), 'plain_bytes' => (int) $size, 'cipher_bytes' => (int) filesize($destination)];
    }

    /**
     * Déchiffre un fichier vers un autre et renvoie l'empreinte du contenu en clair. Échoue si le fichier est altéré, tronqué ou d'une autre clé.
     */
    public function decrypt(string $source, string $destination): string
    {
        $in = fopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Fichier chiffré illisible ou destination non inscriptible.');
        }
        try {
            $header = (string) fread($in, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (! str_starts_with($header, self::MAGIC) || strlen($header) !== strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Ce fichier n’est pas une sauvegarde chiffrée de Campus.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, strlen(self::MAGIC)), $this->key);
            $plain = hash_init('sha256');
            $finished = false;
            while (! feof($in)) {
                $chunk = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($chunk === '') {
                    break;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
                if ($result === false || $finished) {
                    throw new RuntimeException('Sauvegarde altérée ou clé incorrecte : déchiffrement refusé.');
                }
                [$bytes, $tag] = $result;
                fwrite($out, $bytes);
                hash_update($plain, $bytes);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $finished) {
                throw new RuntimeException('Sauvegarde tronquée : la fin du fichier manque.');
            }

            return hash_final($plain);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function sign(string $content): string
    {
        return hash_hmac('sha256', $content, $this->key);
    }
}
