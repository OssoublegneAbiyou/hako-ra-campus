<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Period;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Period> */
class PeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'institution_id' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->institution_id,
            'code' => fake()->unique()->lexify('PER-????????'),
            'name' => 'Période fictive',
            'starts_on' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->starts_on,
            'ends_on' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->ends_on,
            'weight' => 1,
        ];
    }
}
