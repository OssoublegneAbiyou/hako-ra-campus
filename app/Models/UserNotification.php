<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rappel logique destiné à une personne. Visible dans le centre interne une fois remis (« delivered »).
 * Le titre ne contient jamais de contenu scolaire sensible : c'est le seul texte qui sort par courriel.
 */
class UserNotification extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return ['context' => 'array', 'anchor_at' => 'immutable_datetime', 'scheduled_for' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
