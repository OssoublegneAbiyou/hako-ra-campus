<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Historique institutionnel d'un résultat : chaque saisie ou correction, son auteur et son motif. Jamais modifié ni supprimé.
 */
class AssessmentResultRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une révision de résultat ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une révision de résultat ne se supprime pas.'));
    }
}
