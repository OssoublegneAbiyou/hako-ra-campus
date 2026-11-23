<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Favori : une fiche qu'une personne choisit de suivre. Unique par personne et par fiche.
 */
class OpportunityFavorite extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
