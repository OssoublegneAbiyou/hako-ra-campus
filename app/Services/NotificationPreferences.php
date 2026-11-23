<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\PersonalSpace;
use App\Models\User;
use App\Support\Vapid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Préférences de rappel : catégories, canaux, fuseau, plage silencieuse. La catégorie « compte et sécurité »
 * n'est pas désactivable et ignore la plage silencieuse ; toutes les autres sont facultatives.
 */
class NotificationPreferences
{
    /** Catégories, dans l'ordre d'affichage. Les canaux externes sont toujours éteints par défaut : ils exigent un choix. */
    public const CATEGORIES = [
        'account' => ['label' => 'Compte et sécurité', 'mandatory' => true, 'hint' => 'Toujours actif dans le centre de notifications. Les courriels de vérification et de mot de passe ne dépendent pas de ces réglages.'],
        'course_checkin' => ['label' => 'Retour après un cours', 'mandatory' => false, 'hint' => 'Rappel après la fin d’un cours sans retour renseigné.'],
        'schedule_change' => ['label' => 'Changement de planning', 'mandatory' => false, 'hint' => 'Une alerte regroupée par publication du planning.'],
        'task_due' => ['label' => 'Échéance d’une tâche', 'mandatory' => false, 'hint' => 'La veille et le jour même, à l’heure choisie.'],
        'homework' => ['label' => 'Devoirs du groupe', 'mandatory' => false, 'hint' => 'Devoir publié, modifié ou retiré par un enseignant.'],
        'grade' => ['label' => 'Résultats publiés', 'mandatory' => false, 'hint' => 'Le message ne contient jamais la note.'],
        'digest' => ['label' => 'Synthèse quotidienne', 'mandatory' => false, 'hint' => 'Facultative ; envoyée seulement s’il y a quelque chose à signaler.'],
        'opportunity' => ['label' => 'Opportunités et favoris', 'mandatory' => false, 'hint' => 'Synthèses de vos recherches enregistrées, échéance, suspension et changement de date des fiches que vous suivez.'],
    ];

    /** @var array<int, NotificationPreference> */
    private array $loaded = [];

    public function for(User|int $user): NotificationPreference
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->loaded[$id] ??= NotificationPreference::query()->where('user_id', $id)->first() ?? new NotificationPreference(['user_id' => $id]);
    }

    /**
     * Précharge les préférences d'une liste de comptes.
     *
     * @param  iterable<int>  $userIds
     */
    public function preload(iterable $userIds): void
    {
        $ids = collect($userIds)->unique()->reject(fn (int $id) => isset($this->loaded[$id]))->values();
        $found = NotificationPreference::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        foreach ($ids as $id) {
            $this->loaded[$id] = $found->get($id) ?? new NotificationPreference(['user_id' => $id]);
        }
    }

    public function forget(): void
    {
        $this->loaded = [];
    }

    public function timezone(User|int $user): string
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->for($id)->timezone ?? PersonalSpace::query()->where('owner_id', $id)->value('timezone') ?? config('app.display_timezone');
    }

    /**
     * Réglage effectif d'une catégorie : active dans le centre, et canaux externes choisis.
     *
     * @return array{enabled: bool, email: bool, push: bool}
     */
    public function category(User|int $user, string $category): array
    {
        $preference = $this->for($user);
        $stored = $preference->categories[$category] ?? [];
        $mandatory = self::CATEGORIES[$category]['mandatory'] ?? false;
        $enabled = $mandatory || ($category === 'digest' ? $preference->digest_enabled : ($stored['enabled'] ?? true));

        return ['enabled' => $enabled, 'email' => $enabled && ($stored['email'] ?? false), 'push' => $enabled && ($stored['push'] ?? false)];
    }

    /**
     * Fin de la plage silencieuse en cours pour ce compte, dans son fuseau ; null hors plage ou pour une catégorie obligatoire.
     */
    public function quietUntil(User|int $user, string $category, CarbonImmutable $at): ?CarbonImmutable
    {
        $preference = $this->for($user);
        if ((self::CATEGORIES[$category]['mandatory'] ?? false) || $preference->quiet_start === null || $preference->quiet_end === null) {
            return null;
        }
        $local = $at->setTimezone($this->timezone($user));
        $start = substr($preference->quiet_start, 0, 5);
        $end = substr($preference->quiet_end, 0, 5);
        $now = $local->format('H:i');
        $inside = $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
        if (! $inside) {
            return null;
        }
        $until = $local->setTimeFromTimeString($end);

        return ($until->lessThanOrEqualTo($local) ? $until->addDay() : $until)->utc();
    }

    /**
     * État des canaux externes pour l'écran des préférences : configuré ou non, jamais « vérifié » sans preuve d'envoi réel.
     *
     * @return array{email: bool, push: bool, pushPublicKey: string|null}
     */
    public function channels(): array
    {
        return ['email' => $this->emailConfigured(), 'push' => Vapid::configured(), 'pushPublicKey' => Vapid::configured() ? Vapid::publicKey() : null];
    }

    public function emailConfigured(): bool
    {
        return (bool) config('campus.notifications.email_enabled');
    }

    /**
     * @return Collection<string, array{label: string, mandatory: bool, hint: string}>
     */
    public function categories(): Collection
    {
        return collect(self::CATEGORIES);
    }
}
