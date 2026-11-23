<?php

namespace App\Console\Commands;

use App\Jobs\RunNotificationCycle;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use Illuminate\Console\Command;

class RunNotifications extends Command
{
    protected $signature = 'campus:notifications:run';

    protected $description = 'Exécute immédiatement un cycle de rappels (planification, remise, envoi). N’affiche que des compteurs.';

    public function handle(NotificationPlanner $planner, NotificationDispatcher $dispatcher): int
    {
        $result = (new RunNotificationCycle)->handle($planner, $dispatcher);
        foreach ($result as $step => $counters) {
            $this->line($step.' : '.collect($counters)->map(fn (int $count, string $name) => $name.'='.$count)->implode(', '));
        }

        return self::SUCCESS;
    }
}
