<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Critères qu'une personne choisit de déclarer pour comparer les fiches à sa situation. Tout est facultatif, privé,
 * et rien n'y est jamais déduit d'une autre donnée du compte.
 */
class OpportunityProfile extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 0];

    protected function casts(): array
    {
        return ['fields' => 'array', 'languages' => 'array'];
    }
}
