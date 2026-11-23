<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\ScheduleImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Simulation d'un lot d'import, bornée en durée et sans reprise automatique. Les droits de l'auteur sont revérifiés à l'exécution.
 */
class AnalyzeScheduleImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $batchId) {}

    public function handle(ScheduleImporter $importer): void
    {
        $importer->analyze($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch && in_array($batch->status, ['uploaded', 'analyzing'], true)) {
            app(ScheduleImporter::class)->fail($batch, 'L’analyse a échoué pour une raison technique. Relancez-la ; si l’échec persiste, signalez-le au support.');
        }
    }
}
