<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidInvitation;
use App\Models\Membership;
use App\Services\ActiveContext;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, InvitationService $invitations, string $token): Response
    {
        $invitation = $invitations->preview($token);
        $user = $request->user();
        if ($invitation && ! $user) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return Inertia::render('InvitationShow', [
            'token' => $token,
            'invalidMessage' => $invitation ? null : InvalidInvitation::MESSAGE,
            'invitation' => $invitation ? [
                'perimeter' => $invitation->scope === 'platform' ? 'Plateforme HAKO-RA Campus' : $invitation->institution->name,
                'role' => Membership::ROLE_LABELS[$invitation->role],
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ] : null,
            'account' => $user ? ['name' => $user->name, 'verified' => $user->hasVerifiedEmail()] : null,
            'registrationEnabled' => (bool) config('campus.identity.registration_enabled'),
        ])->toResponse($request)->setStatusCode($invitation ? 200 : 404);
    }

    /**
     * Rattache le compte connecté. Aucun rôle ni périmètre n'est lu dans la requête.
     */
    public function store(Request $request, InvitationService $invitations, ActiveContext $context, string $token): RedirectResponse
    {
        try {
            $result = $invitations->accept($request->user(), $token);
        } catch (InvalidInvitation) {
            return redirect()->route('invitations.show', $token);
        }
        if ($result['state'] === 'pending_review') {
            return redirect()->route('profile')->with('status', 'Votre demande de rattachement est en attente de contrôle par l’administration. Aucun accès n’est encore ouvert.');
        }
        $context->switchTo($request, $result['membership']->id);

        return redirect()->route('profile')->with('status', 'Rattachement effectué avec votre compte existant.');
    }
}
