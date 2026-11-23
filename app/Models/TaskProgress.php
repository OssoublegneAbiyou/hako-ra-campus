<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Avancement d'un apprenant sur un devoir collectif, et son objectif personnel éventuel. Privé : propre à son titulaire.
 */
class TaskProgress extends Model
{
    protected $table = 'task_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['personal_target_at' => 'immutable_datetime'];
    }
}
