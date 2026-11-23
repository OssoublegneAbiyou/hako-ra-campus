<?php

namespace App\Services;

use App\Models\CourseCheckIn;
use App\Models\CourseOccurrence;
use App\Models\Enrollment;
use App\Models\Membership;
use App\Models\PublishedOccurrence;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Instantané de lecture hors connexion du compte connecté : planning des 14 prochains jours, tâches ouvertes,
 * notes qu'il a le droit de lire, opportunités favorites. Les droits sont réévalués à chaque appel : une appartenance
 * retirée fait disparaître ses données du prochain instantané. Aucune donnée d'administration, aucun fichier, aucun jeton.
 */
class OfflineSnapshot
{
    public const WINDOW_DAYS = 14;

    public function __construct(private ContextVisibility $visibility, private Tasks $tasks, private Grades $grades, private OpportunityFollowUps $followUps, private CourseCheckIns $checkIns) {}

    /**
     * Identifiant opaque et stable du compte, pour que l'appareil sache si le compte a changé sans jamais exposer son numéro.
     */
    public static function accountKey(User $user): string
    {
        return substr(hash_hmac('sha256', 'offline-account:'.$user->id, (string) config('app.key')), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $now = now()->toImmutable();
        $timezone = $user->personalSpace?->timezone ?? config('app.display_timezone');

        return [
            'version' => 2,
            'accountKey' => self::accountKey($user),
            'displayName' => $user->name,
            'generatedAt' => $now->utc()->toIso8601String(),
            'timezone' => $timezone,
            'windowDays' => self::WINDOW_DAYS,
            'ttlDays' => max(1, (int) config('campus.offline.ttl_days')),
            'planning' => $this->planning($user, $now, $timezone),
            'tasks' => $this->tasks->offlineRows($user, 100, $timezone),
            'checkIns' => $this->pendingCheckIns($user),
            'labels' => ['statuses' => Task::STATUSES, 'priorities' => Task::PRIORITIES, 'understanding' => CourseCheckIn::UNDERSTANDING_LABELS],
            'grades' => $this->gradeViews($user),
            'opportunities' => $this->opportunities($user),
        ];
    }

    /**
     * Cours des 14 prochains jours visibles par le compte : ses événements personnels, les cours publiés de ses groupes, et ceux qu'il enseigne.
     *
     * @return list<array<string, mixed>>
     */
    private function planning(User $user, CarbonImmutable $now, string $timezone): array
    {
        $from = $now->setTimezone($timezone)->startOfDay()->utc();

        return $this->courses($user, $from, $from->addDays(self::WINDOW_DAYS));
    }

    /**
     * Cours visibles par le compte entre deux instants, toutes sources confondues, chacun avec sa provenance. Sert aussi à l'accueil (cours du jour).
     *
     * @return list<array<string, mixed>>
     */
    public function courses(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $memberships = $this->visibility->usableMemberships($user)->where('scope', 'institution')->get(['id', 'role']);
        $learner = $memberships->where('role', 'learner')->pluck('id');
        $teacher = $memberships->where('role', 'teacher')->pluck('id');
        $groups = Enrollment::query()->effective()->where('user_id', $user->id)->whereIn('membership_id', $learner)->pluck('group_id');
        $row = fn (string $id, string $title, $startsAt, $endsAt, string $status, string $provenance, bool $ownCourse): array => ['id' => $id, 'title' => $title, 'startsAt' => $startsAt->utc()->toIso8601String(),
            'endsAt' => $endsAt->utc()->toIso8601String(), 'cancelled' => $status === 'cancelled', 'provenance' => $provenance, 'checkIn' => $ownCourse ? ['version' => 0, 'status' => null] : null];
        $personal = CourseOccurrence::query()->where('scope', 'personal')->where('owner_id', $user->id)->whereIn('status', ['active', 'cancelled'])
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->limit(300)->get(['id', 'title', 'starts_at', 'ends_at', 'status'])
            ->map(fn (CourseOccurrence $occurrence) => $row($occurrence->id, $occurrence->title, $occurrence->starts_at, $occurrence->ends_at, $occurrence->status, 'personal', true));
        // Uniquement la projection publiée : jamais un brouillon de planning.
        $published = PublishedOccurrence::query()->whereIn('status', ['active', 'cancelled'])->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->where(fn ($query) => $query->whereIn('teacher_membership_id', $teacher)->orWhereHas('groups', fn ($group) => $group->whereIn('groups.id', $groups)))
            ->limit(300)->get(['course_occurrence_id', 'title', 'starts_at', 'ends_at', 'status']);
        // Un retour privé ne s'ouvre que sur un cours suivi comme apprenant, jamais sur un cours enseigné.
        $followed = PublishedOccurrence::query()->whereIn('course_occurrence_id', $published->pluck('course_occurrence_id'))
            ->whereHas('groups', fn ($group) => $group->whereIn('groups.id', $groups))->pluck('course_occurrence_id')->flip();
        $rows = $personal->concat($published->map(fn (PublishedOccurrence $occurrence) => $row($occurrence->course_occurrence_id, $occurrence->title, $occurrence->starts_at,
            $occurrence->ends_at, $occurrence->status, 'institutional', isset($followed[$occurrence->course_occurrence_id]))));
        // Seuls l'état et la version du retour sont copiés, jamais son texte : ils suffisent à savoir si un brouillon peut être écrit.
        $known = CourseCheckIn::query()->where('owner_id', $user->id)->whereIn('course_occurrence_id', $rows->pluck('id'))->get(['course_occurrence_id', 'lock_version', 'status'])->keyBy('course_occurrence_id');

        return $rows->map(function (array $course) use ($known): array {
            $checkIn = $known->get($course['id']);
            if ($course['checkIn'] !== null && $checkIn !== null) {
                $course['checkIn'] = ['version' => $checkIn->lock_version, 'status' => $checkIn->status];
            }

            return $course;
        })->sortBy('startsAt')->values()->all();
    }

    /**
     * Cours terminés récemment et encore sans retour : ceux pour lesquels un brouillon de retour peut être écrit hors connexion.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingCheckIns(User $user): array
    {
        $pending = $this->checkIns->pending($user);
        $versions = CourseCheckIn::query()->where('owner_id', $user->id)->whereIn('course_occurrence_id', $pending->pluck('occurrenceId'))->pluck('lock_version', 'course_occurrence_id');

        return $pending->map(fn (array $course): array => ['id' => $course['occurrenceId'], 'title' => $course['title'], 'startsAt' => $course['startsAt'], 'endsAt' => $course['endsAt'],
            'provenance' => $course['provenance'], 'version' => (int) ($versions[$course['occurrenceId']] ?? 0)])->all();
    }

    /**
     * Notes que le compte peut lire maintenant : ses notes personnelles de la période en cours, et les résultats publiés par chacun de ses établissements.
     *
     * @return list<array<string, mixed>>
     */
    private function gradeViews(User $user): array
    {
        $pick = fn (array $periods): ?array => collect($periods)->firstWhere('current', true) ?? ($periods[0] ?? null);
        $compact = fn (string $label, ?string $period, array $summary): array => ['label' => $label, 'period' => $period, 'general' => $summary['general']['average'],
            'subjects' => collect($summary['subjects'])->filter(fn (array $subject) => $subject['grades'] !== [])->map(fn (array $subject): array => ['name' => $subject['name'], 'average' => $subject['average'],
                'grades' => array_map(fn (array $grade): array => ['title' => $grade['title'], 'value' => $grade['value'], 'scale' => $grade['scale'], 'status' => $grade['status']], $subject['grades'])])->values()->all()];
        $views = [];
        $period = $pick($this->grades->personalPeriods($user));
        if ($period !== null) {
            $views[] = $compact('Mes notes personnelles', $period['label'], $this->grades->personalSummary($user, $period['value'] === 'aucune' ? null : (int) $period['value']));
        }
        $this->grades->learnerMemberships($user)->each(function (Membership $membership) use ($user, $pick, $compact, &$views): void {
            $period = $pick($this->grades->publishedPeriods($user, $membership));
            if ($period !== null) {
                $views[] = $compact('Résultats publiés — '.$membership->institution->name, $period['label'], $this->grades->publishedSummary($user, $membership, (int) $period['value']));
            }
        });

        return array_values(array_filter($views, fn (array $view) => $view['subjects'] !== []));
    }

    /**
     * Opportunités favorites. Une fiche suspendue n'emporte que son titre.
     *
     * @return list<array<string, mixed>>
     */
    private function opportunities(User $user): array
    {
        $groups = $this->followUps->favorites($user);
        $flat = fn (string $state): Collection => collect($groups[$state])->map(fn (array $favorite): array => ['id' => $favorite['id'], 'title' => $favorite['title'], 'organizer' => $favorite['organizer'],
            'state' => $state, 'deadline' => $favorite['deadline']['text'] ?? null]);

        return $flat('soon')->concat($flat('open'))->concat($flat('suspended'))->concat($flat('expired'))->values()->all();
    }
}
