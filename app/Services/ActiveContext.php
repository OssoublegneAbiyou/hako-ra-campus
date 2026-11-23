<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ActiveContext
{
    public const SESSION_KEY = 'campus.context_membership';

    public function __construct(private ContextVisibility $visibility) {}

    /**
     * Contexte actif relu en base à chaque requête : la valeur de session ne prouve aucun droit.
     * Une appartenance retirée, suspendue, expirée ou archivée ramène à l'espace individuel.
     */
    public function current(Request $request): ?Membership
    {
        if ($request->attributes->has(self::SESSION_KEY)) {
            return $request->attributes->get(self::SESSION_KEY);
        }
        $membership = $this->resolve($request);
        $request->attributes->set(self::SESSION_KEY, $membership);

        return $membership;
    }

    private function resolve(Request $request): ?Membership
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return null;
        }
        $id = $request->session()->get(self::SESSION_KEY);
        if (! is_int($id)) {
            return null;
        }
        try {
            return $this->visibility->membership($user, $id)->load(['institution:id,name', 'academicYear:id,name']);
        } catch (AuthorizationException|ModelNotFoundException) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
    }

    /**
     * @return Collection<int, Membership>
     */
    public function available(User $user): Collection
    {
        return $this->visibility->usableMemberships($user)->with(['institution:id,name', 'academicYear:id,name'])->orderBy('id')->get();
    }

    /**
     * Change de contexte ; une appartenance étrangère ou inutilisable est introuvable.
     */
    public function switchTo(Request $request, ?int $membershipId): ?Membership
    {
        $request->attributes->remove(self::SESSION_KEY);
        if ($membershipId === null) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        $membership = $this->visibility->membership($request->user(), $membershipId);
        $request->session()->put(self::SESSION_KEY, $membership->id);

        return $membership;
    }

    /**
     * Contexte actif exigé avec l'un des rôles donnés, sinon accès interdit.
     */
    public function require(Request $request, string ...$roles): Membership
    {
        $membership = $this->current($request);
        abort_unless($membership !== null && in_array($membership->role, $roles, true), 403, 'Ce contexte ne permet pas cette action.');

        return $membership;
    }

    public function label(?Membership $membership): string
    {
        return match (true) {
            $membership === null => 'Espace individuel',
            $membership->scope === 'platform' => 'Plateforme HAKO-RA Campus',
            default => (string) $membership->institution?->name,
        };
    }
}
