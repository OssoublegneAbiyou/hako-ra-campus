<?php

namespace App\Policies;

use App\Models\OpportunityApplication;
use App\Models\User;

class OpportunityApplicationPolicy
{
    /**
     * Démarche privée : son auteur seul. Ni enseignant, ni établissement, ni éditeur, ni administrateur de la plateforme.
     */
    public function view(User $user, OpportunityApplication $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function update(User $user, OpportunityApplication $application): bool
    {
        return $this->view($user, $application);
    }
}
