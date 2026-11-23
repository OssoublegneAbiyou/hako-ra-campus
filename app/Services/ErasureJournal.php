<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Journal minimal des effacements et des retraits d'accès. Il sert à les appliquer de nouveau après la restauration d'une sauvegarde
 * antérieure : il vit donc dans un fichier hors de la base de données, à placer sur un stockage distinct de celui qui est restauré.
 *
 * Chaque ligne ne contient que des identifiants techniques et une date — jamais de nom, d'adresse ni de contenu — et porte une
 * signature liée à la clé de l'application : une ligne modifiée ou ajoutée à la main est ignorée.
 */
class ErasureJournal
{
    public const KINDS = ['user.erased', 'membership.revoked'];

    public function path(): string
    {
        return (string) config('campus.privacy.journal_path');
    }

    public function record(string $kind, int $userId, ?int $membershipId = null): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Type d’entrée inconnu.');
        }
        $entry = ['id' => (string) Str::uuid(), 'kind' => $kind, 'user_id' => $userId, 'membership_id' => $membershipId, 'at' => now()->utc()->toIso8601String()];
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Le journal des effacements ne peut pas être écrit.');
        }
        // L'effacement n'est confirmé que si sa trace est écrite : un échec ici annule l'opération en cours.
        if (file_put_contents($this->path(), json_encode([...$entry, 'signature' => $this->sign($entry)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Le journal des effacements ne peut pas être écrit.');
        }
    }

    /**
     * Entrées valides, dans l'ordre d'écriture, et nombre de lignes rejetées (illisibles ou à la signature incorrecte).
     *
     * @return array{entries: list<array{id: string, kind: string, user_id: int, membership_id: int|null, at: string}>, rejected: int}
     */
    public function read(): array
    {
        $entries = [];
        $rejected = 0;
        if (! is_file($this->path())) {
            return ['entries' => [], 'rejected' => 0];
        }
        foreach (file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            $entry = is_array($row) ? ['id' => $row['id'] ?? null, 'kind' => $row['kind'] ?? null, 'user_id' => $row['user_id'] ?? null, 'membership_id' => $row['membership_id'] ?? null, 'at' => $row['at'] ?? null] : null;
            if ($entry === null || ! is_string($entry['id']) || ! in_array($entry['kind'], self::KINDS, true) || ! is_int($entry['user_id']) || ! is_string($entry['at'])
                || ! is_string($row['signature'] ?? null) || ! hash_equals($this->sign($entry), $row['signature'])) {
                $rejected++;

                continue;
            }
            $entries[] = $entry;
        }

        return ['entries' => $entries, 'rejected' => $rejected];
    }

    /**
     * Retire les entrées plus anciennes que la durée de conservation du journal, qui ne peut pas être inférieure à celle des sauvegardes :
     * une entrée doit vivre au moins aussi longtemps que la plus ancienne sauvegarde restaurable.
     */
    public function prune(): int
    {
        if (! is_file($this->path())) {
            return 0;
        }
        $days = max((int) config('campus.privacy.journal_retention_days'), (int) config('campus.retention.backup_days') + 30);
        $limit = CarbonImmutable::now()->subDays($days);
        $kept = [];
        $removed = 0;
        foreach (file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $at = json_decode($line, true)['at'] ?? null;
            if (is_string($at) && CarbonImmutable::parse($at)->lt($limit)) {
                $removed++;
            } else {
                $kept[] = $line;
            }
        }
        if ($removed > 0) {
            file_put_contents($this->path(), $kept === [] ? '' : implode("\n", $kept)."\n", LOCK_EX);
        }

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function sign(array $entry): string
    {
        return hash_hmac('sha256', 'erasure-journal:'.json_encode([$entry['id'], $entry['kind'], $entry['user_id'], $entry['membership_id'], $entry['at']], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
