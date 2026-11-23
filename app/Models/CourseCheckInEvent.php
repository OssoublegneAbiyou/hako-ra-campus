<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Historique technique limité d'un retour : version, état et noms des champs modifiés. Jamais leur contenu.
 */
class CourseCheckInEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
