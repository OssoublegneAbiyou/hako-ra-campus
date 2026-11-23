<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvitationPolicy
{
    /**
     * L'administration d'établissement invite apprenants et enseignants de son périmètre ;
     * seule la plateforme habilite une administration d'établissement ou un éditeur.
     */
    public function create(User $user, Membership $acting, string $role): Response
    {
        $allowed = $acting->user_id === $user->id && match ($acting->role) {
            'institution_admin' => in_array($role, ['learner', 'teacher'], true),
            'platform_admin' => in_array($role, ['institution_admin', 'catalogue_editor'], true),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny('Ce rôle ne peut pas être accordé depuis ce contexte.');
    }

    /**
     * Une invitation hors du périmètre de l'acteur est introuvable, sans indice sur son existence.
     */
    public function manage(User $user, Invitation $invitation, Membership $acting): Response
    {
        $covers = $acting->user_id === $user->id && match ($acting->role) {
            'institution_admin' => $invitation->scope === 'institution'
                && in_array($invitation->role, ['learner', 'teacher'], true)
                && $invitation->institution_id === $acting->institution_id
                && $invitation->academic_year_id === $acting->academic_year_id,
            'platform_admin' => in_array($invitation->role, ['institution_admin', 'catalogue_editor'], true),
            default => false,
        };

        return $covers ? Response::allow() : Response::denyAsNotFound();
    }
}
