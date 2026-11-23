<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\ScheduleImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Application atomique d'un lot au brouillon. Sans reprise automatique : un lot à moitié rejoué n'a pas de sens,
 * la transaction garantit le tout ou rien.
 */
class ApplyScheduleImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** @param array<int|string, string> $decisions */
    public function __construct(public string $batchId, public int $actorId, public int $membershipId, public array $decisions, public bool $confirmConflicts, public ?string $conflictReason) {}

    public function handle(ScheduleImporter $importer): void
    {
        $importer->apply($this->batchId, $this->actorId, $this->membershipId, $this->decisions, $this->confirmConflicts, $this->conflictReason);
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch && $batch->status === 'applying') {
            app(ScheduleImporter::class)->fail($batch, 'L’application a échoué pour une raison technique : aucune ligne n’a été appliquée. Relancez l’analyse.');
        }
    }
}
