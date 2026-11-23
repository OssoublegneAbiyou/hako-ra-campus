<?php

namespace App\Console\Commands;

use App\Services\Tasks;
use Illuminate\Console\Command;

class PurgeTasks extends Command
{
    protected $signature = 'campus:purge-tasks {--limit=500 : Nombre maximal de tâches purgées par exécution}';

    protected $description = 'Supprime définitivement les tâches et devoirs restés à la corbeille au-delà du délai de restauration, avec leurs étapes et avancements.';

    public function handle(Tasks $tasks): int
    {
        // Seul un nombre est affiché : aucun titre ni contenu de tâche ne sort dans les journaux.
        $this->info($tasks->purge(max(1, (int) $this->option('limit'))).' tâche(s) purgée(s).');

        return self::SUCCESS;
    }
}
