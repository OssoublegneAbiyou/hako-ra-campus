<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coefficient d'une matière pour une année d'un établissement, fixé par son administration.
 * Sans ligne, le coefficient est « non défini » : aucune valeur par défaut n'est inventée.
 */
class SubjectCoefficient extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];
}
