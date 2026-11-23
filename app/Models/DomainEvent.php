<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Boîte d'événements métier, écrite dans la transaction qui les produit.
 * Un consommateur ne voit donc que des faits validés ; dedup_key empêche tout doublon.
 */
class DomainEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
