<?php

namespace Database\Factories;

use App\Models\PersonalPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalPeriod> */
class PersonalPeriodFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_id' => User::factory(), 'code' => fake()->unique()->lexify('F-????????'), 'name' => 'Référence fictive', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31'];
    }
}
