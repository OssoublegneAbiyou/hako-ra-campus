<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'academic_year_id' => AcademicYear::factory(),
            'institution_id' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->institution_id,
            'scope' => 'institution', 'role' => 'learner', 'status' => 'active', 'starts_at' => now()->subDay(),
        ];
    }

    public function platform(string $role = 'catalogue_editor'): static
    {
        return $this->state(fn () => [
            'scope' => 'platform', 'institution_id' => null, 'academic_year_id' => null, 'role' => $role,
        ]);
    }
}
