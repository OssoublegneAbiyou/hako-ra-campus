<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note personnelle : saisie par son propriétaire, privée, jamais confondue avec un résultat publié par l'établissement.
 */
class PersonalGrade extends Model
{
    use HasUuids;

    public const STATUSES = ['graded' => 'Notée', 'pending' => 'À venir', 'absent' => 'Absence', 'exempt' => 'Dispense', 'not_submitted' => 'Non rendu'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'graded', 'coefficient' => 1, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['assessed_on' => 'immutable_date'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(PersonalSubject::class, 'personal_subject_id');
    }
}
