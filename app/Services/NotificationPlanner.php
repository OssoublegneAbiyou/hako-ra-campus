<?php

namespace App\Services;

use App\Models\AssessmentResult;
use App\Models\CourseOccurrence;
use App\Models\DomainEvent;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\PublishedOccurrence;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Planification des rappels, entièrement côté serveur : consommation de la boîte d'événements métier (rattrapage après
 * interruption, chaque événement traité une fois) et rappels horaires calculés dans le fuseau de chaque personne.
 * Après une panne, seule la fenêtre de validité récente est reprise : aucun rappel ancien n'est créé en rafale.
 */
class NotificationPlanner
{
    /** @var array<string, list<int>> */
    private array $recipients = [];

    public function __construct(private NotificationStore $store, private NotificationPreferences $preferences, private ScheduleAccess $access,
        private Tasks $tasks, private CourseCheckIns $checkIns, private Opportunities $opportunities, private OpportunityFollowUps $followUps, private SavedSearches $savedSearches) {}

    /**
     * @return array{events: int, created: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= now()->toImmutable();
        $this->preferences->forget();
        $this->recipients = [];
        $events = $this->consumeEvents($now);
        $created = $events['created'] + $this->planCourseReminders($now) + $this->planTaskReminders($now) + $this->planDigests($now)
            + $this->planFavoriteReminders($now) + $this->savedSearches->planDigests($now);

        return ['events' => $events['events'], 'created' => $created];
    }

    /**
     * Traite les événements métier non encore traités, par lots verrouillés : deux exécutions simultanées ne prennent jamais le même.
     *
     * @return array{events: int, created: int}
     */
    public function consumeEvents(CarbonImmutable $now): array
    {
        $batch = max(1, (int) config('campus.notifications.batch'));
        $total = $created = 0;
        for ($round = 0; $round < 50; $round++) {
            $count = DB::transaction(function () use ($now, $batch, &$created): int {
                $events = DomainEvent::query()->whereNull('processed_at')->orderBy('id')->limit($batch)->lock('for update skip locked')->get();
                foreach ($events as $event) {
                    $created += $this->handle($event, $now);
                }
                DomainEvent::query()->whereIn('id', $events->pluck('id'))->update(['processed_at' => $now]);

                return $events->count();
            });
            $total += $count;
            if ($count < $batch) {
                break;
            }
        }

        return ['events' => $total, 'created' => $created];
    }

    private function handle(DomainEvent $event, CarbonImmutable $now): int
    {
        $payload = $event->payload;
        $expires = $event->occurred_at->addHours((int) config('campus.notifications.event_ttl_hours'));
        // Un événement trop ancien n'alerte plus personne ; ses annulations, elles, restent appliquées.
        $stale = $expires->lessThanOrEqualTo($now);
        $context = ['institution_id' => $payload['institution_id'] ?? $event->institution_id, 'academic_year_id' => $payload['academic_year_id'] ?? $event->academic_year_id];
        $created = 0;

        switch ($event->type) {
            case 'checkin.settled':
                $this->store->cancel((int) $payload['user_id'], ['course.checkin'], $payload['occurrence_id'], 'settled');
                break;
            case 'task.settled':
                $this->store->cancel((int) $payload['user_id'], ['task.due.d1', 'task.due.d0'], $payload['task_id'], 'settled');
                break;
            case 'schedule.occurrence.changed':
                if ($stale || ! ($payload['affects_future'] ?? false)) {
                    break;
                }
                $groups = array_values(array_unique([...($payload['before']['group_ids'] ?? []), ...($payload['after']['group_ids'] ?? [])]));
                $teachers = array_values(array_unique(array_filter([$payload['before']['teacher_membership_id'] ?? null, $payload['after']['teacher_membership_id'] ?? null])));
                // Une seule alerte par personne et par publication, quel que soit le nombre de cours modifiés.
                foreach ($this->recipients($context, $groups, $teachers) as $userId) {
                    $created += (int) $this->store->create($userId, 'schedule_change', 'schedule.changed', 'Votre planning a changé', 'Des cours de votre planning publié ont été ajoutés, déplacés ou annulés.',
                        '/planning?source=published', 'schedule-changed:'.$userId.':'.$payload['publication_id'], $event->occurred_at, $expires, null, null, $context);
                }
                break;
            case 'homework.published': case 'homework.updated': case 'homework.withdrawn': case 'homework.restored':
                if ($event->type === 'homework.withdrawn') {
                    $this->store->cancel(null, ['task.due.d1', 'task.due.d0'], $payload['task_id'], 'withdrawn');
                }
                if ($stale) {
                    break;
                }
                $title = ['homework.published' => 'Nouveau devoir pour votre groupe', 'homework.updated' => 'Un devoir de votre groupe a changé',
                    'homework.withdrawn' => 'Un devoir de votre groupe a été retiré', 'homework.restored' => 'Un devoir de votre groupe est de nouveau disponible'][$event->type];
                foreach ($this->recipients($context, [(int) $payload['group_id']], []) as $userId) {
                    $created += (int) $this->store->create($userId, 'homework', $event->type, $title, null, '/taches', $event->type.':'.$userId.':'.$payload['task_id'].':v'.$payload['version'],
                        $event->occurred_at, $expires, $payload['task_id'], null, [...$context, 'group_id' => (int) $payload['group_id']]);
                }
                break;
            case 'opportunity.suspended': case 'opportunity.deadline_changed': case 'opportunity.resumed':
                $opportunity = $stale ? null : Opportunity::query()->find($payload['opportunity_id']);
                if ($opportunity === null) {
                    break;
                }
                // Seuls les comptes qui suivent la fiche (favori, ou démarche en cours) sont prévenus. Une fiche suspendue n'est jamais présentée comme une offre :
                // le message renvoie au suivi personnel, pas à une page d'offre ouverte.
                [$type, $title, $body, $url] = match ($event->type) {
                    'opportunity.suspended' => ['opportunity.suspended', 'Une opportunité que vous suivez a été suspendue',
                        '« '.$opportunity->title.' » a été retirée du catalogue le temps d’une vérification. Ne candidatez pas à partir d’un ancien lien.', '/opportunites/suivi'],
                    'opportunity.deadline_changed' => ['opportunity.updated', 'La date limite d’une opportunité que vous suivez a changé',
                        '« '.$opportunity->title.' » — nouvelle date : '.$this->opportunities->deadline($opportunity, false)['text'].'. Ancienne date : '.($opportunity->previous_deadline['text'] ?? 'non communiquée').'.', '/opportunites/'.$opportunity->id],
                    default => ['opportunity.resumed', 'Une opportunité que vous suivez est de nouveau publiée', '« '.$opportunity->title.' » est de nouveau au catalogue, après vérification.', '/opportunites/'.$opportunity->id],
                };
                foreach ($this->followUps->followers($opportunity->id) as $userId) {
                    $created += (int) $this->store->create($userId, 'opportunity', $type, $title, $body, $url, $type.':'.$userId.':'.$opportunity->id.':v'.$payload['version'],
                        $event->occurred_at, $expires, $opportunity->id, null, ['version' => (int) $payload['version']]);
                }
                break;
            case 'grade.published': case 'grade.corrected': case 'grade.withdrawn':
                if ($stale) {
                    break;
                }
                $graded = AssessmentResult::query()->where('assessment_id', $payload['assessment_id'])->pluck('user_id')->all();
                $users = match (true) {
                    isset($payload['learner_ids']) => array_map('intval', $payload['learner_ids']),
                    $event->type === 'grade.withdrawn' => $graded,
                    default => array_values(array_unique([...$graded, ...$this->recipients($context, [(int) $payload['group_id']], [])])),
                };
                // Le message ne porte ni la note ni l'intitulé : il renvoie à la page des résultats, après connexion.
                $title = ['grade.published' => 'Un résultat a été publié', 'grade.corrected' => 'Un résultat a été corrigé', 'grade.withdrawn' => 'Une évaluation a été retirée'][$event->type];
                foreach ($users as $userId) {
                    $created += (int) $this->store->create($userId, 'grade', $event->type, $title, null, '/notes?source=etablissement', $event->type.':'.$userId.':'.$payload['assessment_id'].':v'.$payload['version'],
                        $event->occurred_at, $expires, $payload['assessment_id'], null, [...$context, 'group_id' => (int) $payload['group_id']]);
                }
                break;
        }

        return $created;
    }

    /**
     * Rappel après un cours terminé : fin du cours + délai choisi par la personne (5 minutes par défaut).
     */
    public function planCourseReminders(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            $ttl = (int) config('campus.notifications.course_reminder_ttl_hours');
            $from = $this->cursor('course_end', $now, $ttl);
            $created = 0;
            $plan = function (int $userId, string $occurrenceId, string $title, CarbonImmutable $endsAt) use (&$created, $ttl): void {
                if (! $this->preferences->category($userId, 'course_checkin')['enabled']) {
                    return;
                }
                $at = $endsAt->addMinutes($this->preferences->for($userId)->course_end_delay_minutes);
                $created += (int) $this->store->create($userId, 'course_checkin', 'course.checkin', 'Un cours vient de se terminer', 'Cours : '.$title.'. Notez en une minute ce qu’il vous reste à faire.',
                    '/planning/occurrences/'.$occurrenceId.'/retour', 'course-checkin:'.$userId.':'.$occurrenceId.':'.$endsAt->getTimestamp(), $at, $at->addHours($ttl), $occurrenceId, $endsAt);
            };
            $personal = CourseOccurrence::query()->where('scope', 'personal')->where('status', 'active')->where('ends_at', '>', $from)->where('ends_at', '<=', $now)->get(['id', 'owner_id', 'title', 'ends_at']);
            $this->preferences->preload($personal->pluck('owner_id'));
            foreach ($personal as $occurrence) {
                $plan($occurrence->owner_id, $occurrence->id, $occurrence->title, $occurrence->ends_at);
            }
            $published = PublishedOccurrence::query()->where('status', 'active')->where('ends_at', '>', $from)->where('ends_at', '<=', $now)->with('groups:id')->get();
            foreach ($published as $occurrence) {
                $learners = $this->recipients(['institution_id' => $occurrence->institution_id, 'academic_year_id' => $occurrence->academic_year_id], $occurrence->groups->pluck('id')->all(), []);
                $this->preferences->preload($learners);
                foreach ($learners as $userId) {
                    $plan($userId, $occurrence->course_occurrence_id, $occurrence->title, $occurrence->ends_at);
                }
            }
            $this->advance('course_end', $now);

            return $created;
        });
    }

    /**
     * Rappels d'échéance d'une tâche : la veille et le jour même, à l'heure et dans le fuseau choisis par la personne.
     */
    public function planTaskReminders(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            $ttl = (int) config('campus.notifications.task_reminder_ttl_hours');
            $from = $this->cursor('task_due', $now, $ttl);
            $created = 0;
            $tasks = Task::query()->whereNull('deleted_at')->where('due_at', '>', $from)->where('due_at', '<=', $now->addHours(49))
                ->where(fn ($query) => $query->where('scope', 'group')->orWhereIn('status', Task::OPEN))->get();
            foreach ($tasks as $task) {
                $users = $task->scope === 'personal' ? [$task->owner_id]
                    : $this->recipients(['institution_id' => $task->institution_id, 'academic_year_id' => $task->academic_year_id], [$task->group_id], []);
                $this->preferences->preload($users);
                foreach ($users as $userId) {
                    $preference = $this->preferences->for($userId);
                    if (! $this->preferences->category($userId, 'task_due')['enabled']) {
                        continue;
                    }
                    $local = $task->due_at->setTimezone($this->preferences->timezone($userId))->setTimeFromTimeString($preference->task_reminder_time);
                    foreach (['d1' => [$preference->task_day_before, $local->subDay(), 'Une tâche arrive à échéance demain'], 'd0' => [$preference->task_same_day, $local, 'Une tâche arrive à échéance aujourd’hui']] as $kind => [$wanted, $instant, $title]) {
                        $instant = $instant->utc();
                        // Le rappel doit tomber dans la fenêtre reprise, avant l'échéance, et après la création de la tâche.
                        if (! $wanted || $instant->lessThanOrEqualTo($from) || $instant->greaterThan($now) || $instant->greaterThanOrEqualTo($task->due_at) || $instant->lessThan($task->created_at)) {
                            continue;
                        }
                        $expires = $instant->addHours($ttl);
                        $created += (int) $this->store->create($userId, 'task_due', 'task.due.'.$kind, $title, 'Tâche : '.$task->title, '/taches', 'task-due:'.$kind.':'.$userId.':'.$task->id.':'.$task->due_at->getTimestamp(),
                            $instant, $expires->greaterThan($task->due_at) ? $task->due_at->toImmutable() : $expires, $task->id, $task->due_at->toImmutable());
                    }
                }
            }
            $this->advance('task_due', $now);

            return $created;
        });
    }

    /**
     * Rappels d'échéance des fiches suivies, N jours avant la clôture (7 et 1 par défaut, réglables, 0 pour désactiver).
     * Échéance exacte : le rappel part N jours avant l'instant publié. Date seule : N jours avant ce jour, à l'heure de rappel que la personne
     * a choisie dans son fuseau — aucune heure de clôture n'est supposée. Clôture continue ou date non communiquée : aucun rappel.
     */
    public function planFavoriteReminders(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            $ttl = (int) config('campus.notifications.opportunity_reminder_ttl_hours');
            $from = $this->cursor('favorite_due', $now, $ttl);
            $created = 0;
            $records = Opportunity::query()->where('status', 'published')->whereIn('deadline_kind', ['instant', 'date'])->where('closes_at', '>', $now)->where('closes_at', '<=', $now->addDays(33))->get();
            foreach ($records as $opportunity) {
                $users = $this->followUps->followers($opportunity->id)->all();
                $this->preferences->preload($users);
                $deadline = $this->opportunities->deadline($opportunity, false)['text'];
                foreach ($users as $userId) {
                    if (! $this->preferences->category($userId, 'opportunity')['enabled']) {
                        continue;
                    }
                    $preference = $this->preferences->for($userId);
                    foreach (array_unique(array_filter([(int) $preference->favorite_first_days, (int) $preference->favorite_second_days])) as $days) {
                        $instant = $opportunity->deadline_kind === 'instant' ? $opportunity->deadline_at->subDays($days)
                            : CarbonImmutable::parse($opportunity->deadline_date->toDateString().' '.$preference->task_reminder_time, $this->preferences->timezone($userId))->subDays($days)->utc();
                        // Dans la fenêtre reprise, avant la clôture, et pas avant la publication de la fiche.
                        if ($instant->lessThanOrEqualTo($from) || $instant->greaterThan($now) || $instant->greaterThanOrEqualTo($opportunity->closes_at) || $instant->lessThan($opportunity->published_at)) {
                            continue;
                        }
                        $expires = $instant->addHours($ttl);
                        $created += (int) $this->store->create($userId, 'opportunity', 'opportunity.deadline', 'Une opportunité que vous suivez arrive à échéance',
                            '« '.$opportunity->title.' » — date limite : '.$deadline.' (rappel à '.$days.' jour'.($days > 1 ? 's' : '').').', '/opportunites/'.$opportunity->id,
                            'favorite-due:'.$days.':'.$userId.':'.$opportunity->id.':'.$opportunity->closes_at->getTimestamp(), $instant,
                            $expires->greaterThan($opportunity->closes_at) ? $opportunity->closes_at : $expires, $opportunity->id, $opportunity->closes_at, ['days' => $days]);
                    }
                }
            }
            $this->advance('favorite_due', $now);

            return $created;
        });
    }

    /**
     * Synthèse quotidienne facultative, à l'heure locale choisie, seulement s'il y a quelque chose à signaler.
     */
    public function planDigests(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            $ttl = (int) config('campus.notifications.digest_ttl_hours');
            $from = $this->cursor('digest', $now, $ttl);
            $created = 0;
            foreach (NotificationPreference::query()->where('digest_enabled', true)->get() as $preference) {
                $timezone = $preference->timezone ?? $this->preferences->timezone($preference->user_id);
                $local = $now->setTimezone($timezone);
                $instant = $local->setTimeFromTimeString($preference->digest_time)->utc();
                $user = $instant->greaterThan($from) && $instant->lessThanOrEqualTo($now) ? User::query()->where('status', 'active')->find($preference->user_id) : null;
                if ($user === null) {
                    continue;
                }
                $due = $this->tasks->visible($user)->whereRaw("(CASE WHEN tasks.scope = 'group' THEN COALESCE(progress.status, 'todo') ELSE tasks.status END) IN ('todo', 'in_progress')")
                    ->whereBetween('tasks.due_at', [$local->startOfDay()->utc(), $local->endOfDay()->utc()])->count();
                $pending = $this->checkIns->pending($user)->count();
                if ($due + $pending === 0) {
                    continue;
                }
                $created += (int) $this->store->create($user->id, 'digest', 'digest.daily', 'Votre synthèse du jour', $due.' tâche(s) à échéance aujourd’hui · '.$pending.' retour(s) de cours à renseigner.',
                    '/', 'digest:'.$user->id.':'.$local->toDateString(), $instant, $instant->addHours($ttl));
            }
            $this->advance('digest', $now);

            return $created;
        });
    }

    /**
     * Début de la fenêtre à reprendre : la dernière position traitée (avec une courte marge), jamais plus ancienne que la durée de validité.
     * La ligne est verrouillée : deux planifications simultanées passent l'une après l'autre.
     */
    private function cursor(string $name, CarbonImmutable $now, int $ttlHours): CarbonImmutable
    {
        DB::table('notification_cursors')->insertOrIgnore(['name' => $name, 'position' => '2000-01-01 00:00:00']);
        $position = CarbonImmutable::parse(DB::table('notification_cursors')->where('name', $name)->lockForUpdate()->value('position'), 'UTC')->subMinutes(2);
        $floor = $now->subHours($ttlHours);

        return $position->greaterThan($floor) ? $position : $floor;
    }

    private function advance(string $name, CarbonImmutable $now): void
    {
        DB::table('notification_cursors')->where('name', $name)->update(['position' => $now->utc()->format('Y-m-d H:i:s')]);
    }

    /**
     * Comptes à informer maintenant : inscrits effectifs des groupes et enseignants désignés, appartenance et compte actifs.
     *
     * @param  array{institution_id: int|null, academic_year_id: int|null}  $context
     * @param  list<int>  $groups
     * @param  list<int>  $teachers
     * @return list<int>
     */
    private function recipients(array $context, array $groups, array $teachers): array
    {
        if ($context['institution_id'] === null || $context['academic_year_id'] === null) {
            return [];
        }
        sort($groups);
        sort($teachers);
        $key = $context['institution_id'].':'.$context['academic_year_id'].':'.implode(',', $groups).':'.implode(',', $teachers);

        return $this->recipients[$key] ??= $this->access->recipients((int) $context['institution_id'], (int) $context['academic_year_id'], $groups, $teachers)->all();
    }
}
