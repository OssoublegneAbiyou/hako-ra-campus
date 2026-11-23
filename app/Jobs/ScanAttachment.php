<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Support\Scanners\Scanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Analyse antivirus d'une pièce jointe, hors de la requête de dépôt. Seul un résultat « sain » explicite ouvre la diffusion :
 * scanner absent, injoignable ou en erreur laissent le fichier bloqué, et l'analyse sera retentée plus tard.
 */
class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $attachmentId) {}

    public function handle(Scanner $scanner): void
    {
        // Réservation : une seule analyse à la fois par fichier, même si la tâche est rejouée.
        $attachment = DB::transaction(function (): ?Attachment {
            $attachment = Attachment::query()->lockForUpdate()->find($this->attachmentId);
            if ($attachment === null || $attachment->deleted_at !== null || ! in_array($attachment->scan_status, ['quarantine', 'error'], true)) {
                return null;
            }
            $attachment->forceFill(['scan_status' => 'scanning', 'scan_started_at' => now(), 'scan_attempts' => $attachment->scan_attempts + 1])->save();

            return $attachment;
        });
        if ($attachment === null) {
            return;
        }
        try {
            $result = Storage::disk('local')->exists($attachment->path())
                ? $scanner->scan(Storage::disk('local')->path($attachment->path()))
                : ['result' => 'error', 'detail' => 'file_missing'];
        } catch (Throwable) {
            $result = ['result' => 'error', 'detail' => 'scanner_exception'];
        }
        $status = ['clean' => 'clean', 'infected' => 'rejected'][$result['result']] ?? 'error';
        $attachment->forceFill(['scan_status' => $status, 'scan_detail' => $result['detail'] ?? null, 'scanned_at' => $status === 'error' ? null : now()])->save();
        if ($status === 'rejected') {
            // Fichier refusé : il est retiré du stockage tout de suite ; la ligne reste pour expliquer le refus à son propriétaire.
            Storage::disk('local')->delete($attachment->path());
            $attachment->forceFill(['file_removed_at' => now()])->save();
        }
    }
}
