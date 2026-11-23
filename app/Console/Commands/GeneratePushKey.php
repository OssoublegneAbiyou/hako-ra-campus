<?php

namespace App\Console\Commands;

use App\Support\Vapid;
use Illuminate\Console\Command;

class GeneratePushKey extends Command
{
    protected $signature = 'campus:push:key';

    protected $description = 'Génère une clé privée Web Push (VAPID) et affiche la ligne à placer dans le fichier d’environnement. À exécuter par l’exploitant, hors de tout journal partagé.';

    public function handle(): int
    {
        $this->warn('Secret : ne le copiez que dans le fichier d’environnement du serveur.');
        $this->line('CAMPUS_PUSH_PRIVATE_KEY='.Vapid::generate());
        $this->line('CAMPUS_PUSH_SUBJECT=mailto:adresse-de-contact-de-l-exploitant');

        return self::SUCCESS;
    }
}
