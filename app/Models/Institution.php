<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends CampusReference
{
    protected array $immutableContext = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['code' => 'required|string|max:40', 'name' => 'required|string|max:255',
            'country_code' => 'required|string|size:2', 'timezone' => 'required|timezone'];
    }

    protected $attributes = ['country_code' => 'CI', 'timezone' => 'Africa/Abidjan'];

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class, 'institution_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'institution_id');
    }
}
