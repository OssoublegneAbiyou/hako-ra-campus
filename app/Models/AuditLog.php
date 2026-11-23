<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d’audit ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal d’audit ne se supprime pas ici.'));
    }

    /**
     * Trace limitée : jamais de secret, de mot de passe ni de contenu privé dans les métadonnées.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public static function record(?User $actor, string $action, ?Model $subject = null, ?int $institutionId = null, ?string $reason = null, array $metadata = []): self
    {
        return self::create([
            'actor_type' => $actor ? 'user' : 'system',
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getTable() : null,
            'subject_id' => $subject && $subject->getKeyType() !== 'string' ? $subject->getKey() : null,
            'subject_uuid' => $subject && $subject->getKeyType() === 'string' ? $subject->getKey() : null,
            'institution_id' => $institutionId,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
        ]);
    }
}
