<?php

namespace App\Policies;

use App\Models\PersonalGrade;
use App\Models\User;

class PersonalGradePolicy
{
    /**
     * Note personnelle : le propriétaire seul. Ni l'enseignant, ni l'administration, ni la plateforme n'y accèdent.
     */
    public function view(User $user, PersonalGrade $grade): bool
    {
        return $grade->owner_id === $user->id;
    }

    public function update(User $user, PersonalGrade $grade): bool
    {
        return $this->view($user, $grade);
    }
}
