<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Microtâche : une action simple d'une tâche, propre à la personne qui la découpe, y compris sur un devoir collectif.
 */
class TaskStep extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['done' => false];

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }
}
