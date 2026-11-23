<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\OpportunityApplicationItem;
use App\Models\OpportunityFavorite;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Favoris et démarches privées. Chaque lecture et chaque écriture part du compte connecté : aucune méthode ne reçoit
 * l'identifiant d'un favori ou d'une démarche d'autrui. Rien n'est journalisé et aucun événement ne porte de contenu.
 */
class OpportunityFollowUps
{
    public const MAX_ITEMS = 20;

    public function __construct(private Opportunities $opportunities) {}

    /**
     * Ajoute la fiche aux favoris. Rejouable et sûr en concurrence : deux demandes, deux appareils, un seul favori.
     */
    public function favorite(User $user, string $opportunityId): bool
    {
        $opportunity = $this->opportunities->published()->findOrFail($opportunityId);

        return DB::table('opportunity_favorites')->insertOrIgnore(['user_id' => $user->id, 'opportunity_id' => $opportunity->id, 'created_at' => now()]) === 1;
    }

    /**
     * Retire un favori. Rejouable : retirer un favori absent ne produit ni erreur ni information.
     */
    public function unfavorite(User $user, string $opportunityId): bool
    {
        return OpportunityFavorite::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->delete() > 0;
    }

    public function isFavorite(User $user, string $opportunityId): bool
    {
        return OpportunityFavorite::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->exists();
    }

    /**
     * Favoris du compte, classés : ouverts, bientôt clos, expirés, suspendus. Une fiche suspendue n'expose que son titre.
     *
     * @return array{open: list<array<string, mixed>>, soon: list<array<string, mixed>>, expired: list<array<string, mixed>>, suspended: list<array<string, mixed>>}
     */
    public function favorites(User $user): array
    {
        $groups = ['open' => [], 'soon' => [], 'expired' => [], 'suspended' => []];
        $soon = now()->addDays(max(1, (int) config('campus.opportunities.closing_soon_days')));
        $favorites = OpportunityFavorite::query()->where('user_id', $user->id)->orderByDesc('id')->limit(200)->pluck('opportunity_id');
        $records = Opportunity::query()->whereIn('id', $favorites)->get()->sortBy(fn (Opportunity $opportunity) => [$opportunity->closes_at === null ? 1 : 0, $opportunity->closes_at?->getTimestamp(), $opportunity->title]);
        foreach ($records as $opportunity) {
            $base = ['id' => $opportunity->id, 'title' => $opportunity->title, 'organizer' => $opportunity->organizer, 'campaign' => $opportunity->campaign];
            if ($opportunity->status === 'suspended') {
                $groups['suspended'][] = $base;

                continue;
            }
            $deadline = $this->opportunities->deadline($opportunity);
            $row = [...$base, 'deadline' => $deadline];
            $closed = $opportunity->status !== 'published' || $deadline['state'] === 'closed';
            $groups[$closed ? 'expired' : ($opportunity->closes_at !== null && $opportunity->closes_at->lessThanOrEqualTo($soon) ? 'soon' : 'open')][] = $row;
        }

        return $groups;
    }

    public function application(User $user, string $opportunityId): ?OpportunityApplication
    {
        return OpportunityApplication::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->with('items')->first();
    }

    /**
     * Crée ou modifie la démarche du compte pour cette fiche. L'état est celui que la personne choisit, et rien d'autre ne le change.
     * expected_version vaut 0 à la création ; une version dépassée ne remplace rien.
     *
     * @param  array{expected_version: int, status: string, personal_deadline_on?: string|null, note?: string|null}  $data
     * @return array{application: OpportunityApplication, replayed: bool}
     */
    public function save(User $user, string $opportunityId, array $data): array
    {
        return DB::transaction(function () use ($user, $opportunityId, $data): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $application = OpportunityApplication::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->lockForUpdate()->first();
            // Une démarche se commence sur une fiche publiée ; elle se poursuit ensuite quoi qu'il arrive à la fiche.
            $opportunity = $application === null ? $this->opportunities->published()->findOrFail($opportunityId) : Opportunity::query()->findOrFail($opportunityId);
            $note = $this->text($data['note'] ?? null, 500);
            $content = ['status' => $data['status'], 'personal_deadline_on' => $data['personal_deadline_on'] ?? null, 'note' => $note];
            $expected = (int) $data['expected_version'];
            if ($application !== null && $expected !== $application->lock_version) {
                $same = $application->status === $content['status'] && $application->personal_deadline_on?->toDateString() === $content['personal_deadline_on'] && $application->note === $note;
                if ($expected < $application->lock_version && $same) {
                    return ['application' => $application, 'replayed' => true];
                }
                throw new ConflictHttpException('Votre démarche a été modifiée sur un autre appareil. Rechargez la page avant de l’enregistrer.');
            }
            if ($application === null) {
                abort_unless($expected === 0, 409, 'Cette démarche n’existe pas encore.');
                $application = new OpportunityApplication(['user_id' => $user->id, 'opportunity_id' => $opportunity->id, 'status_changed_at' => now(), 'reference' => $this->opportunities->reference($opportunity)]);
            }
            if ($application->status !== $content['status'] || ! $application->exists) {
                $application->status_changed_at = now();
            }
            // Tant que la fiche est publiée, le résumé de référence suit la fiche ; ensuite il reste tel qu'il était à sa dernière mise à jour.
            if ($opportunity->status === 'published') {
                $application->reference = $this->opportunities->reference($opportunity);
            }
            $application->fill([...$content, 'lock_version' => $application->lock_version + 1])->save();

            return ['application' => $application, 'replayed' => false];
        }, 3);
    }

    /**
     * Supprime la démarche et sa liste de pièces. Rejouable.
     */
    public function delete(User $user, string $opportunityId): bool
    {
        return DB::transaction(function () use ($user, $opportunityId): bool {
            $application = OpportunityApplication::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->lockForUpdate()->first();
            if ($application === null) {
                return false;
            }
            OpportunityApplicationItem::query()->where('opportunity_application_id', $application->id)->delete();

            return (bool) $application->delete();
        }, 3);
    }

    /**
     * Ajoute une ligne à la liste des pièces à préparer. Rejouable par clé client.
     */
    public function addItem(User $user, string $opportunityId, string $clientKey, string $label): OpportunityApplicationItem
    {
        return DB::transaction(function () use ($user, $opportunityId, $clientKey, $label): OpportunityApplicationItem {
            $application = OpportunityApplication::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->lockForUpdate()->firstOrFail();
            $items = OpportunityApplicationItem::query()->where('opportunity_application_id', $application->id);
            if ($existing = (clone $items)->where('client_key', $clientKey)->first()) {
                return $existing;
            }
            $label = (string) $this->text($label, 160);
            if ($label === '') {
                throw ValidationException::withMessages(['label' => 'Indiquez la pièce à préparer.']);
            }
            if ((clone $items)->count() >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['label' => 'Cette liste compte déjà le nombre maximal de lignes ('.self::MAX_ITEMS.').']);
            }

            return OpportunityApplicationItem::create(['opportunity_application_id' => $application->id, 'client_key' => $clientKey, 'label' => $label, 'position' => ((int) (clone $items)->max('position')) + 1]);
        }, 3);
    }

    public function item(User $user, string $opportunityId, int $itemId): OpportunityApplicationItem
    {
        $application = OpportunityApplication::query()->where('user_id', $user->id)->where('opportunity_id', $opportunityId)->firstOrFail();

        return OpportunityApplicationItem::query()->where('opportunity_application_id', $application->id)->findOrFail($itemId);
    }

    /**
     * Démarches du compte, avec le résumé de référence et l'état actuel de la fiche.
     *
     * @return list<array<string, mixed>>
     */
    public function applications(User $user): array
    {
        $applications = OpportunityApplication::query()->where('user_id', $user->id)->with('items')->orderByDesc('updated_at')->limit(200)->get();
        $states = Opportunity::query()->whereIn('id', $applications->pluck('opportunity_id'))->pluck('status', 'id');

        return $applications->map(fn (OpportunityApplication $application): array => $this->present($application, $states[$application->opportunity_id] ?? 'archived'))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(OpportunityApplication $application, string $opportunityStatus): array
    {
        return ['opportunityId' => $application->opportunity_id, 'version' => $application->lock_version, 'status' => $application->status, 'statusLabel' => OpportunityApplication::STATUSES[$application->status],
            'statusChangedAt' => $application->status_changed_at->utc()->toIso8601String(), 'personalDeadlineOn' => $application->personal_deadline_on?->toDateString(), 'note' => $application->note,
            'reference' => $application->reference, 'opportunityStatus' => $opportunityStatus,
            'items' => $application->items->map(fn (OpportunityApplicationItem $item): array => ['id' => $item->id, 'label' => $item->label, 'done' => $item->done])->all()];
    }

    /**
     * Comptes qui suivent une fiche (favori, ou démarche encore en cours) : destinataires des alertes de changement de date et de suspension du lot 18.
     * Cette liste ne sort jamais vers un écran : elle ne sert qu'au moteur de rappels.
     *
     * @return Collection<int, int>
     */
    public function followers(string $opportunityId): Collection
    {
        return OpportunityFavorite::query()->where('opportunity_id', $opportunityId)->pluck('user_id')
            ->merge(OpportunityApplication::query()->where('opportunity_id', $opportunityId)->whereIn('status', OpportunityApplication::ACTIVE)->pluck('user_id'))
            ->unique()->sort()->values();
    }

    private function text(?string $value, int $max): ?string
    {
        $value = mb_substr(trim((string) preg_replace('/[^\P{C}\n]+/u', ' ', strip_tags((string) $value))), 0, $max);

        return $value === '' ? null : $value;
    }
}
