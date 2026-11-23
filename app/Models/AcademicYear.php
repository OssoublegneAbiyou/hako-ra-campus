<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends CampusReference
{
    protected array $immutableContext = ['institution_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['institution_id' => 'required|integer', 'code' => 'required|string|max:40',
            'name' => 'required|string|max:255', 'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on'];
    }

    protected function validateContext(): void
    {
        $this->validateInstitution();
        if ($this->exists && $this->isDirty(['starts_on', 'ends_on'])) {
            $outside = $this->periods()->where(fn ($query) => $query
                ->where('starts_on', '<', $this->starts_on)->orWhere('ends_on', '>', $this->ends_on))->exists();
            $this->ensure(! $outside, 'ends_on', 'Les périodes existantes doivent rester dans les dates de leur année.');
        }
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'academic_year_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class, 'academic_year_id');
    }
}
