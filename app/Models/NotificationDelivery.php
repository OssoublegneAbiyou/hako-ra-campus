<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envoi d'un rappel sur un canal externe. États : en file, en traitement, accepté, échec, indéterminé, annulé, expiré.
 * « Accepté » signifie accepté par le fournisseur, jamais « lu ».
 */
class NotificationDelivery extends Model
{
    public const STATUSES = ['queued' => 'En file', 'processing' => 'En traitement', 'accepted' => 'Accepté par le fournisseur', 'failed' => 'Échec',
        'uncertain' => 'Indéterminé', 'cancelled' => 'Annulé', 'expired' => 'Expiré'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'user_notification_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PushSubscription::class, 'push_subscription_id');
    }
}
