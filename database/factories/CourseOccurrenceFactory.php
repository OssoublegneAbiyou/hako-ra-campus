<?php

namespace Database\Factories;

use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseOccurrence> */
class CourseOccurrenceFactory extends Factory
{
    public function definition(): array
    {
        return ['course_series_id' => CourseSeries::factory(), 'scope' => 'personal', 'owner_id' => fn ($a) => CourseSeries::findOrFail($a['course_series_id'])->owner_id, 'title' => 'Cours fictif', 'original_date' => '2026-11-21', 'starts_at' => '2026-11-21 09:00:00', 'ends_at' => '2026-11-21 10:00:00', 'timezone' => 'Africa/Abidjan', 'status' => 'active'];
    }
}
