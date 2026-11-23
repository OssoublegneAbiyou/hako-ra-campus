<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Tâche personnelle : son propriétaire seul. Un devoir collectif ne passe pas par cette policy :
     * sa lecture dépend de l'inscription effective au groupe, sa gestion de l'affectation de l'enseignant.
     */
    public function view(User $user, Task $task): bool
    {
        return $task->scope === 'personal' && $task->owner_id === $user->id;
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
