<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RuntimeHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(): void
    {
        Cache::put('campus:runtime:worker', now()->utc()->toIso8601String(), now()->addMinutes(5));
    }
}
