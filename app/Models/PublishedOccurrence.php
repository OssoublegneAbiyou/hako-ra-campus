<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * État actuellement publié d'une occurrence : projection de lecture, reconstruite par les publications.
 * L'identifiant est celui de l'occurrence et ne change jamais.
 */
class PublishedOccurrence extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'course_occurrence_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'published_occurrence_group', 'course_occurrence_id', 'group_id');
    }
}
