<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Révision d'une fiche : état complet du contenu à cet instant, action et auteur. Jamais modifiée ni supprimée.
 */
class OpportunityRevision extends Model
{
    public const ACTIONS = ['created' => 'Création', 'updated' => 'Modification', 'submitted' => 'Envoi en vérification', 'returned' => 'Retour en brouillon',
        'verified' => 'Vérification et publication', 'reverified' => 'Nouvelle vérification', 'deadline_changed' => 'Changement de date limite',
        'suspended' => 'Suspension', 'resumed' => 'Reprise de publication', 'archived' => 'Archivage', 'auto_archived' => 'Archivage automatique à la clôture'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une révision de fiche ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une révision de fiche ne se supprime pas.'));
    }
}
