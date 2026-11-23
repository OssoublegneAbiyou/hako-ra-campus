<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\ContextVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Envoi du lien d'invitation. Chiffré en file, car il transporte le secret du lien.
 * Les droits de l'émetteur sont revérifiés à l'exécution, pas seulement à la mise en file.
 */
class DeliverInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $invitationId, public int $actorId, public int $actingMembershipId, public string $token) {}

    public function handle(ContextVisibility $visibility): void
    {
        $invitation = Invitation::query()->find($this->invitationId);
        $actor = User::query()->find($this->actorId);
        if (! $invitation || ! $actor || ! $invitation->isOpen() || $invitation->email === null
            || ! hash_equals($invitation->token_hash, Invitation::hashToken($this->token))) {
            return;
        }

        try {
            $acting = $visibility->membership($actor, $this->actingMembershipId);
        } catch (AuthorizationException|ModelNotFoundException) {
            $acting = null;
        }
        if (! $acting instanceof Membership || Gate::forUser($actor)->inspect('manage', [$invitation, $acting])->denied()) {
            AuditLog::record(null, 'invitation.delivery_refused', $invitation, $invitation->institution_id, 'sender_rights_lost');

            return;
        }

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification(
            route('invitations.show', $this->token),
            $invitation->scope === 'platform' ? 'Plateforme HAKO-RA Campus' : (string) $invitation->institution?->name,
            Membership::ROLE_LABELS[$invitation->role],
            $invitation->expires_at,
        ));
    }
}
