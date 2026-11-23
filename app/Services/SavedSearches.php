<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Models\User;
use App\Support\Labels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Recherches enregistrées et leurs synthèses. Une synthèse n'annonce que des fiches ouvertes, publiées ou mises à jour depuis
 * l'enregistrement (ou la reprise) de la recherche, et jamais deux fois la même révision : pas de rafale, pas de répétition.
 */
class SavedSearches
{
    public const MAX_PER_USER = 10;

    public const MAX_ITEMS_PER_DIGEST = 20;

    public function __construct(private OpportunitySearch $search, private Opportunities $opportunities, private NotificationStore $notifications) {}

    /**
     * Enregistre une recherche. Les filtres identiques d'un même compte ne s'enregistrent qu'une fois.
     *
     * @param  array<string, mixed>  $filters  filtres déjà validés par les règles du catalogue
     * @return array{search: SavedSearch, replayed: bool}
     */
    public function create(User $user, array $filters, ?string $name, string $frequency, bool $email, bool $push): array
    {
        $filters = $this->normalize($filters);
        if ($filters === []) {
            throw ValidationException::withMessages(['filters' => 'Choisissez au moins un filtre ou un mot recherché avant d’enregistrer : une alerte sur tout le catalogue n’est pas proposée.']);
        }
        $hash = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($user, $filters, $hash, $name, $frequency, $email, $push): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($existing = SavedSearch::query()->where('user_id', $user->id)->where('filters_hash', $hash)->first()) {
                return ['search' => $existing, 'replayed' => true];
            }
            if (SavedSearch::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
                throw ValidationException::withMessages(['filters' => 'Vous avez déjà '.self::MAX_PER_USER.' recherches enregistrées. Supprimez-en une pour en ajouter.']);
            }
            $name = $this->text($name, 100) ?? $this->describe($filters);

            return ['search' => SavedSearch::create(['user_id' => $user->id, 'name' => mb_substr($name, 0, 100), 'filters' => $filters, 'filters_hash' => $hash,
                'frequency' => $frequency, 'email' => $email, 'push' => $push, 'baseline_at' => now()]), 'replayed' => false];
        }, 3);
    }

    /**
     * Modifie le nom, la fréquence, les canaux, ou arrête et reprend les alertes. Arrêter ne touche ni aux favoris ni aux démarches.
     * À la reprise, seules les fiches publiées après la reprise seront annoncées.
     *
     * @param  array{expected_version: int, name?: string|null, frequency: string, email: bool, push: bool, active: bool}  $data
     */
    public function update(User $user, int $searchId, array $data): SavedSearch
    {
        return DB::transaction(function () use ($user, $searchId, $data): SavedSearch {
            $search = SavedSearch::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($searchId);
            $content = ['name' => $this->text($data['name'] ?? null, 100) ?? $search->name, 'frequency' => $data['frequency'], 'email' => (bool) $data['email'], 'push' => (bool) $data['push'], 'active' => (bool) $data['active']];
            $same = collect($content)->every(fn ($value, string $key) => $search->{$key} === $value);
            if ((int) $data['expected_version'] !== $search->lock_version) {
                if ($same && (int) $data['expected_version'] < $search->lock_version) {
                    return $search;
                }
                throw new ConflictHttpException('Cette recherche a été modifiée ailleurs. Rechargez la page avant de l’enregistrer.');
            }
            if ($same) {
                return $search;
            }
            if (! $search->active && $content['active']) {
                $search->baseline_at = now();
                $search->last_run_at = null;
            }
            $search->fill([...$content, 'lock_version' => $search->lock_version + 1])->save();
            if (! $search->active) {
                // Désabonnement effectif tout de suite : les synthèses encore programmées ou visibles de cette recherche sont retirées.
                $this->cancelDigests($search, 'unsubscribed');
            }

            return $search;
        }, 3);
    }

    /**
     * Supprime une recherche, l'historique de ses annonces et ses synthèses en attente. Rejouable.
     */
    public function delete(User $user, int $searchId): bool
    {
        return DB::transaction(function () use ($user, $searchId): bool {
            $search = SavedSearch::query()->where('user_id', $user->id)->lockForUpdate()->find($searchId);
            if ($search === null) {
                return false;
            }
            $this->cancelDigests($search, 'unsubscribed');
            SavedSearchMatch::query()->where('saved_search_id', $search->id)->delete();

            return (bool) $search->delete();
        }, 3);
    }

    /**
     * Calcule, pour chaque recherche active arrivée à échéance, les fiches à annoncer et crée au plus une synthèse par recherche.
     * Rejouable et sûr en concurrence : chaque recherche est verrouillée, chaque annonce a une clé unique.
     */
    public function planDigests(CarbonImmutable $now, int $limit = 500): int
    {
        $created = 0;
        $due = SavedSearch::query()->where('active', true)
            ->where(fn ($query) => $query->whereNull('last_run_at')
                ->orWhere(fn ($daily) => $daily->where('frequency', 'daily')->where('last_run_at', '<=', $now->subDay()))
                ->orWhere(fn ($weekly) => $weekly->where('frequency', 'weekly')->where('last_run_at', '<=', $now->subWeek())))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            $created += DB::transaction(fn (): int => $this->digest($id, $now), 3);
        }

        return $created;
    }

    private function digest(int $searchId, CarbonImmutable $now): int
    {
        $search = SavedSearch::query()->lockForUpdate()->find($searchId);
        $period = $search?->frequency === 'weekly' ? $now->subWeek() : $now->subDay();
        if ($search === null || ! $search->active || ($search->last_run_at !== null && $search->last_run_at->greaterThan($period))) {
            return 0;
        }
        if (! User::query()->whereKey($search->user_id)->where('status', 'active')->exists()) {
            return 0;
        }
        // Fiches ouvertes répondant aux filtres, publiées ou modifiées depuis l'enregistrement de la recherche. Une fiche suspendue,
        // archivée ou en brouillon ne peut pas sortir de cette requête.
        $candidates = $this->search->query([...$search->filters, 'cloture' => 'ouvertes'], $now)->where('updated_at', '>', $search->baseline_at)->limit(200)->get();
        $digestKey = 'search-digest:'.$search->id.':'.$now->getTimestamp();
        $items = [];
        foreach ($candidates as $opportunity) {
            if (count($items) >= self::MAX_ITEMS_PER_DIGEST) {
                break;
            }
            $item = $this->classify($search, $opportunity);
            if ($item !== null && DB::table('saved_search_matches')->insertOrIgnore(['saved_search_id' => $search->id, 'opportunity_id' => $opportunity->id, ...$item, 'digest_key' => $digestKey, 'created_at' => $now]) === 1) {
                $items[] = $item;
            }
        }
        $search->forceFill(['last_run_at' => $now])->save();
        if ($items === []) {
            return 0;
        }
        $new = count(array_filter($items, fn (array $item) => $item['kind'] === 'new'));
        $updated = count($items) - $new;
        $search->forceFill(['last_digest_at' => $now])->save();
        // Le titre, seul texte qui peut sortir par courriel, ne contient ni le nom de la recherche ni ses critères.
        $this->notifications->create($search->user_id, 'opportunity', 'search.digest', 'Du nouveau pour une de vos recherches enregistrées',
            '« '.$search->name.' » : '.$new.' nouvelle(s) fiche(s), '.$updated.' mise(s) à jour.', '/opportunites/alertes/'.$search->id, $digestKey, $now,
            $now->addHours((int) config('campus.notifications.event_ttl_hours')), null, null, ['saved_search_id' => $search->id, 'digest_key' => $digestKey, 'channels' => ['email' => $search->email, 'push' => $search->push]]);

        return 1;
    }

    /**
     * Révision pertinente d'une fiche pour une recherche : « nouvelle » si elle a été publiée après l'enregistrement de la recherche ;
     * « mise à jour » si sa date limite a changé depuis. Toute autre modification n'est pas annoncée.
     *
     * @return array{revision_key: string, kind: string, detail: string|null}|null
     */
    private function classify(SavedSearch $search, Opportunity $opportunity): ?array
    {
        if ($opportunity->published_at !== null && $opportunity->published_at->greaterThan($search->baseline_at)
            && ! SavedSearchMatch::query()->where('saved_search_id', $search->id)->where('opportunity_id', $opportunity->id)->exists()) {
            return ['revision_key' => 'new', 'kind' => 'new', 'detail' => null];
        }
        $changedAt = $opportunity->previous_deadline['changedAt'] ?? null;
        if ($changedAt !== null && CarbonImmutable::parse($changedAt)->greaterThan($search->baseline_at)) {
            return ['revision_key' => 'deadline:'.($opportunity->closes_at?->getTimestamp() ?? 'none'), 'kind' => 'updated', 'detail' => mb_substr('Date limite modifiée. Ancienne date : '.$opportunity->previous_deadline['text'], 0, 255)];
        }

        return null;
    }

    /**
     * Recherches du compte, avec leur état.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $counts = SavedSearchMatch::query()->whereIn('saved_search_id', SavedSearch::query()->where('user_id', $user->id)->select('id'))->selectRaw('saved_search_id, COUNT(*) as total')->groupBy('saved_search_id')->pluck('total', 'saved_search_id');

        return SavedSearch::query()->where('user_id', $user->id)->orderBy('id')->get()->map(fn (SavedSearch $search): array => [...$this->present($search), 'announced' => (int) ($counts[$search->id] ?? 0)])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SavedSearch $search): array
    {
        return ['id' => $search->id, 'version' => $search->lock_version, 'name' => $search->name, 'criteria' => $this->criteria($search->filters), 'query' => http_build_query(collect(SavedSearch::FILTERS)->filter(fn (string $key) => isset($search->filters[$key]))->mapWithKeys(fn (string $key) => [$key => $search->filters[$key]])->all()),
            'frequency' => $search->frequency, 'frequencyLabel' => SavedSearch::FREQUENCIES[$search->frequency], 'email' => $search->email, 'push' => $search->push, 'active' => $search->active,
            'since' => $search->baseline_at->utc()->toIso8601String(), 'lastRunAt' => $search->last_run_at?->utc()->toIso8601String(), 'lastDigestAt' => $search->last_digest_at?->utc()->toIso8601String()];
    }

    /**
     * Fiches annoncées pour une recherche du compte, les plus récentes d'abord. Une fiche qui n'est plus publiée n'y est montrée que par son titre et son état.
     *
     * @return list<array<string, mixed>>
     */
    public function announced(User $user, int $searchId, int $limit = 50): array
    {
        $search = SavedSearch::query()->where('user_id', $user->id)->findOrFail($searchId);

        return SavedSearchMatch::query()->where('saved_search_id', $search->id)->with('opportunity')->orderByDesc('id')->limit($limit)->get()->map(function (SavedSearchMatch $match): array {
            $opportunity = $match->opportunity;
            $live = $opportunity->status === 'published';

            return ['id' => $match->id, 'kind' => $match->kind, 'kindLabel' => $match->kind === 'new' ? 'Nouvelle fiche' : 'Mise à jour', 'detail' => $match->detail, 'announcedAt' => $match->created_at->utc()->toIso8601String(),
                'opportunityId' => $opportunity->id, 'title' => $opportunity->title, 'organizer' => $opportunity->organizer, 'availability' => $opportunity->status,
                // Lien vers la source seulement pour une fiche encore publiée : une fiche suspendue n'est jamais présentée comme une offre ouverte.
                'sourceUrl' => $live ? $opportunity->source_url : null, 'sourceHost' => $live ? parse_url($opportunity->source_url, PHP_URL_HOST) : null,
                'deadline' => $live ? $this->opportunities->deadline($opportunity, false)['text'] : null];
        })->all();
    }

    /**
     * La synthèse désignée par cette clé doit-elle encore être remise ? Oui si la recherche existe, est active, et qu'au moins une des fiches annoncées est toujours publiée.
     */
    public function digestStillValid(int $userId, ?int $searchId, ?string $digestKey): bool
    {
        if ($searchId === null || $digestKey === null || ! SavedSearch::query()->whereKey($searchId)->where('user_id', $userId)->where('active', true)->exists()) {
            return false;
        }

        return SavedSearchMatch::query()->where('saved_search_id', $searchId)->where('digest_key', $digestKey)->whereHas('opportunity', fn ($query) => $query->where('status', 'published'))->exists();
    }

    /**
     * Filtres retenus, nettoyés et ordonnés : mêmes critères, même empreinte.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public function normalize(array $filters): array
    {
        $kept = [];
        foreach (SavedSearch::FILTERS as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $kept[$key] = match ($key) {
                'q' => implode(' ', $this->search->terms($value)),
                'pays' => strtoupper($value),
                'langue' => strtolower($value),
                default => $value,
            };
            if ($kept[$key] === '') {
                unset($kept[$key]);
            }
        }

        return $kept;
    }

    /**
     * Critères en clair, pour expliquer pourquoi une fiche est annoncée.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function criteria(array $filters): array
    {
        $labels = ['q' => fn (string $v) => 'Mots : '.$v, 'categorie' => fn (string $v) => 'Catégorie : '.(Opportunity::CATEGORIES[$v] ?? $v), 'niveau' => fn (string $v) => 'Niveau : '.(Opportunity::LEVELS[$v] ?? $v),
            'pays' => fn (string $v) => 'Pays : '.Labels::country($v), 'domaine' => fn (string $v) => 'Domaine : '.$v, 'modalite' => fn (string $v) => 'Modalité : '.(Opportunity::MODALITIES[$v] ?? $v),
            'financement' => fn (string $v) => 'Financement : '.(Opportunity::FUNDING[$v] ?? $v), 'langue' => fn (string $v) => 'Langue : '.Labels::language($v)];

        // Ordre fixe des critères : MySQL ne conserve pas l'ordre des clés d'une colonne JSON.
        return collect(SavedSearch::FILTERS)->filter(fn (string $key) => isset($filters[$key]))->map(fn (string $key) => $labels[$key]($filters[$key]))->values()->all();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function describe(array $filters): string
    {
        return implode(' · ', $this->criteria($filters));
    }

    private function cancelDigests(SavedSearch $search, string $reason): void
    {
        $ids = DB::table('user_notifications')->where('user_id', $search->user_id)->where('type', 'search.digest')->whereIn('status', ['scheduled', 'delivered'])
            ->where('url', '/opportunites/alertes/'.$search->id)->lockForUpdate()->pluck('id')->all();
        $this->notifications->cancelIds($ids, $reason);
    }

    private function text(?string $value, int $max): ?string
    {
        $value = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', ' ', strip_tags((string) $value)))), 0, $max);

        return $value === '' ? null : $value;
    }
}
