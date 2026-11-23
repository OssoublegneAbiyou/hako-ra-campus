<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Résultat d'un apprenant à une évaluation. L'absence de ligne signifie « note manquante », jamais zéro.
 */
class AssessmentResult extends Model
{
    public const STATUSES = PersonalGrade::STATUSES + ['missing' => 'Note manquante'];

    protected $guarded = ['id'];

    protected $attributes = ['version' => 0];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
