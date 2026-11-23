<?php

namespace App\Console\Commands;

use App\Services\Opportunities;
use Illuminate\Console\Command;

class ArchiveClosedOpportunities extends Command
{
    protected $signature = 'campus:opportunities:archive-closed {--limit=200 : Nombre maximal de fiches archivées par exécution}';

    protected $description = 'Archive les fiches publiées dont la clôture connue est passée. Les fiches à clôture continue ou sans date communiquée ne sont jamais archivées d’office.';

    public function handle(Opportunities $opportunities): int
    {
        $this->info($opportunities->archiveClosed(max(1, (int) $this->option('limit'))).' fiche(s) archivée(s).');

        return self::SUCCESS;
    }
}
