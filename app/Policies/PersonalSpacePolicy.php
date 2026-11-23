<?php

namespace App\Policies;

use App\Models\PersonalSpace;
use App\Models\User;

class PersonalSpacePolicy
{
    /**
     * Espace privé : le titulaire seul. Aucun rôle d'établissement ou de plateforme n'y donne accès.
     */
    public function view(User $user, PersonalSpace $space): bool
    {
        return $space->owner_id === $user->id;
    }

    public function update(User $user, PersonalSpace $space): bool
    {
        return $this->view($user, $space);
    }
}
