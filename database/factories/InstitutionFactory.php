<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Institution> */
class InstitutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'FICTIF-'.fake()->unique()->lexify('????????'),
            'name' => 'Établissement fictif '.fake()->word(),
            'country_code' => 'CI',
            'timezone' => 'Africa/Abidjan',
        ];
    }
}
