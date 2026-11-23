<?php

namespace App\Http\Controllers;

use App\Models\SavedSearch;
use App\Services\NotificationPreferences;
use App\Services\SavedSearches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recherches enregistrées et alertes du compte connecté. Aucune route ne désigne la recherche d'une autre personne :
 * l'identifiant est toujours recherché parmi les recherches du compte.
 */
class SavedSearchController extends Controller
{
    public function __construct(private SavedSearches $searches) {}

    public function index(Request $r, NotificationPreferences $preferences): Response
    {
        return Inertia::render('OpportunityAlerts', [
            'searches' => $this->searches->list($r->user()),
            'frequencies' => SavedSearch::FREQUENCIES,
            'channels' => ['email' => $preferences->channels()['email'], 'push' => $preferences->channels()['push'], 'category' => $preferences->category($r->user(), 'opportunity')],
            'limits' => ['searches' => SavedSearches::MAX_PER_USER, 'items' => SavedSearches::MAX_ITEMS_PER_DIGEST],
            'reminders' => ['first' => $preferences->for($r->user())->favorite_first_days, 'second' => $preferences->for($r->user())->favorite_second_days],
        ]);
    }

    public function show(Request $r, int $search): Response
    {
        $found = SavedSearch::query()->where('user_id', $r->user()->id)->findOrFail($search);

        return Inertia::render('OpportunityAlert', [
            'search' => $this->searches->present($found),
            'announced' => $this->searches->announced($r->user(), $found->id),
            'frequencies' => SavedSearch::FREQUENCIES,
        ]);
    }

    /**
     * Enregistre les filtres courants du catalogue. Ils sont validés par les mêmes règles que la recherche elle-même.
     */
    public function store(Request $r): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['name' => 'nullable|string|max:100', 'frequency' => 'required|in:'.implode(',', array_keys(SavedSearch::FREQUENCIES)), 'email' => 'required|boolean', 'push' => 'required|boolean',
            'filters' => 'required|array', ...collect(OpportunityController::filterRules())->only(SavedSearch::FILTERS)->mapWithKeys(fn ($rule, string $key) => ['filters.'.$key => $rule])->all()]);
        $result = $this->searches->create($r->user(), $data['filters'] ?? [], $data['name'] ?? null, $data['frequency'], (bool) $data['email'], (bool) $data['push']);
        if ($r->expectsJson()) {
            return response()->json(['id' => $result['search']->id, 'version' => $result['search']->lock_version, 'replayed' => $result['replayed']], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('opportunities.alerts')->with('status', $result['replayed'] ? 'Cette recherche était déjà enregistrée.' : 'Recherche enregistrée. Vous serez prévenu des nouvelles fiches, pas de celles qui existent déjà.');
    }

    public function update(Request $r, int $search): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', 'name' => 'nullable|string|max:100', 'frequency' => 'required|in:'.implode(',', array_keys(SavedSearch::FREQUENCIES)),
            'email' => 'required|boolean', 'push' => 'required|boolean', 'active' => 'required|boolean']);
        $updated = $this->searches->update($r->user(), $search, $data);
        if ($r->expectsJson()) {
            return response()->json(['id' => $updated->id, 'version' => $updated->lock_version, 'active' => $updated->active], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', $updated->active ? 'Alerte enregistrée.' : 'Alertes arrêtées pour cette recherche. Vos favoris et vos démarches sont conservés.');
    }

    public function destroy(Request $r, int $search): RedirectResponse|JsonResponse
    {
        $deleted = $this->searches->delete($r->user(), $search);
        if ($r->expectsJson()) {
            return response()->json(['deleted' => true, 'replayed' => ! $deleted], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('opportunities.alerts')->with('status', 'Recherche supprimée. Vos favoris et vos démarches sont conservés.');
    }
}
