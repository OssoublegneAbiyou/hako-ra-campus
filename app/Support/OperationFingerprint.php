<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Empreinte du contenu d'une opération hors connexion. La même forme canonique est calculée par l'appareil
 * (public/offline-core.js) : clés triées, aucune espace, chaînes en UTF-8 non échappé. Les deux calculs doivent rester identiques.
 */
class OperationFingerprint
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(string $type, ?string $targetId, int $baseVersion, array $payload): string
    {
        return hash('sha256', self::canonical(['base_version' => $baseVersion, 'payload' => $payload, 'target_id' => $targetId, 'type' => $type]));
    }

    public static function canonical(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
            if ($encoded === false) {
                throw new InvalidArgumentException('Texte illisible.');
            }

            return $encoded;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::canonical(...), $value)).']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = self::canonical((string) $key).':'.self::canonical($item);
            }

            return '{'.implode(',', $pairs).'}';
        }
        throw new InvalidArgumentException('Valeur non prise en charge.');
    }
}
