<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PrivacyRequest;
use App\Services\RetentionPolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suivi des demandes de rectification et d'opposition par l'administration de la plateforme. Elle lit le texte que la personne lui a adressé,
 * jamais son espace individuel : il n'existe ici aucun accès aux données privées du demandeur.
 */
class PrivacyRequestController extends Controller
{
    public function index(Request $r, RetentionPolicies $policies): Response
    {
        Gate::authorize('manage-platform');
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $list = PrivacyRequest::query()->with('user:id,name,email,login_identifier,status')->orderByRaw("status IN ('received','in_progress') DESC")->orderBy('created_at')->paginate(20)->withQueryString();

        return Inertia::render('admin/PrivacyRequests', [
            'requests' => $list->getCollection()->map(fn (PrivacyRequest $request): array => [
                'id' => $request->id, 'typeLabel' => PrivacyRequest::TYPES[$request->type], 'type' => $request->type, 'status' => $request->status, 'statusLabel' => PrivacyRequest::STATUSES[$request->status],
                // Demandes automatiques (export, suppression) : seulement leur existence et leur date. Compte supprimé : plus d'identité à afficher.
                'requester' => $request->user->status === 'erased' ? 'Compte supprimé' : $request->user->name, 'contact' => $request->user->status === 'erased' ? null : ($request->user->email ?? $request->user->login_identifier),
                'message' => $request->message, 'response' => $request->response, 'createdAt' => $request->created_at->utc()->toIso8601String(), 'closedAt' => $request->closed_at?->utc()->toIso8601String(),
                'open' => in_array($request->status, PrivacyRequest::OPEN, true),
            ])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
            'durations' => $policies->durations(),
            // Revue d'inactivité : un nombre, pas une liste. Aucune suppression automatique.
            'inactiveAccounts' => $policies->inactiveAccounts(),
        ]);
    }

    public function handle(Request $r, string $request): RedirectResponse
    {
        Gate::authorize('manage-platform');
        $data = $r->validate(['status' => 'required|in:in_progress,completed,refused', 'response' => 'required_unless:status,in_progress|nullable|string|max:2000']);
        $found = PrivacyRequest::query()->whereIn('type', ['rectification', 'objection'])->whereIn('status', PrivacyRequest::OPEN)->findOrFail($request);
        $closing = $data['status'] !== 'in_progress';
        $found->update(['status' => $data['status'], 'response' => $data['response'] ?? $found->response, 'handled_by' => $r->user()->id, 'closed_at' => $closing ? now() : null]);
        AuditLog::record($r->user(), 'privacy.request.handled', null, null, null, ['type' => $found->type, 'status' => $found->status]);

        return back()->with('status', $closing ? 'Demande clôturée. La personne voit votre réponse dans son suivi.' : 'Demande marquée en cours de traitement.');
    }
}
