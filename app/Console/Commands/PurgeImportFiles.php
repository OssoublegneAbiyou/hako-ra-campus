<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeImportFiles extends Command
{
    protected $signature = 'campus:purge-import-files {--limit=500 : Nombre maximal de lots traités par exécution}';

    protected $description = 'Supprime le fichier brut et les lignes lues des lots d’import plus anciens que la durée de conservation ; le rapport et le bilan sont conservés.';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) config('campus.imports.raw_retention_days')));
        $purged = 0;
        ImportBatch::query()->whereNull('raw_purged_at')->where('created_at', '<', $before)->whereNotIn('status', ['analyzing', 'applying'])
            ->orderBy('created_at')->limit((int) $this->option('limit'))->get()->each(function (ImportBatch $batch) use (&$purged): void {
                if ($batch->stored_path !== null) {
                    Storage::disk('local')->delete($batch->stored_path);
                }
                // Un lot non appliqué ne peut plus l'être une fois ses lignes purgées.
                $batch->forceFill([
                    'stored_path' => null, 'parsed_rows' => null, 'raw_purged_at' => now(),
                    'status' => in_array($batch->status, ['uploaded', 'valid'], true) ? 'failed' : $batch->status,
                    'failure' => in_array($batch->status, ['uploaded', 'valid'], true) ? 'Fichier purgé après la durée de conservation : déposez-le de nouveau pour l’appliquer.' : $batch->failure,
                ])->save();
                $purged++;
            });
        $this->info($purged.' lot(s) purgé(s).');

        return self::SUCCESS;
    }
}
