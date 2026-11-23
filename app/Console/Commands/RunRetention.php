<?php

namespace App\Console\Commands;

use App\Services\RetentionPolicies;
use Illuminate\Console\Command;

class RunRetention extends Command
{
    protected $signature = 'campus:retention:run {--limit=500 : Nombre maximal de lignes purgées par catégorie et par exécution}';

    protected $description = 'Applique les durées de conservation : invitations, notifications, journal d’audit, exports, demandes closes, journal des effacements. Ne supprime aucun compte.';

    public function handle(RetentionPolicies $policies): int
    {
        // Seuls des nombres sont affichés : aucune donnée purgée ne sort dans les journaux.
        foreach ($policies->run((int) $this->option('limit')) as $category => $count) {
            $this->line($category.' : '.$count);
        }

        return self::SUCCESS;
    }
}
