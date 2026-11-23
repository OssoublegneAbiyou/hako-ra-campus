<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Services\GoogleSignIn;
use App\Services\MembershipAdministration;
use App\Services\SessionRegistry;
use App\Support\PrivilegedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request, SessionRegistry $sessions): Response
    {
        $user = $request->user();
        $privileged = PrivilegedAccess::available();

        return Inertia::render('Profile', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'loginIdentifier' => $user->login_identifier,
                'provisioned' => $user->isProvisioned(),
            ],
            'memberships' => $user->memberships()->effective()->with('institution:id,name')->get()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'role' => Membership::ROLE_LABELS[$membership->role],
                    'institution' => $membership->institution?->name,
                    'privileged' => in_array($membership->role, Invitation::PRIVILEGED_ROLES, true),
                    'accountsHref' => $membership->role === 'institution_admin' && $privileged
                        ? route('institution.accounts.index', $membership->id, absolute: false) : null,
                ])->all(),
            'privilegedBlocked' => $user->hasPrivilegedRole() && ! $privileged,
            'secondFactorEnabled' => $user->secondFactor?->isConfirmed() === true,
            'google' => ['enabled' => app(GoogleSignIn::class)->enabled(), 'linkedEmail' => $user->socialIdentities()->where('provider', 'google')->value('email'),
                'linked' => $user->socialIdentities()->where('provider', 'google')->exists()],
            // Fuseau de l'espace personnel : il fixe l'heure des échéances saisies et l'affichage du planning personnel.
            'timezone' => ['current' => $user->personalSpace?->timezone ?? config('app.display_timezone'),
                'confirmed' => isset(($user->personalSpace?->preferences ?? [])['timezone_confirmed_at']), 'options' => timezone_identifiers_list()],
            'sessionsSupported' => $sessions->supported(),
            'sessions' => $sessions->listFor($user, $request->session()->getId()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $request->user()->update(['name' => $data['name']]);

        return back()->with('status', 'Nom enregistré.');
    }

    /**
     * Fuseau horaire de l'espace personnel. Les instants déjà enregistrés ne changent pas : seul leur affichage suit le nouveau fuseau.
     */
    public function updateTimezone(Request $request): RedirectResponse
    {
        $data = $request->validate(['timezone' => ['required', 'string', 'timezone:all']]);
        $space = PersonalSpace::query()->where('owner_id', $request->user()->id)->firstOrFail();
        $space->update(['timezone' => $data['timezone'], 'preferences' => [...($space->preferences ?? []), 'timezone_confirmed_at' => now()->utc()->toIso8601String()]]);

        return back()->with('status', 'Fuseau horaire enregistré : '.$data['timezone'].'.');
    }

    /**
     * Départ volontaire d'une appartenance : l'accès se ferme, le compte et l'espace individuel restent.
     */
    public function leaveMembership(Request $request, MembershipAdministration $administration, int $membership): RedirectResponse
    {
        $administration->leave($request->user(), Membership::query()->findOrFail($membership));

        return redirect()->route('profile')->with('status', 'Vous avez quitté cette appartenance. Votre espace individuel est conservé.');
    }

    public function destroySession(Request $request, SessionRegistry $sessions, string $handle): RedirectResponse
    {
        abort_unless($sessions->revoke($request->user(), $handle, $request->session()->getId()), 404);

        return redirect()->route('profile')->with('status', 'Session fermée sur l’autre appareil.');
    }

    public function destroyOtherSessions(Request $request, SessionRegistry $sessions): RedirectResponse
    {
        $count = $sessions->revokeOthers($request->user(), $request->session()->getId());

        return redirect()->route('profile')->with('status', $count.' autre(s) session(s) fermée(s).');
    }
}
