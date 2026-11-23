<?php

namespace App\Console\Commands;

use App\Services\SyncOperations;
use Illuminate\Console\Command;

class PruneSyncOperations extends Command
{
    protected $signature = 'campus:sync:prune';

    protected $description = 'Supprime les accusés de synchronisation plus anciens que la durée de conservation. Ces accusés ne contiennent aucun contenu saisi.';

    public function handle(SyncOperations $operations): int
    {
        $this->info($operations->prune((int) config('campus.offline.sync_retention_days')).' accusé(s) de synchronisation supprimé(s).');

        return self::SUCCESS;
    }
}
