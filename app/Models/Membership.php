<?php

namespace App\Models;

use App\Models\Concerns\HasEffectiveWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends CampusReference
{
    use HasEffectiveWindow {
        scopeEffective as private scopeEffectiveWindow;
    }

    public const INSTITUTION_ROLES = ['learner', 'teacher', 'institution_admin'];

    public const PLATFORM_ROLES = ['catalogue_editor', 'platform_admin'];

    public const ROLE_LABELS = [
        'learner' => 'Apprenant', 'teacher' => 'Enseignant', 'institution_admin' => 'Administration de l’établissement',
        'catalogue_editor' => 'Édition du catalogue', 'platform_admin' => 'Administration de la plateforme',
    ];

    protected array $immutableContext = ['user_id', 'scope', 'institution_id', 'academic_year_id', 'role'];

    protected $attributes = ['scope' => 'institution', 'role' => 'learner', 'status' => 'active'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime', 'suspended_at' => 'immutable_datetime'];
    }

    protected function validationRules(): array
    {
        return ['user_id' => 'required|integer|exists:users,id', 'scope' => 'required|in:institution,platform',
            'role' => 'required|in:learner,teacher,institution_admin,catalogue_editor,platform_admin',
            'status' => 'required|in:active,suspended,closed', 'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at', 'local_identifier' => 'nullable|string|max:100',
            'revoked_at' => 'nullable|date|before_or_equal:now', 'suspended_at' => 'nullable|date|before_or_equal:now'];
    }

    protected function validateContext(): void
    {
        if ($this->scope === 'institution') {
            $this->ensure(in_array($this->role, self::INSTITUTION_ROLES, true), 'role');
            $this->validateYear();
        } else {
            $this->ensure($this->institution_id === null && $this->academic_year_id === null && $this->local_identifier === null, 'scope');
            $this->ensure(in_array($this->role, self::PLATFORM_ROLES, true), 'role');
        }
        $this->ensure($this->status !== 'suspended' || $this->suspended_at !== null, 'suspended_at');
        $this->ensure($this->status !== 'closed' || $this->ends_at !== null || $this->revoked_at !== null, 'ends_at');
        $this->ensure($this->status !== 'active' || $this->revoked_at === null, 'revoked_at');
        $this->ensure($this->local_identifier === null || trim($this->local_identifier) !== '', 'local_identifier');
    }

    public function scopeEffective(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $this->scopeEffectiveWindow($query, $at)->whereNull('revoked_at');
    }

    public function revoke(): void
    {
        $this->forceFill(['status' => 'closed', 'revoked_at' => now()])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'membership_id');
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class, 'membership_id');
    }
}
