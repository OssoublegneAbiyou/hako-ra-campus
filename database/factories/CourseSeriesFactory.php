<?php

namespace Database\Factories;

use App\Models\CourseSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CourseSeries> */
class CourseSeriesFactory extends Factory
{
    public function definition(): array
    {
        return ['scope' => 'personal', 'owner_id' => User::factory(), 'created_by' => fn ($a) => $a['owner_id'], 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'fixture')];
    }
}
