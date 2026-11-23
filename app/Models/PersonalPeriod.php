<?php

namespace App\Models;

class PersonalPeriod extends CampusReference
{
    protected array $immutableContext = ['owner_id', 'parent_period_id'];

    protected $attributes = ['weight' => 1, 'timezone' => 'Africa/Abidjan'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'archived_at' => 'immutable_datetime', 'weight' => 'decimal:4'];
    }

    protected function validationRules(): array
    {
        return ['owner_id' => 'required|integer|exists:users,id', 'code' => 'required|string|max:40', 'name' => 'required|string|max:255', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'weight' => 'required|numeric|gt:0', 'timezone' => 'required|timezone'];
    }
}
