<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fiche du catalogue éditorial : bourse, concours, stage ou formation. Seules les fiches « published » sont visibles hors de l'équipe éditoriale.
 */
class Opportunity extends Model
{
    use HasUuids;

    public const CATEGORIES = ['scholarship' => 'Bourse', 'competition' => 'Concours', 'internship' => 'Stage', 'training' => 'Formation'];

    public const MODALITIES = ['onsite' => 'Sur place', 'remote' => 'À distance', 'hybrid' => 'Hybride', 'unspecified' => 'Non précisé'];

    public const LEVELS = ['secondary' => 'Secondaire', 'bachelor' => 'Licence', 'master' => 'Master', 'doctorate' => 'Doctorat', 'professional' => 'Professionnel', 'any' => 'Tous niveaux'];

    public const FUNDING = ['full' => 'Financement complet', 'partial' => 'Financement partiel', 'none' => 'Non financé', 'unspecified' => 'Financement non précisé'];

    public const FEES = ['free' => 'Candidature gratuite', 'paid' => 'Candidature payante', 'unspecified' => 'Frais non précisés'];

    public const DEADLINES = ['instant' => 'Date et heure, avec fuseau', 'date' => 'Date seule, sans heure', 'rolling' => 'Clôture continue', 'unknown' => 'Date non communiquée'];

    public const STATUSES = ['draft' => 'Brouillon', 'review' => 'À vérifier', 'published' => 'Publiée', 'suspended' => 'Suspendue', 'archived' => 'Archivée'];

    /** Champs éditoriaux, dans l'ordre du formulaire ; ce sont aussi ceux conservés dans chaque révision. */
    public const CONTENT = ['category', 'title', 'organizer', 'campaign', 'source_url', 'application_url', 'summary', 'conditions', 'countries', 'modality', 'levels', 'fields',
        'languages', 'eligible_nationalities', 'eligible_residences', 'funding', 'fees', 'fees_detail', 'deadline_kind', 'deadline_at', 'deadline_timezone', 'deadline_date'];

    /** Champs de CONTENT stockés en liste. */
    public const LISTS = ['countries', 'levels', 'fields', 'languages', 'eligible_nationalities', 'eligible_residences'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'modality' => 'unspecified', 'funding' => 'unspecified', 'fees' => 'unspecified', 'deadline_kind' => 'unknown', 'lock_version' => 1];

    protected function casts(): array
    {
        return ['countries' => 'array', 'levels' => 'array', 'fields' => 'array', 'languages' => 'array', 'eligible_nationalities' => 'array', 'eligible_residences' => 'array', 'previous_deadline' => 'array',
            'deadline_at' => 'immutable_datetime', 'deadline_date' => 'immutable_date', 'last_day_starts_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'suspended_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OpportunityRevision::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(OpportunityReport::class);
    }
}
