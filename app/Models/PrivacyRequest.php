<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'exercice d'un droit (accès, rectification, opposition, effacement), suivie de sa réception à sa clôture.
 */
class PrivacyRequest extends Model
{
    use HasUuids;

    public const TYPES = ['access' => 'Accès et export de mes données', 'rectification' => 'Rectification', 'objection' => 'Opposition à un traitement', 'erasure' => 'Suppression du compte'];

    public const STATUSES = ['received' => 'Reçue', 'in_progress' => 'En cours de traitement', 'completed' => 'Traitée', 'refused' => 'Refusée', 'cancelled' => 'Annulée par vous'];

    public const OPEN = ['received', 'in_progress'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'received'];

    protected function casts(): array
    {
        return ['identity_verified_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
