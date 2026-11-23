<?php

namespace App\Support\Scanners;

/**
 * Aucun antivirus configuré : chaque analyse échoue, donc aucun fichier n'est jamais diffusé.
 */
class UnavailableScanner implements Scanner
{
    public function scan(string $absolutePath): array
    {
        return ['result' => 'error', 'detail' => 'scanner_not_configured'];
    }

    public function available(): bool
    {
        return false;
    }
}
