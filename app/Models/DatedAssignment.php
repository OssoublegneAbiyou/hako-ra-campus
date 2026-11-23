<?php

namespace App\Models;

abstract class DatedAssignment extends CampusReference
{
    use Concerns\HasEffectiveWindow;

    protected array $immutableContext = ['membership_id', 'user_id', 'institution_id', 'academic_year_id', 'group_id', 'subject_id', 'membership_role'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['membership_id' => 'required|integer', 'user_id' => 'required|integer',
            'institution_id' => 'required|integer', 'academic_year_id' => 'required|integer',
            'group_id' => 'required|integer', 'status' => 'required|in:active,closed',
            'starts_at' => 'required|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at'];
    }

    protected function validateContext(): void
    {
        $this->validateYear();
        $membership = Membership::query()->where('user_id', $this->user_id)->where('scope', 'institution')
            ->where('institution_id', $this->institution_id)->where('academic_year_id', $this->academic_year_id)
            ->where('role', $this->membership_role)->find($this->membership_id);
        $this->ensure($membership !== null, 'membership_id');
        $this->ensure(Group::query()->when($this->status === 'active', fn ($query) => $query->whereNull('archived_at'))->where('institution_id', $this->institution_id)
            ->where('academic_year_id', $this->academic_year_id)->whereKey($this->group_id)->exists(), 'group_id');
        if ($this->status === 'active') {
            $this->ensure($membership->status === 'active' && $membership->revoked_at === null, 'membership_id');
            $this->ensure($this->starts_at->gte($membership->starts_at), 'starts_at');
            $this->ensure($membership->ends_at === null || $this->starts_at->lt($membership->ends_at), 'starts_at');
        }
        $this->ensure($this->status !== 'closed' || $this->closed_at !== null, 'closed_at');
    }

    public function close(): void
    {
        $this->forceFill(['status' => 'closed', 'closed_at' => now(), 'lock_version' => ($this->lock_version ?? 1) + 1,
            'ends_at' => $this->starts_at->lt(now()) ? now() : $this->ends_at])->save();
    }
}
