<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Group> */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'institution_id' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->institution_id,
            'code' => fake()->unique()->lexify('GR-????????'), 'name' => 'Groupe fictif',
        ];
    }
}
