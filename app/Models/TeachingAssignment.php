<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignment extends DatedAssignment
{
    protected $attributes = ['status' => 'active', 'membership_role' => 'teacher'];

    protected function validateContext(): void
    {
        parent::validateContext();
        $this->ensure($this->membership_role === 'teacher', 'membership_role');
        $this->ensure(Subject::query()->when($this->status === 'active', fn ($query) => $query->whereNull('archived_at'))->where('institution_id', $this->institution_id)
            ->whereKey($this->subject_id)->exists(), 'subject_id');
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
