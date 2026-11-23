<?php

namespace App\Http\Controllers;

use App\Models\OpportunityApplication;
use App\Services\OpportunityFollowUps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Favoris et démarches du compte connecté. Chaque route est résolue pour ce compte à partir de la fiche :
 * aucune adresse ne désigne le favori ou la démarche d'une autre personne.
 */
class OpportunityFollowUpController extends Controller
{
    public function __construct(private OpportunityFollowUps $followUps) {}

    public function index(Request $r): Response
    {
        return Inertia::render('OpportunityFollowUp', [
            'favorites' => $this->followUps->favorites($r->user()),
            'applications' => $this->followUps->applications($r->user()),
            'statuses' => OpportunityApplication::STATUSES,
            'closingSoonDays' => max(1, (int) config('campus.opportunities.closing_soon_days')),
            'maxItems' => OpportunityFollowUps::MAX_ITEMS,
        ]);
    }

    public function favorite(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        $created = $this->followUps->favorite($r->user(), $opportunity);

        return $this->answer($r, ['favorite' => true, 'replayed' => ! $created], 'Fiche ajoutée à vos favoris.');
    }

    public function unfavorite(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        $removed = $this->followUps->unfavorite($r->user(), $opportunity);

        return $this->answer($r, ['favorite' => false, 'replayed' => ! $removed], 'Fiche retirée de vos favoris.');
    }

    /**
     * Crée ou met à jour la démarche. L'état vient toujours de ce formulaire : aucune autre action ne le modifie.
     */
    public function save(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:0', 'status' => 'required|in:'.implode(',', array_keys(OpportunityApplication::STATUSES)),
            'personal_deadline_on' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:500']);
        $result = $this->followUps->save($r->user(), $opportunity, $data);

        return $this->answer($r, ['version' => $result['application']->lock_version, 'status' => $result['application']->status, 'replayed' => $result['replayed']], 'Démarche enregistrée. Elle n’est visible que par vous.');
    }

    public function destroy(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        $deleted = $this->followUps->delete($r->user(), $opportunity);

        return $this->answer($r, ['deleted' => true, 'replayed' => ! $deleted], 'Démarche supprimée, avec sa note et sa liste de pièces.');
    }

    public function storeItem(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['client_key' => 'required|uuid', 'label' => 'required|string|max:160']);
        $item = $this->followUps->addItem($r->user(), $opportunity, $data['client_key'], $data['label']);

        return $this->answer($r, ['id' => $item->id, 'done' => $item->done], 'Pièce ajoutée à votre liste.');
    }

    public function updateItem(Request $r, string $opportunity, int $item): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['done' => 'required|boolean']);
        $found = $this->followUps->item($r->user(), $opportunity, $item);
        $found->update(['done' => (bool) $data['done']]);

        return $this->answer($r, ['id' => $found->id, 'done' => $found->done], $found->done ? 'Pièce prête.' : 'Pièce à préparer.');
    }

    public function destroyItem(Request $r, string $opportunity, int $item): RedirectResponse|JsonResponse
    {
        $found = $this->followUps->item($r->user(), $opportunity, $item);
        $found->delete();

        return $this->answer($r, ['id' => $found->id, 'deleted' => true], 'Ligne supprimée.');
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function answer(Request $r, array $json, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json($json, 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', $message);
    }
}
