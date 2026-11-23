<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends DatedAssignment
{
    protected $attributes = ['status' => 'active', 'membership_role' => 'learner'];

    protected function validateContext(): void
    {
        parent::validateContext();
        $this->ensure($this->membership_role === 'learner', 'membership_role');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}
