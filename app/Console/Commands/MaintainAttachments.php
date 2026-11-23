<?php

namespace App\Console\Commands;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\AttachmentReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MaintainAttachments extends Command
{
    protected $signature = 'campus:attachments:maintain {--limit=200 : Nombre maximal d’éléments traités par étape}';

    protected $description = 'Relance les analyses antivirus en attente, retire les fichiers supprimés et purge les fichiers orphelins. N’affiche que des compteurs.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $disk = Storage::disk('local');

        // Analyse interrompue (processus arrêté) : le fichier repasse en quarantaine, il n'est jamais considéré comme sain.
        $stale = Attachment::query()->where('scan_status', 'scanning')->where('scan_started_at', '<=', now()->subMinutes((int) config('campus.attachments.scan_stale_minutes')))
            ->update(['scan_status' => 'quarantine']);
        $queued = 0;
        Attachment::query()->whereIn('scan_status', ['quarantine', 'error'])->whereNull('deleted_at')->where('updated_at', '<=', now()->subMinute())
            ->orderBy('updated_at')->limit($limit)->pluck('id')->each(function (string $id) use (&$queued): void {
                ScanAttachment::dispatch($id);
                $queued++;
            });

        // Fichiers de lignes supprimées encore présents, puis lignes supprimées depuis longtemps.
        $removed = 0;
        Attachment::query()->whereNotNull('deleted_at')->whereNull('file_removed_at')->limit($limit)->get()->each(function (Attachment $attachment) use ($disk, &$removed): void {
            $disk->delete($attachment->path());
            $attachment->forceFill(['file_removed_at' => now()])->save();
            $removed++;
        });
        $old = Attachment::query()->whereNotNull('deleted_at')->where('deleted_at', '<=', now()->subDays((int) config('campus.attachments.deleted_row_retention_days')))->limit($limit)->pluck('id');
        AttachmentReport::query()->whereIn('attachment_id', $old)->delete();
        $purged = Attachment::query()->whereIn('id', $old)->delete();

        // Fichiers orphelins : présents dans le stockage sans aucune ligne, depuis plus d'un jour (un dépôt en cours n'est jamais touché).
        $orphans = 0;
        foreach (array_slice($disk->files('attachments'), 0, $limit * 10) as $path) {
            if ($orphans < $limit && $disk->lastModified($path) < now()->subDay()->getTimestamp() && ! Attachment::query()->where('stored_name', basename($path))->exists()) {
                $disk->delete($path);
                $orphans++;
            }
        }
        $this->info("analyses relancées={$queued}, analyses interrompues={$stale}, fichiers retirés={$removed}, lignes purgées={$purged}, orphelins supprimés={$orphans}");

        return self::SUCCESS;
    }
}
