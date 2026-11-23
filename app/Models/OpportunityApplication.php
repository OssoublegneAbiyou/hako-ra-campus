<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Démarche privée : le suivi qu'une personne tient, pour elle seule, d'une candidature. L'état ne change que par un choix de la personne ;
 * ouvrir le site de l'organisateur ne le modifie jamais.
 */
class OpportunityApplication extends Model
{
    use HasUuids;

    public const STATUSES = ['to_study' => 'À étudier', 'preparing' => 'Dossier en préparation', 'sent' => 'Envoyée', 'waiting' => 'En attente',
        'accepted' => 'Retenue', 'rejected' => 'Non retenue', 'abandoned' => 'Abandonnée'];

    /** États où la démarche est encore en cours : utiles aux rappels du lot 18. */
    public const ACTIVE = ['to_study', 'preparing', 'sent', 'waiting'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'to_study', 'lock_version' => 0];

    protected function casts(): array
    {
        return ['reference' => 'array', 'personal_deadline_on' => 'immutable_date', 'status_changed_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpportunityApplicationItem::class)->orderBy('position')->orderBy('id');
    }
}
