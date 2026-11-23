<?php

namespace App\Console\Commands;

use App\Services\HealthChecks;
use Illuminate\Console\Command;

class HealthCommand extends Command
{
    protected $signature = 'campus:health {--only=* : Limiter à certains contrôles (database, queue, worker, scheduler, backup, scanner, disk, certificate, deliveries)} {--json : Sortie JSON}';

    protected $description = 'Supervision : un contrôle par composant (base, file, processus de file, planificateur, sauvegarde, antivirus, disque, certificat, envois).';

    public function handle(HealthChecks $health): int
    {
        $only = array_filter((array) $this->option('only'));
        $report = $health->run($only === [] ? null : array_values($only));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Version en service : '.$report['version']);
            foreach ($report['checks'] as $check) {
                $this->line(mb_str_pad(['ok' => 'OK', 'alert' => 'ALERTE', 'unconfigured' => 'NON CONFIGURÉ'][$check['status']], 15).$check['label'].' — '.$check['detail']);
            }
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
