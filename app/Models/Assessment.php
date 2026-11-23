<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Évaluation d'un groupe dans une matière et une période. Ses résultats ne sont visibles des apprenants qu'une fois publiée.
 */
class Assessment extends Model
{
    use HasUuids;

    public const STATUSES = ['draft' => 'Brouillon', 'published' => 'Publiée', 'withdrawn' => 'Retirée'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'coefficient' => 1, 'lock_version' => 1];

    protected function casts(): array
    {
        return ['assessed_on' => 'immutable_date', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(AssessmentResult::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
