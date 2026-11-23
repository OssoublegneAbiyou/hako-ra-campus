<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subject> */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'code' => fake()->unique()->lexify('MAT-????????'), 'name' => 'Matière fictive',
        ];
    }
}
