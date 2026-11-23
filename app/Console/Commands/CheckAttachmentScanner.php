<?php

namespace App\Console\Commands;

use App\Support\Scanners\ClamdScanner;
use App\Support\Scanners\Scanner;
use Illuminate\Console\Command;

class CheckAttachmentScanner extends Command
{
    protected $signature = 'campus:attachments:scanner-check';

    protected $description = 'Vérifie l’antivirus configuré : réponse du démon, détection du fichier de test standard EICAR (inoffensif) et acceptation d’un contenu sain. Rien n’est écrit sur disque.';

    public function handle(Scanner $scanner): int
    {
        $this->line('Scanner configuré : '.config('campus.attachments.scanner'));
        if (! $scanner instanceof ClamdScanner) {
            $this->error('Aucun antivirus configuré : aucune pièce jointe ne peut être diffusée.');

            return self::FAILURE;
        }
        if (! $scanner->available()) {
            $this->error('Le démon antivirus ne répond pas : les fichiers restent bloqués.');

            return self::FAILURE;
        }
        // Chaîne de test EICAR, reconnue par tous les antivirus et sans aucun effet. Elle est assemblée ici en mémoire
        // pour que le dépôt de code ne contienne jamais la signature complète.
        $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-'.'TEST-FILE!$H+H*';
        $detected = $scanner->scanBytes($eicar);
        $clean = $scanner->scanBytes('Contenu fictif sain de HAKO-RA Campus.');
        $this->line('Fichier de test EICAR : '.$detected['result'].($detected['detail'] ? ' ('.$detected['detail'].')' : ''));
        $this->line('Contenu sain : '.$clean['result']);
        if ($detected['result'] !== 'infected' || $clean['result'] !== 'clean') {
            $this->error('Le scanner ne se comporte pas comme attendu : il ne doit pas être considéré comme actif.');

            return self::FAILURE;
        }
        $this->info('Antivirus opérationnel sur ce serveur.');

        return self::SUCCESS;
    }
}
