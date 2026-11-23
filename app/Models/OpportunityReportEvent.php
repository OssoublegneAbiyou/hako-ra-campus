<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Étape du traitement d'un signalement : qui, quoi, quand, et un commentaire interne réservé à l'équipe éditoriale. Jamais modifiée ni supprimée.
 */
class OpportunityReportEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une étape de traitement ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une étape de traitement ne se supprime pas.'));
    }
}
