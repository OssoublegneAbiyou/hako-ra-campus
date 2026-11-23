<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Signalement d'une fiche par un utilisateur connecté. L'identité du signalant n'est jamais affichée, ni au public ni dans la file éditoriale.
 */
class OpportunityReport extends Model
{
    public const REASONS = ['broken_link' => 'Lien cassé', 'wrong_date' => 'Date erronée', 'closed' => 'Offre close', 'authenticity' => 'Doute sur l’authenticité', 'other' => 'Autre'];

    public const STATUSES = ['new' => 'Nouveau', 'in_progress' => 'En cours de traitement', 'resolved' => 'Traité', 'rejected' => 'Classé sans suite'];

    public const DECISIONS = ['corrected' => 'Fiche corrigée', 'suspended' => 'Fiche suspendue', 'archived' => 'Fiche archivée', 'no_action' => 'Aucune action nécessaire'];

    protected $guarded = ['id'];

    protected $hidden = ['reporter_id'];

    protected $attributes = ['status' => 'new', 'open_slot' => 1, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OpportunityReportEvent::class);
    }
}
