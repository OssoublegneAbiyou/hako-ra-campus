<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement d'indisponibilité d'un enseignant : il ne modifie jamais le planning par lui-même.
 */
class TeacherUnavailability extends Model
{
    public const DECISIONS = ['decline' => 'Refusé', 'accept_cancel' => 'Accepté : cours annulé dans le brouillon', 'accept_other' => 'Accepté : report ou remplacement à préparer'];

    protected $guarded = ['id', 'open_slot'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(CourseOccurrence::class, 'course_occurrence_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
