<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeachingAssignment> */
class TeachingAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory()->state(['role' => 'teacher']),
            'user_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->user_id,
            'institution_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->institution_id,
            'academic_year_id' => fn (array $attributes) => Membership::findOrFail($attributes['membership_id'])->academic_year_id,
            'group_id' => fn (array $attributes) => Group::factory()->create([
                'institution_id' => $attributes['institution_id'], 'academic_year_id' => $attributes['academic_year_id'],
            ])->id,
            'subject_id' => fn (array $attributes) => Subject::factory()->create(['institution_id' => $attributes['institution_id']])->id,
            'membership_role' => 'teacher', 'status' => 'active', 'starts_at' => now(),
        ];
    }
}
