<?php

namespace Database\Factories;

use App\Models\PersonalSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalSubject> */
class PersonalSubjectFactory extends Factory
{
    public function definition(): array
    {
        return ['owner_id' => User::factory(), 'code' => fake()->unique()->lexify('F-????????'), 'name' => 'Référence fictive'];
    }
}
