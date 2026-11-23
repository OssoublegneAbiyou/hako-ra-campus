<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Abonnement Web Push d'un appareil. Seule l'adresse du service de push est conservée : aucun contenu n'est jamais poussé.
 */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['endpoint'];

    protected function casts(): array
    {
        return ['last_accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
