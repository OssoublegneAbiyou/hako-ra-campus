<?php

namespace App\Services;

use App\Exceptions\InvalidInvitation;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    /**
     * Crée une invitation dans le périmètre de l'appartenance active de l'acteur.
     * Le périmètre vient de cette appartenance ; pour la plateforme, de l'établissement explicitement visé.
     *
     * @param  array{role: string, email?: string|null, local_identifier?: string|null, group_id?: int|null, institution_id?: int|null, academic_year_id?: int|null}  $data
     * @return array{invitation: Invitation, token: string}
     */
    public function create(User $actor, ?Membership $acting, array $data): array
    {
        $role = $data['role'];
        if ($acting === null || Gate::forUser($actor)->inspect('create', [Invitation::class, $acting, $role])->denied()) {
            if (in_array($role, Invitation::PRIVILEGED_ROLES, true)) {
                AuditLog::record($actor, 'privilege.escalation_denied', null, $acting?->institution_id, null, [
                    'requested_role' => $role, 'acting_role' => $acting?->role,
                ]);
            }
            throw new AuthorizationException('Ce rôle ne peut pas être accordé depuis ce contexte.');
        }

        $perimeter = $this->perimeter($acting, $role, $data);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $invitation = DB::transaction(function () use ($actor, $acting, $role, $data, $perimeter, $token): Invitation {
            $invitation = Invitation::create([
                ...$perimeter,
                'role' => $role,
                'email' => isset($data['email']) && $data['email'] !== '' ? Str::lower($data['email']) : null,
                'token_hash' => Invitation::hashToken($token),
                'status' => 'pending',
                'expires_at' => now()->addDays(max(1, (int) config('campus.invitations.ttl_days'))),
                'invited_by' => $actor->id,
                'inviter_membership_id' => $acting->id,
            ]);
            AuditLog::record($actor, 'invitation.created', $invitation, $invitation->institution_id, null, [
                'role' => $role, 'privileged' => in_array($role, Invitation::PRIVILEGED_ROLES, true),
            ]);

            return $invitation;
        });

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function revoke(User $actor, Membership $acting, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize('manage', [$invitation, $acting]);
        DB::transaction(function () use ($actor, $invitation): void {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! in_array($locked->status, ['pending', 'pending_review'], true)) {
                throw ValidationException::withMessages(['invitation' => 'Cette invitation a déjà été traitée.']);
            }
            $locked->update(['status' => 'revoked', 'decided_by' => $actor->id, 'decided_at' => now()]);
            AuditLog::record($actor, 'invitation.revoked', $locked, $locked->institution_id);
        });
    }

    public function preview(string $token): ?Invitation
    {
        return Invitation::query()->open()->where('token_hash', Invitation::hashToken($token))->first();
    }

    /**
     * Consomme l'invitation une seule fois, pour le compte connecté. Le rôle et le périmètre viennent
     * de l'invitation, jamais de la requête. Un rapprochement ambigu reste en attente de contrôle.
     *
     * @return array{state: 'accepted'|'pending_review', membership: Membership|null}
     */
    public function accept(User $user, string $token): array
    {
        return DB::transaction(function () use ($user, $token): array {
            $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first();
            if (! $invitation || ! $invitation->isOpen() || ! $this->perimeterIsOpen($invitation)) {
                throw new InvalidInvitation;
            }
            $this->ensureNoCurrentRole($invitation, $user);

            $reason = match (true) {
                $invitation->local_identifier !== null && $this->identifierTaken($invitation) => 'identifier_conflict',
                $invitation->email !== null && Str::lower((string) $user->email) !== $invitation->email => 'email_mismatch',
                default => null,
            };
            if ($reason !== null) {
                $invitation->update(['status' => 'pending_review', 'accepted_by' => $user->id, 'review_reason' => $reason]);
                AuditLog::record($user, 'invitation.pending_review', $invitation, $invitation->institution_id, $reason);

                return ['state' => 'pending_review', 'membership' => null];
            }

            return ['state' => 'accepted', 'membership' => $this->grant($invitation, $user, $user)];
        });
    }

    /**
     * Décision de l'autorité sur un rapprochement en attente.
     */
    public function review(User $actor, Membership $acting, Invitation $invitation, bool $approve): void
    {
        Gate::forUser($actor)->authorize('manage', [$invitation, $acting]);
        DB::transaction(function () use ($actor, $invitation, $approve): void {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ($locked->status !== 'pending_review') {
                throw ValidationException::withMessages(['invitation' => 'Cette invitation n’attend pas de décision.']);
            }
            if (! $approve) {
                $locked->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()]);
                AuditLog::record($actor, 'invitation.rejected', $locked, $locked->institution_id);

                return;
            }
            if (! $this->perimeterIsOpen($locked)) {
                throw ValidationException::withMessages(['invitation' => 'L’établissement ou l’année de cette invitation est archivé.']);
            }
            if ($locked->local_identifier !== null && $this->identifierTaken($locked)) {
                throw ValidationException::withMessages(['invitation' => 'Ce matricule est encore rattaché à un autre compte. Retirez d’abord l’autre appartenance ou refusez la demande.']);
            }
            $candidate = User::query()->where('status', 'active')->findOrFail($locked->accepted_by);
            $this->ensureNoCurrentRole($locked, $candidate);
            $locked->forceFill(['decided_by' => $actor->id, 'decided_at' => now()]);
            $this->grant($locked, $candidate, $actor);
        });
    }

    private function grant(Invitation $invitation, User $user, User $actor): Membership
    {
        try {
            $membership = Membership::create([
                'user_id' => $user->id, 'scope' => $invitation->scope, 'institution_id' => $invitation->institution_id,
                'academic_year_id' => $invitation->academic_year_id, 'role' => $invitation->role, 'status' => 'active',
                'local_identifier' => $invitation->local_identifier, 'starts_at' => now(),
            ]);
            if ($invitation->group_id !== null) {
                Enrollment::create([
                    'membership_id' => $membership->id, 'user_id' => $user->id, 'institution_id' => $invitation->institution_id,
                    'academic_year_id' => $invitation->academic_year_id, 'group_id' => $invitation->group_id, 'starts_at' => now(),
                ]);
            }
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['invitation' => 'Ce rattachement existe déjà.']);
            }
            throw $error;
        }
        $invitation->forceFill([
            'status' => 'accepted', 'accepted_by' => $user->id, 'accepted_at' => now(), 'membership_id' => $membership->id,
        ])->save();
        AuditLog::record($actor, 'membership.granted', $membership, $membership->institution_id, null, [
            'role' => $membership->role, 'privileged' => in_array($membership->role, Invitation::PRIVILEGED_ROLES, true),
        ]);

        return $membership;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{scope: string, institution_id: int|null, academic_year_id: int|null, group_id: int|null, local_identifier: string|null}
     */
    private function perimeter(Membership $acting, string $role, array $data): array
    {
        if ($role === 'catalogue_editor') {
            return ['scope' => 'platform', 'institution_id' => null, 'academic_year_id' => null, 'group_id' => null, 'local_identifier' => null];
        }
        if ($acting->role === 'platform_admin') {
            $year = AcademicYear::query()->whereNull('archived_at')->where('institution_id', $data['institution_id'] ?? 0)
                ->whereHas('institution', fn ($query) => $query->whereNull('archived_at'))->find($data['academic_year_id'] ?? 0);
            if (! $year) {
                throw ValidationException::withMessages(['academic_year_id' => 'Choisissez un établissement et une année non archivés.']);
            }

            return ['scope' => 'institution', 'institution_id' => $year->institution_id, 'academic_year_id' => $year->id, 'group_id' => null, 'local_identifier' => null];
        }

        $groupId = null;
        $identifier = null;
        if ($role === 'learner') {
            $identifier = isset($data['local_identifier']) && trim((string) $data['local_identifier']) !== '' ? trim((string) $data['local_identifier']) : null;
            if (! empty($data['group_id'])) {
                $groupId = Group::query()->whereNull('archived_at')->where('institution_id', $acting->institution_id)
                    ->where('academic_year_id', $acting->academic_year_id)->whereKey($data['group_id'])->value('id');
                if ($groupId === null) {
                    throw ValidationException::withMessages(['group_id' => 'Ce groupe n’appartient pas à votre établissement pour cette année.']);
                }
            }
        }

        return ['scope' => 'institution', 'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'group_id' => $groupId, 'local_identifier' => $identifier];
    }

    private function perimeterIsOpen(Invitation $invitation): bool
    {
        return $invitation->scope === 'platform' || AcademicYear::query()->whereNull('archived_at')
            ->whereHas('institution', fn ($query) => $query->whereNull('archived_at'))->whereKey($invitation->academic_year_id)->exists();
    }

    private function identifierTaken(Invitation $invitation): bool
    {
        return Membership::query()->where('institution_id', $invitation->institution_id)
            ->where('academic_year_id', $invitation->academic_year_id)
            ->where('current_local_identifier', $invitation->local_identifier)->exists();
    }

    private function ensureNoCurrentRole(Invitation $invitation, User $user): void
    {
        $exists = Membership::query()->where('user_id', $user->id)->where('scope', $invitation->scope)
            ->where('institution_id', $invitation->institution_id)->where('academic_year_id', $invitation->academic_year_id)
            ->where('role', $invitation->role)->where('status', '!=', 'closed')->exists();
        if ($exists) {
            throw ValidationException::withMessages(['invitation' => 'Vous avez déjà ce rôle dans ce périmètre. L’invitation n’a pas été utilisée.']);
        }
    }
}
