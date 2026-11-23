<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Retour privé d'un apprenant après un cours. Un seul par propriétaire et par occurrence ;
 * lisible et modifiable par son seul propriétaire, quel que soit le rôle de quiconque d'autre.
 */
class CourseCheckIn extends Model
{
    /** États qui closent la sollicitation : plus aucun rappel n'est utile. */
    public const SETTLED = ['completed', 'nothing', 'ignored'];

    public const STATUS_LABELS = ['completed' => 'Renseigné', 'nothing' => 'Rien à signaler', 'deferred' => 'Reporté à plus tard', 'ignored' => 'Ignoré'];

    public const UNDERSTANDING_LABELS = [1 => 'Pas compris', 2 => 'À revoir', 3 => 'Plutôt compris', 4 => 'Bien compris'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['course_starts_at' => 'immutable_datetime', 'course_ends_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CourseCheckInEvent::class);
    }

    public function task(): HasOne
    {
        return $this->hasOne(Task::class, 'source_check_in_id');
    }
}
