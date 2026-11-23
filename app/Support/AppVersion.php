<?php

namespace App\Support;

/**
 * Version en service. À chaque mise en production, la procédure écrit le fichier REVISION à la racine (identifiant du code et date) ;
 * sans ce fichier, l'application se déclare « non versionnée », ce qui est normal sur un poste de développement.
 * L'emplacement est réglable (campus.revision_file) pour que les tests n'écrivent jamais dans le fichier réel.
 */
class AppVersion
{
    public static function current(): string
    {
        $configured = config('campus.revision_file');
        $file = is_string($configured) && $configured !== '' ? $configured : base_path('REVISION');
        $value = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $value !== '' && preg_match('/^[\w .:+\/-]{1,80}$/u', $value) === 1 ? $value : 'non versionnée';
    }
}
