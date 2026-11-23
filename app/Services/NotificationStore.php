<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Écritures élémentaires des rappels : création rejouable (clé unique) et annulation des rappels devenus inutiles.
 */
class NotificationStore
{
    /**
     * Crée un rappel s'il n'existe pas déjà pour cette clé. Renvoie vrai seulement lors de la création.
     *
     * @param  array<string, mixed>  $context
     */
    public function create(int $userId, string $category, string $type, string $title, ?string $body, string $url, string $dedupKey,
        CarbonImmutable $scheduledFor, CarbonImmutable $expiresAt, ?string $subject = null, ?CarbonImmutable $anchor = null, array $context = []): bool
    {
        $now = now();

        return DB::table('user_notifications')->insertOrIgnore([
            'user_id' => $userId, 'category' => $category, 'type' => $type, 'title' => $title, 'body' => $body === null ? null : mb_substr($body, 0, 300), 'url' => $url,
            'subject_uuid' => $subject, 'anchor_at' => $anchor?->utc()->format('Y-m-d H:i:s'), 'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'dedup_key' => $dedupKey, 'scheduled_for' => $scheduledFor->utc()->format('Y-m-d H:i:s'), 'expires_at' => $expiresAt->utc()->format('Y-m-d H:i:s'),
            'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Annule les rappels encore programmés ou déjà visibles, et leurs envois externes en attente.
     *
     * @param  list<string>  $types
     */
    public function cancel(?int $userId, array $types, string $subject, string $reason): int
    {
        $ids = DB::table('user_notifications')->whereIn('status', ['scheduled', 'delivered'])->whereIn('type', $types)->where('subject_uuid', $subject)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))->lockForUpdate()->pluck('id');

        return $this->cancelIds($ids->all(), $reason);
    }

    /**
     * Annule tous les rappels d'une catégorie portant sur un objet, pour toutes les personnes (fiche suspendue, archivée ou date changée).
     */
    public function cancelSubject(string $category, string $subject, string $reason): int
    {
        return $this->cancelIds(DB::table('user_notifications')->whereIn('status', ['scheduled', 'delivered'])->where('category', $category)
            ->where('subject_uuid', $subject)->lockForUpdate()->pluck('id')->all(), $reason);
    }

    /**
     * @param  list<int>  $ids
     */
    public function cancelIds(array $ids, string $reason): int
    {
        if ($ids === []) {
            return 0;
        }
        DB::table('notification_deliveries')->whereIn('user_notification_id', $ids)->where('status', 'queued')
            ->update(['status' => 'cancelled', 'last_error' => $reason, 'finished_at' => now(), 'updated_at' => now()]);

        return DB::table('user_notifications')->whereIn('id', $ids)->update(['status' => 'cancelled', 'status_reason' => $reason, 'updated_at' => now()]);
    }
}
