<?php

namespace App\Models;

class AcademicPeriodType extends CampusReference
{
    protected array $immutableContext = ['owner_id', 'institution_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['code' => 'required|string|max:40', 'name' => 'required|string|max:255'];
    }

    protected function validateContext(): void
    {
        $this->ensure(($this->institution_id === null) !== ($this->owner_id === null), 'owner_id');
    }
}
