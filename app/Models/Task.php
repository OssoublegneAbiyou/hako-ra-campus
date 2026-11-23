<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tâche personnelle (propriétaire obligatoire) ou devoir collectif publié pour un groupe et une matière.
 * L'avancement d'un devoir collectif ne vit jamais ici : il est propre à chaque apprenant (TaskProgress).
 */
class Task extends Model
{
    use HasUuids;

    public const STATUSES = ['todo' => 'À faire', 'in_progress' => 'En cours', 'done' => 'Terminé', 'abandoned' => 'Abandonné'];

    public const OPEN = ['todo', 'in_progress'];

    public const PRIORITIES = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute'];

    protected $guarded = ['id'];

    protected $attributes = ['scope' => 'personal', 'status' => 'todo', 'priority' => 'normal', 'lock_version' => 1];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime', 'personal_target_at' => 'immutable_datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function personalSubject(): BelongsTo
    {
        return $this->belongsTo(PersonalSubject::class, 'personal_subject_id');
    }
}
