<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory()->state(['role' => 'learner']),
            'user_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->user_id,
            'institution_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->institution_id,
            'academic_year_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->academic_year_id,
            'group_id' => fn (array $attributes) => Group::factory()->create([
                'institution_id' => $attributes['institution_id'], 'academic_year_id' => $attributes['academic_year_id'],
            ])->id,
            'membership_role' => 'learner', 'status' => 'active', 'starts_at' => now(),
        ];
    }
}
