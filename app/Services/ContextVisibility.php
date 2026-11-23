<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class ContextVisibility
{
    private function assertActiveUser(User $actor): void
    {
        if (! User::query()->whereKey($actor->getKey())->where('status', 'active')->exists()) {
            throw new AuthorizationException('Accès interdit.');
        }
    }

    public function personalSpace(User $actor, int $spaceId): PersonalSpace
    {
        $this->assertActiveUser($actor);

        return PersonalSpace::query()->where('owner_id', $actor->getKey())->findOrFail($spaceId);
    }

    public function institutionMembership(User $actor, int $membershipId): Membership
    {
        $this->assertActiveUser($actor);

        return $actor->memberships()->effective()->where('scope', 'institution')
            ->whereHas('institution', fn (Builder $query) => $query->whereNull('archived_at'))
            ->whereHas('academicYear', fn (Builder $query) => $query->whereNull('archived_at'))
            ->findOrFail($membershipId);
    }

    /**
     * Appartenances réellement utilisables maintenant : effectives, et pour un établissement, non archivé.
     */
    public function usableMemberships(User $actor): Builder
    {
        $this->assertActiveUser($actor);

        return Membership::query()->where('user_id', $actor->getKey())->effective()
            ->where(fn (Builder $scope) => $scope->where('scope', 'platform')->orWhere(fn (Builder $institution) => $institution
                ->where('scope', 'institution')
                ->whereHas('institution', fn (Builder $query) => $query->whereNull('archived_at'))
                ->whereHas('academicYear', fn (Builder $query) => $query->whereNull('archived_at'))));
    }

    /**
     * Appartenance de l'acteur, établissement ou plateforme, relue en base à chaque appel.
     */
    public function membership(User $actor, int $membershipId): Membership
    {
        return $this->usableMemberships($actor)->findOrFail($membershipId);
    }

    public function groups(User $actor, int $membershipId): Builder
    {
        $membership = $this->institutionMembership($actor, $membershipId);
        $query = Group::query()->where('institution_id', $membership->institution_id)
            ->where('academic_year_id', $membership->academic_year_id)->whereNull('archived_at');

        return match ($membership->role) {
            'institution_admin' => $query,
            'learner' => $query->whereHas('enrollments', fn (Builder $enrollment) => $enrollment
                ->where('membership_id', $membership->id)->effective()),
            'teacher' => $query->whereHas('teachingAssignments', fn (Builder $assignment) => $assignment
                ->where('membership_id', $membership->id)->effective()
                ->whereHas('subject', fn (Builder $subject) => $subject->whereNull('archived_at'))),
            default => throw new AuthorizationException('Accès interdit.'),
        };
    }

    public function group(User $actor, int $membershipId, int $groupId): Group
    {
        return $this->groups($actor, $membershipId)->findOrFail($groupId);
    }

    public function teachingAssignment(User $actor, int $membershipId, int $assignmentId): TeachingAssignment
    {
        $membership = $this->institutionMembership($actor, $membershipId);
        if ($membership->role !== 'teacher') {
            throw new AuthorizationException('Accès interdit.');
        }

        return TeachingAssignment::query()->where('membership_id', $membership->id)->effective()
            ->whereHas('group', fn (Builder $query) => $query->whereNull('archived_at'))
            ->whereHas('subject', fn (Builder $query) => $query->whereNull('archived_at'))
            ->findOrFail($assignmentId);
    }

    /**
     * Appartenance d'administration de l'acteur ; tout autre rôle est refusé.
     */
    public function administeredMembership(User $actor, int $membershipId): Membership
    {
        $membership = $this->institutionMembership($actor, $membershipId);
        if ($membership->role !== 'institution_admin') {
            throw new AuthorizationException('Accès interdit.');
        }

        return $membership;
    }

    /**
     * Requête des comptes sans adresse électronique rattachés comme apprenants au périmètre de l'administrateur.
     */
    public function provisionedLearners(Membership $admin): Builder
    {
        return User::query()->whereNull('email')->whereHas('memberships', fn (Builder $membership) => $membership
            ->effective()->where('role', 'learner')->where('institution_id', $admin->institution_id)
            ->where('academic_year_id', $admin->academic_year_id));
    }

    public function provisionedLearner(Membership $admin, int $userId): User
    {
        return $this->provisionedLearners($admin)->findOrFail($userId);
    }

    public function canEditCatalogue(User $actor): bool
    {
        $this->assertActiveUser($actor);

        return $actor->memberships()->effective()->where('scope', 'platform')
            ->where('role', 'catalogue_editor')->exists();
    }
}
