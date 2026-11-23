<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

class InstitutionPolicy
{
    /**
     * Seule l'administration de la plateforme liste et crée les établissements.
     */
    public function viewAny(User $user, Membership $acting): bool
    {
        return $acting->user_id === $user->id && $acting->role === 'platform_admin';
    }

    public function create(User $user, Membership $acting): bool
    {
        return $this->viewAny($user, $acting);
    }
}
