<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalSpace extends CampusReference
{
    protected array $immutableContext = ['owner_id'];

    protected $attributes = ['timezone' => 'Africa/Abidjan'];

    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }

    protected function validationRules(): array
    {
        return ['owner_id' => 'required|integer|exists:users,id', 'timezone' => 'required|timezone',
            'preferences' => 'nullable|array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
