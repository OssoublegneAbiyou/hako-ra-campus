<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MembershipPolicy
{
    public function viewAny(User $user, Membership $acting): bool
    {
        return $acting->user_id === $user->id && $acting->role === 'institution_admin';
    }

    /**
     * Retrait par une autorité : même périmètre pour l'établissement, rôles habilités par elle pour la plateforme.
     * Hors périmètre, l'appartenance est introuvable. Son propre retrait passe par le départ volontaire.
     */
    public function revoke(User $user, Membership $target, Membership $acting): Response
    {
        $covers = $acting->user_id === $user->id && $target->user_id !== $user->id && $target->status !== 'closed' && match ($acting->role) {
            'institution_admin' => $target->scope === 'institution'
                && in_array($target->role, ['learner', 'teacher'], true)
                && $target->institution_id === $acting->institution_id
                && $target->academic_year_id === $acting->academic_year_id,
            'platform_admin' => in_array($target->role, ['institution_admin', 'catalogue_editor'], true),
            default => false,
        };

        return $covers ? Response::allow() : Response::denyAsNotFound();
    }

    public function leave(User $user, Membership $target): Response
    {
        return $target->user_id === $user->id && $target->status !== 'closed' ? Response::allow() : Response::denyAsNotFound();
    }
}
