<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pièce à préparer, sous forme de simple ligne de texte à cocher. Ce n'est jamais un fichier.
 */
class OpportunityApplicationItem extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['done' => false];

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }
}
