<?php

namespace App\Policies;

use App\Models\CourseCheckIn;
use App\Models\User;

class CourseCheckInPolicy
{
    /**
     * Retour privé : le propriétaire seul. Ni l'enseignant, ni l'administration, ni la plateforme n'y accèdent.
     */
    public function view(User $user, CourseCheckIn $checkIn): bool
    {
        return $checkIn->owner_id === $user->id;
    }

    public function update(User $user, CourseCheckIn $checkIn): bool
    {
        return $this->view($user, $checkIn);
    }
}
