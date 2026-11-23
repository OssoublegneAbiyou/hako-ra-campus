<?php

namespace App\Support\Scanners;

/**
 * Analyse antivirus d'un fichier local. Le résultat est l'un de : clean (sain), infected (refusé), error (analyse impossible).
 * Une analyse impossible ne vaut jamais « sain ».
 */
interface Scanner
{
    /**
     * @return array{result: string, detail: string|null}
     */
    public function scan(string $absolutePath): array;

    /**
     * Le scanner répond-il maintenant ? Sert au contrôle d'exploitation, jamais à valider un fichier.
     */
    public function available(): bool;
}
