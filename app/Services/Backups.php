<?php

namespace App\Services;

use App\Support\AppVersion;
use App\Support\BackupCipher;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Sauvegardes chiffrées de la base et des fichiers privés, et leur restauration dans un environnement isolé.
 *
 * Une sauvegarde est un dossier daté contenant la base (export SQL), les fichiers privés (archive), tous deux chiffrés, et un manifeste signé :
 * empreintes, tailles, nombre de lignes par table, version du code. Le dossier des sauvegardes doit se trouver hors de l'instance principale ;
 * la clé de chiffrement n'y figure jamais. Le journal des effacements n'est pas dans l'archive : il est copié à part, pour pouvoir être
 * appliqué à une base restaurée plus ancienne que lui.
 */
class Backups
{
    public const MANIFEST = 'manifest.json';

    public const JOURNAL = 'journal-effacements.enc';

    /** Dossiers du stockage privé qui ne vont pas dans l'archive : journal (sauvegardé à part), fichiers de travail. */
    private const EXCLUDED = ['journal', 'sauvegarde-travail'];

    public function directory(): ?string
    {
        $path = config('campus.backup.directory');

        return is_string($path) && trim($path) !== '' ? rtrim($path, '/\\') : null;
    }

    private function cipher(): BackupCipher
    {
        $key = BackupCipher::configuredKey();
        if ($key === null) {
            throw new RuntimeException('Clé de sauvegarde non configurée (CAMPUS_BACKUP_KEY). Générez-la avec campus:backup:key et conservez-la à part.');
        }

        return new BackupCipher($key);
    }

    private function vault(): string
    {
        $directory = $this->directory();
        if ($directory === null) {
            throw new RuntimeException('Dossier des sauvegardes non configuré (CAMPUS_BACKUP_DIRECTORY).');
        }
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    private function work(): string
    {
        $path = storage_path('app/private/sauvegarde-travail/'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    /**
     * Crée une sauvegarde complète. Renvoie son nom et son manifeste. Les fichiers en clair de travail sont supprimés dans tous les cas.
     *
     * @return array{name: string, manifest: array<string, mixed>}
     */
    public function create(): array
    {
        $cipher = $this->cipher();
        $vault = $this->vault();
        $work = $this->work();
        $name = 'campus-'.now()->utc()->format('Ymd-His');
        $target = $vault.DIRECTORY_SEPARATOR.$name;
        try {
            File::ensureDirectoryExists($target, 0700);
            $connection = config('database.connections.'.config('database.default'));
            // Nombre de lignes par table, relevé dans une lecture cohérente juste avant l'export.
            $tables = DB::transaction(function (): array {
                $counts = [];
                foreach (DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']) as $row) {
                    $table = array_values((array) $row)[0];
                    $counts[$table] = (int) DB::table($table)->count();
                }
                ksort($counts);

                return $counts;
            });
            $dump = $work.DIRECTORY_SEPARATOR.'base.sql';
            $this->run([(string) config('campus.backup.mysqldump'), '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'], '--single-transaction', '--no-tablespaces',
                '--skip-lock-tables', '--hex-blob', '--default-character-set=utf8mb4', '--result-file='.$dump, $connection['database']], (string) $connection['password'], null, 'L’export de la base a échoué.');
            $database = $cipher->encrypt($dump, $target.DIRECTORY_SEPARATOR.'base.sql.enc');

            $files = $this->tree(storage_path('app/private'));
            $archive = $work.DIRECTORY_SEPARATOR.'fichiers.tar';
            $tar = new PharData($archive);
            foreach ($files['paths'] as $relative) {
                $tar->addFile(storage_path('app/private/'.$relative), $relative);
            }
            if ($files['paths'] === []) {
                $tar->addFromString('.vide', '');
            }
            unset($tar);
            $storage = $cipher->encrypt($archive, $target.DIRECTORY_SEPARATOR.'fichiers.tar.enc');

            $manifest = [
                'format' => 1, 'name' => $name, 'created_at' => now()->utc()->toIso8601String(), 'version' => AppVersion::current(), 'database_name' => $connection['database'],
                'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
                'database' => ['file' => 'base.sql.enc', ...$database, 'tables' => $tables],
                'files' => ['file' => 'fichiers.tar.enc', ...$storage, 'count' => count($files['paths']), 'bytes' => $files['bytes'], 'tree_sha256' => $files['sha256']],
            ];
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            File::put($target.DIRECTORY_SEPARATOR.self::MANIFEST, json_encode(['manifest' => $manifest, 'signature' => $cipher->sign($encoded)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // Journal des effacements : copie chiffrée à part, toujours la plus récente. Il sert à remettre à jour une base restaurée plus ancienne.
            $journal = (string) config('campus.privacy.journal_path');
            if (is_file($journal)) {
                $cipher->encrypt($journal, $vault.DIRECTORY_SEPARATOR.self::JOURNAL);
            }
            File::put($vault.DIRECTORY_SEPARATOR.'derniere-reussite.json', json_encode(['name' => $name, 'at' => $manifest['created_at']], JSON_THROW_ON_ERROR));

            return ['name' => $name, 'manifest' => $manifest];
        } catch (\Throwable $exception) {
            // Une sauvegarde incomplète ne doit pas rester : elle passerait pour utilisable.
            File::deleteDirectory($target);
            throw $exception;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Sauvegardes présentes, de la plus récente à la plus ancienne.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $directory = $this->directory();
        if ($directory === null || ! is_dir($directory)) {
            return [];
        }
        $names = array_values(array_filter(array_map('basename', File::directories($directory)), fn (string $name) => preg_match('/^campus-\d{8}-\d{6}$/', $name) === 1));
        rsort($names);

        return $names;
    }

    /**
     * Date de la dernière sauvegarde réussie, ou null.
     */
    public function lastSuccess(): ?CarbonImmutable
    {
        $directory = $this->directory();
        $file = $directory === null ? null : $directory.DIRECTORY_SEPARATOR.'derniere-reussite.json';
        $at = $file !== null && is_file($file) ? (json_decode((string) file_get_contents($file), true)['at'] ?? null) : null;

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Supprime les sauvegardes plus anciennes que la durée de conservation, en gardant toujours les trois plus récentes.
     *
     * @return list<string> Noms supprimés.
     */
    public function prune(): array
    {
        $limit = now()->utc()->subDays(max(1, (int) config('campus.retention.backup_days')));
        $removed = [];
        foreach (array_slice($this->names(), 3) as $name) {
            if (CarbonImmutable::createFromFormat('Ymd-His', substr($name, 7), 'UTC')->lt($limit)) {
                File::deleteDirectory($this->vault().DIRECTORY_SEPARATOR.$name);
                $removed[] = $name;
            }
        }

        return $removed;
    }

    /**
     * Lit le manifeste d'une sauvegarde après contrôle de sa signature et de l'empreinte des deux fichiers chiffrés.
     *
     * @return array<string, mixed>
     */
    public function verify(string $name): array
    {
        if (preg_match('/^campus-\d{8}-\d{6}$/', $name) !== 1) {
            throw new RuntimeException('Nom de sauvegarde invalide.');
        }
        $path = $this->vault().DIRECTORY_SEPARATOR.$name;
        $file = $path.DIRECTORY_SEPARATOR.self::MANIFEST;
        if (! is_file($file)) {
            throw new RuntimeException('Sauvegarde introuvable ou sans manifeste.');
        }
        $read = json_decode((string) file_get_contents($file), true);
        $manifest = $read['manifest'] ?? null;
        $encoded = is_array($manifest) ? json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        if (! is_array($manifest) || ! is_string($read['signature'] ?? null) || ! hash_equals($this->cipher()->sign((string) $encoded), $read['signature'])) {
            throw new RuntimeException('Manifeste altéré ou signé avec une autre clé : sauvegarde refusée.');
        }
        foreach (['database', 'files'] as $part) {
            $encrypted = $path.DIRECTORY_SEPARATOR.$manifest[$part]['file'];
            if (! is_file($encrypted) || ! hash_equals($manifest[$part]['cipher_sha256'], (string) hash_file('sha256', $encrypted))) {
                throw new RuntimeException('Fichier de sauvegarde manquant ou altéré : '.$manifest[$part]['file'].'.');
            }
        }

        return $manifest;
    }

    /**
     * Restaure une sauvegarde dans une base et un dossier isolés, puis contrôle l'intégrité : empreintes, nombre de lignes par table, fichiers.
     * Refuse la base en service, une base non vide et un dossier non vide : cette commande ne peut rien écraser.
     *
     * @return array{manifest: array<string, mixed>, tables: int, mismatches: list<string>, files: int, files_ok: bool}
     */
    public function restore(string $name, string $database, string $storage): array
    {
        $manifest = $this->verify($name);
        $cipher = $this->cipher();
        $connection = config('database.connections.'.config('database.default'));
        if ($database === $connection['database'] || preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('La base de destination doit être une base isolée, différente de la base en service.');
        }
        if (is_dir($storage) && (new FilesystemIterator($storage))->valid()) {
            throw new RuntimeException('Le dossier de destination des fichiers doit être vide.');
        }
        config(['database.connections.campus_restore' => [...$connection, 'database' => $database]]);
        DB::purge('campus_restore');
        try {
            $existing = DB::connection('campus_restore')->select('SHOW TABLES');
        } catch (\Throwable) {
            throw new RuntimeException('Base de destination inaccessible : elle doit exister et être ouverte au compte de la base. Créez-la d’abord, vide.');
        }
        if ($existing !== []) {
            throw new RuntimeException('La base de destination n’est pas vide : rien n’y est restauré.');
        }
        $work = $this->work();
        $path = $this->vault().DIRECTORY_SEPARATOR.$name;
        try {
            $dump = $work.DIRECTORY_SEPARATOR.'base.sql';
            if (! hash_equals($manifest['database']['plain_sha256'], $cipher->decrypt($path.DIRECTORY_SEPARATOR.'base.sql.enc', $dump))) {
                throw new RuntimeException('L’export de la base ne correspond pas à son empreinte.');
            }
            $this->run([(string) config('campus.backup.mysql'), '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'], '--default-character-set=utf8mb4', $database],
                (string) $connection['password'], $dump, 'Le chargement de la base a échoué.');
            $mismatches = [];
            foreach ($manifest['database']['tables'] as $table => $expected) {
                try {
                    $found = (int) DB::connection('campus_restore')->table($table)->count();
                } catch (\Throwable) {
                    $found = -1;
                }
                if ($found !== $expected) {
                    $mismatches[] = $table.' : '.($found < 0 ? 'table absente' : $found.' ligne(s)').', attendu '.$expected;
                }
            }
            $archive = $work.DIRECTORY_SEPARATOR.'fichiers.tar';
            if (! hash_equals($manifest['files']['plain_sha256'], $cipher->decrypt($path.DIRECTORY_SEPARATOR.'fichiers.tar.enc', $archive))) {
                throw new RuntimeException('L’archive des fichiers ne correspond pas à son empreinte.');
            }
            File::ensureDirectoryExists($storage, 0700);
            (new PharData($archive))->extractTo($storage, null, true);
            File::delete($storage.DIRECTORY_SEPARATOR.'.vide');
            $tree = $this->tree($storage);

            return ['manifest' => $manifest, 'tables' => count($manifest['database']['tables']), 'mismatches' => $mismatches, 'files' => count($tree['paths']),
                'files_ok' => count($tree['paths']) === $manifest['files']['count'] && hash_equals($manifest['files']['tree_sha256'], $tree['sha256'])];
        } finally {
            File::deleteDirectory($work);
            DB::purge('campus_restore');
        }
    }

    /**
     * Contrôle approfondi sans base de destination : déchiffre l'export, vérifie son empreinte, qu'il est complet (marque de fin)
     * et qu'il contient la définition de chaque table annoncée par le manifeste. Rien n'est conservé en clair.
     *
     * @return array{tables: int, missing: list<string>, complete: bool, bytes: int}
     */
    public function inspect(string $name): array
    {
        $manifest = $this->verify($name);
        $work = $this->work();
        try {
            $dump = $work.DIRECTORY_SEPARATOR.'base.sql';
            if (! hash_equals($manifest['database']['plain_sha256'], $this->cipher()->decrypt($this->vault().DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'base.sql.enc', $dump))) {
                throw new RuntimeException('L’export de la base ne correspond pas à son empreinte.');
            }
            $found = [];
            $complete = false;
            $handle = fopen($dump, 'rb');
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $match) === 1) {
                    $found[$match[1]] = true;
                }
                $complete = $complete || str_starts_with($line, '-- Dump completed');
            }
            fclose($handle);

            return ['tables' => count($found), 'missing' => array_values(array_diff(array_keys($manifest['database']['tables']), array_keys($found))), 'complete' => $complete, 'bytes' => (int) filesize($dump)];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Restaure seulement les fichiers privés dans un dossier isolé et vide, et contrôle leur conformité au manifeste.
     * Sert quand aucune base isolée n'est disponible : la base, elle, n'est alors pas restaurée.
     *
     * @return array{files: int, files_ok: bool}
     */
    public function restoreFiles(string $name, string $storage): array
    {
        $manifest = $this->verify($name);
        if (is_dir($storage) && (new FilesystemIterator($storage))->valid()) {
            throw new RuntimeException('Le dossier de destination des fichiers doit être vide.');
        }
        $work = $this->work();
        try {
            $archive = $work.DIRECTORY_SEPARATOR.'fichiers.tar';
            if (! hash_equals($manifest['files']['plain_sha256'], $this->cipher()->decrypt($this->vault().DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'fichiers.tar.enc', $archive))) {
                throw new RuntimeException('L’archive des fichiers ne correspond pas à son empreinte.');
            }
            File::ensureDirectoryExists($storage, 0700);
            (new PharData($archive))->extractTo($storage, null, true);
            File::delete($storage.DIRECTORY_SEPARATOR.'.vide');
            $tree = $this->tree($storage);

            return ['files' => count($tree['paths']), 'files_ok' => count($tree['paths']) === $manifest['files']['count'] && hash_equals($manifest['files']['tree_sha256'], $tree['sha256'])];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Rétablit le journal des effacements à partir de sa copie chiffrée la plus récente, vers le chemin donné.
     */
    public function restoreJournal(string $destination): bool
    {
        $copy = $this->vault().DIRECTORY_SEPARATOR.self::JOURNAL;
        if (! is_file($copy)) {
            return false;
        }
        File::ensureDirectoryExists(dirname($destination), 0700);
        $this->cipher()->decrypt($copy, $destination);

        return true;
    }

    /**
     * Fichiers d'un dossier, hors dossiers exclus : chemins relatifs triés, taille totale et empreinte de l'ensemble (chemin, taille, empreinte de chaque fichier).
     *
     * @return array{paths: list<string>, bytes: int, sha256: string}
     */
    public function tree(string $root): array
    {
        $lines = [];
        $bytes = 0;
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                if (! $file->isFile() || in_array(explode('/', $relative)[0], self::EXCLUDED, true) || $relative === '.gitignore') {
                    continue;
                }
                $lines[$relative] = $relative."\t".$file->getSize()."\t".hash_file('sha256', $file->getPathname());
                $bytes += $file->getSize();
            }
        }
        ksort($lines);

        return ['paths' => array_keys($lines), 'bytes' => $bytes, 'sha256' => hash('sha256', implode("\n", $lines))];
    }

    /**
     * Lance un outil MySQL. Le mot de passe passe par l'environnement du processus, jamais par la ligne de commande ni par un journal.
     *
     * @param  list<string>  $command
     */
    private function run(array $command, string $password, ?string $inputFile, string $failure): void
    {
        $process = new Process($command, base_path(), ['MYSQL_PWD' => $password]);
        $process->setTimeout(3600);
        $input = $inputFile !== null ? fopen($inputFile, 'rb') : null;
        if ($input) {
            $process->setInput($input);
        }
        $process->run();
        if (is_resource($input)) {
            fclose($input);
        }
        if (! $process->isSuccessful()) {
            // Seule la première ligne de l'erreur de l'outil est rapportée : elle ne contient ni mot de passe ni donnée.
            throw new RuntimeException($failure.' '.trim(strtok($process->getErrorOutput(), "\n") ?: ''));
        }
    }
}
