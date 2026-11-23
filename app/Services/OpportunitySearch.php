<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Support\Labels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Recherche et filtres du catalogue public, entièrement en base : aucun moteur externe.
 * Seules des fiches publiées, ou publiées puis archivées à leur clôture, peuvent en sortir ; jamais un brouillon ni une fiche suspendue.
 */
class OpportunitySearch
{
    public const SORTS = ['cloture' => 'Clôture la plus proche', 'publication' => 'Publication la plus récente', 'pertinence' => 'Pertinence'];

    public const CLOSINGS = ['ouvertes' => 'Ouvertes', '30j' => 'Clôture dans les 30 jours', 'sans_date' => 'Sans date limite connue', 'closes' => 'Clôturées', 'toutes' => 'Ouvertes et clôturées'];

    public const RELEVANCE = 'Pertinence : pour chaque mot recherché, 3 points s’il figure dans le titre, 2 dans le nom de l’organisateur, 1 dans un domaine. Les fiches sont classées par total décroissant, puis par clôture la plus proche.';

    public const MAX_TERMS = 5;

    public function __construct(private Opportunities $opportunities) {}

    /**
     * @param  array<string, mixed>  $filters  paramètres déjà validés
     */
    public function query(array $filters, ?CarbonImmutable $now = null): Builder
    {
        $now ??= now()->toImmutable();
        $closedArchive = fn (Builder $query) => $query->where('status', 'archived')->whereNotNull('published_at');
        $query = match ($filters['cloture'] ?? 'ouvertes') {
            '30j' => $this->opportunities->open($now)->whereNotNull('closes_at')->where('closes_at', '<=', $now->addDays(30)),
            // Clôture continue ou date non communiquée : ces fiches n'ont aucune borne connue.
            'sans_date' => $this->opportunities->published()->whereNull('closes_at'),
            'closes' => Opportunity::query()->where(fn (Builder $closed) => $closed
                ->where(fn (Builder $live) => $live->where('status', 'published')->whereNotNull('closes_at')->where('closes_at', '<=', $now))->orWhere($closedArchive)),
            'toutes' => Opportunity::query()->where(fn (Builder $any) => $any->where('status', 'published')->orWhere($closedArchive)),
            default => $this->opportunities->open($now),
        };
        $query->when(isset($filters['categorie']), fn (Builder $q) => $q->where('category', $filters['categorie']))
            ->when(isset($filters['modalite']), fn (Builder $q) => $q->where('modality', $filters['modalite']))
            ->when(isset($filters['financement']), fn (Builder $q) => $q->where('funding', $filters['financement']))
            // Une fiche « tous niveaux » répond à n'importe quel niveau demandé.
            ->when(isset($filters['niveau']), fn (Builder $q) => $q->where(fn (Builder $level) => $level->whereJsonContains('levels', $filters['niveau'])->orWhereJsonContains('levels', 'any')))
            ->when(isset($filters['pays']), fn (Builder $q) => $q->whereJsonContains('countries', strtoupper($filters['pays'])))
            ->when(isset($filters['langue']), fn (Builder $q) => $q->whereJsonContains('languages', strtolower($filters['langue'])))
            // Un domaine sans aucune lettre ni chiffre ne désigne rien : il ne renvoie aucune fiche, plutôt que d'être ignoré en silence.
            ->when(isset($filters['domaine']), fn (Builder $q) => $this->normalize($filters['domaine']) === '' ? $q->whereRaw('1 = 0') : $q->where('normalized_fields', 'like', '%'.$this->normalize($filters['domaine']).'%'));

        $terms = $this->terms($filters['q'] ?? '');
        foreach ($terms as $term) {
            $query->where(fn (Builder $match) => $match->where('normalized_title', 'like', '%'.$term.'%')->orWhere('normalized_organizer', 'like', '%'.$term.'%')->orWhere('normalized_fields', 'like', '%'.$term.'%'));
        }
        $sort = $filters['tri'] ?? 'cloture';
        if ($sort === 'pertinence' && $terms !== []) {
            $score = implode(' + ', array_fill(0, count($terms), '(CASE WHEN normalized_title LIKE ? THEN 3 ELSE 0 END + CASE WHEN normalized_organizer LIKE ? THEN 2 ELSE 0 END + CASE WHEN normalized_fields LIKE ? THEN 1 ELSE 0 END)'));
            $query->orderByRaw('('.$score.') DESC', collect($terms)->flatMap(fn (string $term) => array_fill(0, 3, '%'.$term.'%'))->all());
        } elseif ($sort === 'publication') {
            $query->orderByDesc('published_at');
        }

        // Dans tous les cas : clôture connue la plus proche, puis fiches sans date, puis titre.
        return $query->orderByRaw('closes_at IS NULL')->orderBy('closes_at')->orderBy('title')->orderBy('id');
    }

    /**
     * Mots recherchés : minuscules sans accents, lettres et chiffres seulement, deux caractères au moins, cinq mots au plus.
     *
     * @return list<string>
     */
    public function terms(string $search): array
    {
        return array_slice(array_values(array_unique(array_filter(explode(' ', $this->normalize($search)), fn (string $term) => strlen($term) >= 2))), 0, self::MAX_TERMS);
    }

    /**
     * Valeurs proposées dans les filtres pays, langue et domaine : celles réellement présentes dans les fiches consultables.
     *
     * @return array{countries: list<array{value: string, label: string}>, languages: list<array{value: string, label: string}>, fields: list<string>}
     */
    public function facets(): array
    {
        $rows = Opportunity::query()->where(fn (Builder $any) => $any->where('status', 'published')->orWhere(fn (Builder $closed) => $closed->where('status', 'archived')->whereNotNull('published_at')))
            ->limit(2000)->get(['countries', 'languages', 'fields']);
        $distinct = fn (string $column) => $rows->flatMap(fn (Opportunity $row) => $row->{$column} ?? [])->unique()->sort()->values();

        return [
            'countries' => $distinct('countries')->map(fn (string $code) => ['value' => $code, 'label' => Labels::country($code)])->all(),
            'languages' => $distinct('languages')->map(fn (string $code) => ['value' => $code, 'label' => Labels::language($code)])->all(),
            'fields' => $distinct('fields')->all(),
        ];
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }
}
