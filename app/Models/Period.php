<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Period extends CampusReference
{
    protected array $immutableContext = ['institution_id', 'academic_year_id', 'parent_period_id'];

    protected $attributes = ['weight' => 1];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'weight' => 'decimal:4', 'archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['institution_id' => 'required|integer', 'academic_year_id' => 'required|integer',
            'code' => 'required|string|max:40', 'name' => 'required|string|max:255',
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on',
            'weight' => 'required|numeric|gt:0'];
    }

    protected function validateContext(): void
    {
        $year = $this->validateYear();
        if ($this->exists && $this->isDirty(['starts_on', 'ends_on'])) {
            $outside = self::query()->where('parent_period_id', $this->id)->where(fn ($query) => $query
                ->where('starts_on', '<', $this->starts_on)->orWhere('ends_on', '>', $this->ends_on))->exists();
            $this->ensure(! $outside, 'ends_on', 'Les sous-périodes existantes doivent rester dans leur période parente.');
        }
        $this->ensure($this->starts_on->gte($year->starts_on) && $this->ends_on->lte($year->ends_on), 'starts_on', 'La période doit rester dans les dates de son année.');
        if ($this->parent_period_id !== null) {
            $parent = self::query()->where('institution_id', $this->institution_id)
                ->where('academic_year_id', $this->academic_year_id)->find($this->parent_period_id);
            $this->ensure($parent !== null && $parent->archived_at === null, 'parent_period_id');
            $this->ensure($this->starts_on->gte($parent->starts_on) && $this->ends_on->lte($parent->ends_on), 'starts_on', 'La sous-période doit rester dans sa période parente.');
        }
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function parentPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'parent_period_id');
    }
}
