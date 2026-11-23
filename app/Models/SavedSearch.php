<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Recherche enregistrée par une personne, avec la fréquence et les canaux de sa synthèse. Privée : son auteur seul la lit et la modifie.
 */
class SavedSearch extends Model
{
    public const FREQUENCIES = ['daily' => 'Une synthèse par jour au plus', 'weekly' => 'Une synthèse par semaine au plus'];

    /** Filtres du catalogue qu'une recherche enregistrée peut retenir. La clôture, le tri et la page n'en font pas partie : une alerte porte toujours sur les fiches ouvertes. */
    public const FILTERS = ['q', 'categorie', 'niveau', 'pays', 'domaine', 'modalite', 'financement', 'langue'];

    protected $guarded = ['id'];

    protected $attributes = ['frequency' => 'daily', 'email' => false, 'push' => false, 'active' => true, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['filters' => 'array', 'email' => 'boolean', 'push' => 'boolean', 'active' => 'boolean', 'baseline_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime', 'last_digest_at' => 'immutable_datetime'];
    }
}
