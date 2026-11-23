<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverInvitation;
use App\Models\Invitation;
use App\Models\Membership;
use App\Services\ActiveContext;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function store(Request $request, ActiveContext $context, InvitationService $invitations): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:learner,teacher,institution_admin,catalogue_editor,platform_admin'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'local_identifier' => ['nullable', 'string', 'max:100'],
            'group_id' => ['nullable', 'integer'],
            'institution_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
        ]);
        $acting = $context->current($request);
        $result = $invitations->create($request->user(), $acting, $data);
        $invitation = $result['invitation'];
        if ($invitation->email !== null) {
            DeliverInvitation::dispatch($invitation->id, $request->user()->id, $acting->id, $result['token']);
        }

        return back()->with('oneTimeLink', [
            'url' => route('invitations.show', $result['token']),
            'role' => Membership::ROLE_LABELS[$invitation->role],
            'email' => $invitation->email,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function revoke(Request $request, ActiveContext $context, InvitationService $invitations, int $invitation): RedirectResponse
    {
        $acting = $context->require($request, 'institution_admin', 'platform_admin');
        $invitations->revoke($request->user(), $acting, Invitation::query()->findOrFail($invitation));

        return back()->with('status', 'Invitation révoquée : son lien ne fonctionne plus.');
    }

    public function review(Request $request, ActiveContext $context, InvitationService $invitations, int $invitation): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $acting = $context->require($request, 'institution_admin', 'platform_admin');
        $invitations->review($request->user(), $acting, Invitation::query()->findOrFail($invitation), $data['decision'] === 'approve');

        return back()->with('status', $data['decision'] === 'approve' ? 'Rattachement approuvé.' : 'Demande de rattachement refusée.');
    }
}
