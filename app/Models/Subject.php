<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends CampusReference
{
    protected array $immutableContext = ['institution_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['institution_id' => 'required|integer', 'code' => 'required|string|max:40', 'name' => 'required|string|max:255'];
    }

    protected function validateContext(): void
    {
        $this->validateInstitution();
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class, 'subject_id');
    }
}
