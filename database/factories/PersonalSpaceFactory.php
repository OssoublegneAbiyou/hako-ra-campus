<?php

namespace Database\Factories;

use App\Models\PersonalSpace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalSpace> */
class PersonalSpaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(), 'timezone' => 'Africa/Abidjan',
            'preferences' => ['study_goal' => 'Objectif fictif privé'],
        ];
    }
}
