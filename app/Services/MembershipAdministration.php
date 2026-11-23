<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MembershipAdministration
{
    /**
     * Retrait par une autorité habilitée, effectif dès la requête suivante. Le compte et l'espace individuel restent.
     */
    public function revoke(User $actor, Membership $acting, Membership $target): void
    {
        Gate::forUser($actor)->authorize('revoke', [$target, $acting]);
        $this->close($actor, $target, 'membership.revoked');
    }

    /**
     * Départ volontaire du titulaire.
     */
    public function leave(User $user, Membership $target): void
    {
        Gate::forUser($user)->authorize('leave', $target);
        $this->close($user, $target, 'membership.left');
    }

    private function close(User $actor, Membership $target, string $action): void
    {
        DB::transaction(function () use ($actor, $target, $action): void {
            $locked = Membership::query()->lockForUpdate()->findOrFail($target->id);
            if ($locked->status === 'closed') {
                return;
            }
            $locked->enrollments()->where('status', 'active')->get()->each->close();
            $locked->teachingAssignments()->where('status', 'active')->get()->each->close();
            $locked->revoke();
            // Retrait tracé hors de la base, une fois validé : il sera appliqué de nouveau si une sauvegarde antérieure est restaurée.
            DB::afterCommit(fn () => app(ErasureJournal::class)->record('membership.revoked', $locked->user_id, $locked->id));
            AuditLog::record($actor, $action, $locked, $locked->institution_id, null, [
                'role' => $locked->role, 'privileged' => in_array($locked->role, Invitation::PRIVILEGED_ROLES, true),
            ]);
        });
    }
}
