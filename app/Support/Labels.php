<?php

namespace App\Support;

use Locale;

/**
 * Noms français des pays et des langues à partir de leur code à deux lettres, quand l'extension intl est disponible.
 * Sans elle, ou pour un code inconnu, le code lui-même est affiché : aucun nom n'est inventé.
 */
class Labels
{
    public static function country(string $code): string
    {
        $code = strtoupper($code);
        $name = class_exists(Locale::class) ? (string) Locale::getDisplayRegion('-'.$code, 'fr') : '';

        return $name === '' || $name === $code ? $code : $name.' ('.$code.')';
    }

    public static function language(string $code): string
    {
        $code = strtolower($code);
        $name = class_exists(Locale::class) ? (string) Locale::getDisplayLanguage($code, 'fr') : '';

        return $name === '' || $name === $code ? $code : $name.' ('.$code.')';
    }
}
