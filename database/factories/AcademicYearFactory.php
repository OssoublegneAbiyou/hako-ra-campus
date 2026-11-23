<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicYear> */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'code' => fake()->unique()->lexify('AN-????????'),
            'name' => 'Année fictive 2026–2027',
            'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31',
        ];
    }
}
