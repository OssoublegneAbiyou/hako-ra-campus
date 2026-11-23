<?php

namespace App\Http\Controllers;

use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\OpportunityProfile;
use App\Models\OpportunityReport;
use App\Models\User;
use App\Services\Opportunities;
use App\Services\OpportunityFollowUps;
use App\Services\OpportunityReports;
use App\Services\OpportunitySearch;
use App\Support\Labels;
use App\Support\OpportunityMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Catalogue public : recherche, filtres, détail. Consultable sans compte. Brouillons et fiches à vérifier sont introuvables ;
 * une fiche suspendue ou archivée après publication est servie dans une forme réduite et identifiée comme telle.
 * Ouvrir la source ou le lien de candidature ne passe par aucune route du serveur : rien n'est enregistré ni transmis.
 */
class OpportunityController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $r, Opportunities $opportunities, OpportunitySearch $search): Response
    {
        $filters = $r->validate(self::filterRules());
        $list = $search->query($filters)->paginate(self::PER_PAGE)->withQueryString();
        $profile = $this->profileOf($r->user());
        $terms = $search->terms($filters['q'] ?? '');

        return Inertia::render('Opportunities', [
            'opportunities' => $list->getCollection()->map(fn (Opportunity $opportunity): array => [
                ...collect($opportunities->present($opportunity))->only(['id', 'categoryLabel', 'title', 'organizer', 'campaign', 'countryLabels', 'modality', 'funding', 'fees', 'fields', 'deadline', 'verifiedAt', 'availability'])->all(),
                'match' => $profile ? collect(OpportunityMatcher::compare($opportunity, $profile))->only(['overall', 'overallLabel'])->all() : null,
            ])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl(), 'count' => $list->total()],
            'filters' => ['q' => $filters['q'] ?? '', 'categorie' => $filters['categorie'] ?? '', 'niveau' => $filters['niveau'] ?? '', 'pays' => strtoupper($filters['pays'] ?? ''), 'domaine' => $filters['domaine'] ?? '',
                'modalite' => $filters['modalite'] ?? '', 'financement' => $filters['financement'] ?? '', 'langue' => strtolower($filters['langue'] ?? ''), 'cloture' => $filters['cloture'] ?? 'ouvertes',
                // Le tri par pertinence n'a de sens qu'avec des mots recherchés : sans eux, le tri appliqué est la clôture la plus proche.
                'tri' => ($filters['tri'] ?? 'cloture') === 'pertinence' && $terms === [] ? 'cloture' : ($filters['tri'] ?? 'cloture')],
            'options' => ['categories' => Opportunity::CATEGORIES, 'levels' => Opportunity::LEVELS, 'modalities' => Opportunity::MODALITIES, 'funding' => Opportunity::FUNDING,
                'closings' => OpportunitySearch::CLOSINGS, 'sorts' => OpportunitySearch::SORTS, ...$search->facets()],
            'searchedTerms' => $terms, 'relevanceRule' => OpportunitySearch::RELEVANCE,
            'signedIn' => $this->member($r->user()), 'hasProfile' => $profile !== null, 'matchDisclaimer' => OpportunityMatcher::DISCLAIMER,
        ]);
    }

    public function show(Request $r, Opportunities $opportunities, OpportunityReports $reports, OpportunityFollowUps $followUps, string $opportunity): Response
    {
        $found = $opportunities->identified()->findOrFail($opportunity);
        $user = $r->user();
        $member = $this->member($user);
        $presented = $opportunities->present($found);
        if ($found->status === 'suspended') {
            // Fiche suspendue : seule son identité reste affichée. Ni contenu, ni lien, ni comparaison : rien n'invite à candidater.
            $presented = [...array_fill_keys(array_keys($presented), null), ...collect($presented)->only(['id', 'category', 'categoryLabel', 'title', 'organizer', 'campaign', 'availability'])->all(),
                'countries' => [], 'countryLabels' => [], 'levels' => [], 'fields' => [], 'languages' => [], 'languageLabels' => [], 'eligibleNationalities' => [], 'eligibleResidences' => []];
        } elseif ($found->status === 'archived') {
            // Fiche close et archivée : le contenu reste lisible pour mémoire, sans lien de candidature.
            $presented = [...$presented, 'applicationUrl' => null, 'applicationHost' => null];
        }
        $profile = $member ? $this->profileOf($user) : null;

        return Inertia::render('Opportunity', [
            'opportunity' => $presented,
            'reportReasons' => OpportunityReport::REASONS,
            'canReport' => $member && $found->status === 'published',
            'myReport' => $member ? $reports->mine($user, $found->id) : null,
            'signedIn' => $member, 'hasProfile' => $profile !== null,
            'match' => $profile && $found->status !== 'suspended' ? OpportunityMatcher::compare($found, $profile) : null,
            'matchDisclaimer' => OpportunityMatcher::DISCLAIMER,
            // Suivi personnel : favori et démarche du seul compte connecté.
            'favorite' => $member && $followUps->isFavorite($user, $found->id),
            'canFavorite' => $member && $found->status === 'published',
            'application' => $member && ($application = $followUps->application($user, $found->id)) ? $followUps->present($application, $found->status) : null,
            'applicationStatuses' => OpportunityApplication::STATUSES,
        ]);
    }

    public function report(Request $r, OpportunityReports $reports, string $opportunity): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['reason' => 'required|in:'.implode(',', array_keys(OpportunityReport::REASONS)), 'message' => 'nullable|string|max:500']);
        $report = $reports->submit($r->user(), $opportunity, $data['reason'], $data['message'] ?? null);
        if ($r->expectsJson()) {
            return response()->json(['status' => $report->status], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Signalement transmis à l’équipe éditoriale. Votre identité n’est pas publiée.');
    }

    /**
     * Critères déclarés par la personne connectée, pour elle seule.
     */
    public function profile(Request $r): Response
    {
        $profile = OpportunityProfile::query()->where('user_id', $r->user()->id)->first();

        return Inertia::render('OpportunityProfile', [
            'profile' => ['version' => $profile?->lock_version ?? 0, 'level' => $profile?->level ?? '', 'fields' => $profile?->fields ?? [], 'languages' => $profile?->languages ?? [],
                'residenceCountry' => $profile?->residence_country ?? '', 'nationality' => $profile?->nationality ?? ''],
            'levels' => collect(Opportunity::LEVELS)->except('any')->all(),
            'labels' => ['residenceCountry' => $profile?->residence_country ? Labels::country($profile->residence_country) : null, 'nationality' => $profile?->nationality ? Labels::country($profile->nationality) : null],
            'disclaimer' => OpportunityMatcher::DISCLAIMER,
        ]);
    }

    public function updateProfile(Request $r): RedirectResponse|JsonResponse
    {
        $data = $r->validate([
            'expected_version' => 'required|integer|min:0', 'level' => 'nullable|in:'.implode(',', array_keys(collect(Opportunity::LEVELS)->except('any')->all())),
            'fields' => 'present|array|max:10', 'fields.*' => 'string|max:60', 'languages' => 'present|array|max:10', 'languages.*' => ['string', 'regex:/^[A-Za-z]{2}$/'],
            // Pays de résidence et nationalité : deux informations distinctes, toutes deux facultatives.
            'residence_country' => ['nullable', 'regex:/^[A-Za-z]{2}$/'], 'nationality' => ['nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);
        $clean = fn (array $items, callable $map): array => array_values(array_unique(array_filter(array_map($map, $items), fn (string $item) => $item !== '')));
        $profile = DB::transaction(function () use ($r, $data, $clean): OpportunityProfile {
            User::query()->whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $profile = OpportunityProfile::query()->where('user_id', $r->user()->id)->lockForUpdate()->first() ?? new OpportunityProfile(['user_id' => $r->user()->id]);
            if ((int) $data['expected_version'] !== $profile->lock_version) {
                throw new ConflictHttpException('Vos critères ont été modifiés ailleurs. Rechargez la page avant de les enregistrer.');
            }
            $profile->fill([
                'level' => $data['level'] ?? null,
                'fields' => $clean($data['fields'], fn ($field) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', strip_tags((string) $field)))), 0, 60)),
                'languages' => $clean($data['languages'], fn ($code) => strtolower(trim((string) $code))),
                'residence_country' => isset($data['residence_country']) ? strtoupper($data['residence_country']) : null,
                'nationality' => isset($data['nationality']) ? strtoupper($data['nationality']) : null,
                'lock_version' => $profile->lock_version + 1,
            ])->save();

            return $profile;
        }, 3);
        if ($r->expectsJson()) {
            return response()->json(['version' => $profile->lock_version], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('opportunities.profile')->with('status', 'Vos critères sont enregistrés. Ils ne sont visibles que par vous.');
    }

    /**
     * Règles de validation des paramètres de recherche, partagées avec l'enregistrement d'une recherche.
     *
     * @return array<string, mixed>
     */
    public static function filterRules(): array
    {
        $in = fn (array $values): string => 'sometimes|in:'.implode(',', array_keys($values));

        return [
            'q' => 'sometimes|string|max:100', 'categorie' => $in(Opportunity::CATEGORIES), 'niveau' => $in(Opportunity::LEVELS), 'pays' => ['sometimes', 'regex:/^[A-Za-z]{2}$/'],
            'domaine' => 'sometimes|string|max:60', 'modalite' => $in(Opportunity::MODALITIES), 'financement' => $in(Opportunity::FUNDING), 'langue' => ['sometimes', 'regex:/^[A-Za-z]{2}$/'],
            'cloture' => $in(OpportunitySearch::CLOSINGS), 'tri' => $in(OpportunitySearch::SORTS), 'page' => 'sometimes|integer|min:1|max:1000',
        ];
    }

    private function member(?User $user): bool
    {
        return $user !== null && $user->status === 'active' && $user->hasVerifiedEmail();
    }

    /**
     * Critères déclarés du compte, ou null si la personne n'a rien déclaré (ou n'est pas connectée) : aucune comparaison n'est alors affichée.
     */
    private function profileOf(?User $user): ?OpportunityProfile
    {
        if (! $this->member($user)) {
            return null;
        }
        $profile = OpportunityProfile::query()->where('user_id', $user->id)->first();
        $declared = $profile !== null && ($profile->level !== null || ($profile->fields ?? []) !== [] || ($profile->languages ?? []) !== [] || $profile->residence_country !== null || $profile->nationality !== null);

        return $declared ? $profile : null;
    }
}
