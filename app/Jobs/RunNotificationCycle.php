<?php

namespace App\Jobs;

use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un cycle de rappels : planifier, remettre, envoyer. Lancé chaque minute par le planificateur, exécuté par la file,
 * donc sans aucun onglet ouvert. Rejouable et concurrent sans doublon : chaque étape repose sur des clés uniques et des verrous.
 */
class RunNotificationCycle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    /**
     * @return array<string, array<string, int>>
     */
    public function handle(NotificationPlanner $planner, NotificationDispatcher $dispatcher): array
    {
        return ['planned' => $planner->run(), 'released' => $dispatcher->release(), 'sent' => $dispatcher->send()];
    }
}
