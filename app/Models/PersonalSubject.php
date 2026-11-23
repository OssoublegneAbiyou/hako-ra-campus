<?php

namespace App\Models;

class PersonalSubject extends CampusReference
{
    protected array $immutableContext = ['owner_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['owner_id' => 'required|integer|exists:users,id', 'code' => 'required|string|max:40', 'name' => 'required|string|max:255'];
    }
}
