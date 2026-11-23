<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Backups;
use App\Support\BackupCipher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sauvegardes : clé, création, vérification, liste, restauration dans un environnement isolé. Aucune action ne touche la base en service
 * autrement qu'en lecture, et la restauration refuse toute destination qui n'est pas vide.
 */
class BackupCommand extends Command
{
    protected $signature = 'campus:backup
        {action : key | create | verify | list | restore | journal}
        {name? : Nom de la sauvegarde (verify, restore) ; « latest » pour la plus récente}
        {--output= : key : fichier, hors dépôt, où écrire la clé générée ; journal : chemin où rétablir le journal des effacements}
        {--deep : verify : déchiffre aussi l’export et contrôle sa structure, sans base de destination}
        {--files-only : restore : ne restaure que les fichiers, quand aucune base isolée n’est disponible}
        {--database= : restore : base isolée de destination, existante et vide}
        {--storage= : restore : dossier isolé de destination des fichiers, vide ou absent}';

    protected $description = 'Sauvegarde chiffrée de la base et des fichiers privés : clé, création, vérification, liste, restauration isolée.';

    public function handle(Backups $backups): int
    {
        try {
            return match ($this->argument('action')) {
                'key' => $this->key(),
                'create' => $this->create($backups),
                'verify' => $this->verify($backups),
                'list' => $this->list($backups),
                'restore' => $this->restore($backups),
                'journal' => $this->journal($backups),
                default => $this->refuse('Action inconnue : key, create, verify, list, restore ou journal.'),
            };
        } catch (Throwable $exception) {
            // Le message d'une erreur attendue est en français et sans secret ; rien d'autre n'est affiché.
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : 'La commande a échoué. Consultez le journal de l’application.');
            report($exception);

            return self::FAILURE;
        }
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /**
     * La clé n'est jamais affichée : elle est écrite dans un fichier choisi par la personne, hors du dépôt.
     */
    private function key(): int
    {
        $output = $this->option('output');
        if (! is_string($output) || $output === '' || is_file($output)) {
            return $this->refuse('Indiquez --output=<fichier inexistant, hors du dépôt> : la clé y sera écrite, jamais affichée.');
        }
        if (str_starts_with((string) realpath(dirname($output)), (string) realpath(base_path()))) {
            return $this->refuse('Le fichier de la clé doit se trouver hors du dépôt.');
        }
        file_put_contents($output, 'CAMPUS_BACKUP_KEY='.BackupCipher::generateKey().PHP_EOL);
        @chmod($output, 0600);
        $this->info('Clé écrite dans le fichier indiqué. Reportez-la dans la configuration, et conservez-en une copie à part des sauvegardes : sans elle, aucune restauration n’est possible.');

        return self::SUCCESS;
    }

    private function create(Backups $backups): int
    {
        $created = $backups->create();
        $removed = $backups->prune();
        $manifest = $created['manifest'];
        AuditLog::record(null, 'backup.created', null, null, null, ['tables' => count($manifest['database']['tables']), 'files' => $manifest['files']['count'], 'pruned' => count($removed)]);
        $this->info('Sauvegarde '.$created['name'].' : '.count($manifest['database']['tables']).' tables, '.$manifest['files']['count'].' fichier(s), '
            .round(($manifest['database']['cipher_bytes'] + $manifest['files']['cipher_bytes']) / 1024).' Ko chiffrés. '.count($removed).' ancienne(s) sauvegarde(s) supprimée(s).');

        return self::SUCCESS;
    }

    private function resolve(Backups $backups): ?string
    {
        $name = (string) $this->argument('name');

        return $name === 'latest' ? ($backups->names()[0] ?? null) : ($name !== '' ? $name : null);
    }

    private function verify(Backups $backups): int
    {
        $name = $this->resolve($backups);
        if ($name === null) {
            return $this->refuse('Indiquez le nom d’une sauvegarde, ou « latest ».');
        }
        $manifest = $backups->verify($name);
        $this->info('Sauvegarde '.$name.' intacte : manifeste signé, fichiers chiffrés conformes à leur empreinte. Créée le '.$manifest['created_at'].', version « '.$manifest['version'].' ».');
        if ($this->option('deep')) {
            $inspection = $backups->inspect($name);
            $this->line('Export déchiffré : '.round($inspection['bytes'] / 1024).' Ko, '.$inspection['tables'].' définitions de table, '.count($inspection['missing']).' table(s) manquante(s), export '.($inspection['complete'] ? 'complet' : 'INCOMPLET').'.');
            if ($inspection['missing'] !== [] || ! $inspection['complete']) {
                return $this->refuse('L’export de la base est incomplet : cette sauvegarde ne doit pas servir à une restauration.');
            }
        }

        return self::SUCCESS;
    }

    private function list(Backups $backups): int
    {
        $names = $backups->names();
        foreach ($names as $name) {
            $this->line($name);
        }
        $this->line(count($names).' sauvegarde(s). Dernière réussite : '.($backups->lastSuccess()?->toIso8601String() ?? 'aucune').'.');

        return self::SUCCESS;
    }

    private function restore(Backups $backups): int
    {
        $name = $this->resolve($backups);
        $database = (string) $this->option('database');
        $storage = (string) $this->option('storage');
        if ($name !== null && $storage !== '' && $this->option('files-only')) {
            $files = $backups->restoreFiles($name, $storage);
            $this->line('Fichiers : '.$files['files'].' restauré(s), '.($files['files_ok'] ? 'conformes au manifeste' : 'NON conformes au manifeste').'.');
            $this->warn('Base non restaurée : aucune base isolée n’a été fournie. Cet exercice ne vaut pas restauration complète.');

            return $files['files_ok'] ? self::SUCCESS : self::FAILURE;
        }
        if ($name === null || $database === '' || $storage === '') {
            return $this->refuse('Indiquez la sauvegarde, --database=<base isolée, vide> et --storage=<dossier isolé, vide>.');
        }
        $result = $backups->restore($name, $database, $storage);
        $this->line('Base : '.$result['tables'].' tables contrôlées, '.count($result['mismatches']).' écart(s).');
        foreach ($result['mismatches'] as $mismatch) {
            $this->warn('  '.$mismatch);
        }
        $this->line('Fichiers : '.$result['files'].' restauré(s), '.($result['files_ok'] ? 'conformes au manifeste' : 'NON conformes au manifeste').'.');
        $ok = $result['mismatches'] === [] && $result['files_ok'];
        $ok ? $this->info('Restauration isolée de '.$name.' vérifiée. Pour finir : pointer l’application vers cette base, puis lancer campus:restore:finalize.')
            : $this->error('Restauration incomplète ou incohérente : ne pas mettre en service.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function journal(Backups $backups): int
    {
        $output = $this->option('output');
        if (! is_string($output) || $output === '' || is_file($output)) {
            return $this->refuse('Indiquez --output=<fichier inexistant> où rétablir le journal des effacements.');
        }
        if (! $backups->restoreJournal($output)) {
            return $this->refuse('Aucune copie du journal des effacements dans le dossier des sauvegardes.');
        }
        $this->info('Journal des effacements rétabli à partir de sa copie la plus récente.');

        return self::SUCCESS;
    }
}
