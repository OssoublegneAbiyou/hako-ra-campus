<?php

namespace App\Services;

use App\Models\CourseCheckIn;
use App\Models\CourseCheckInEvent;
use App\Models\CourseOccurrence;
use App\Models\DomainEvent;
use App\Models\Enrollment;
use App\Models\PersonalSpace;
use App\Models\PublishedOccurrence;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retours privés après cours. Toute lecture et toute écriture partent du propriétaire ;
 * aucun rôle d'établissement ou de plateforme n'ouvre d'accès.
 */
class CourseCheckIns
{
    public const FIELDS = ['summary', 'work_to_do', 'points_to_review', 'understanding'];

    public function __construct(private ContextVisibility $visibility) {}

    /**
     * Cours sur lequel le compte peut ouvrir un retour maintenant : son propre événement personnel,
     * ou un cours publié d'un groupe où il est effectivement inscrit. Un brouillon non publié n'est jamais visible.
     *
     * @return array{id: string, title: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, status: string, timezone: string, provenance: string}|null
     */
    public function course(User $user, string $occurrenceId): ?array
    {
        $personal = CourseOccurrence::query()->where('scope', 'personal')->where('owner_id', $user->id)->whereIn('status', ['active', 'cancelled'])->find($occurrenceId);
        if ($personal) {
            return ['id' => $personal->id, 'title' => $personal->title, 'starts_at' => $personal->starts_at, 'ends_at' => $personal->ends_at,
                'status' => $personal->status, 'timezone' => $personal->timezone, 'provenance' => 'personal'];
        }
        $published = $this->publishedFor($user)->find($occurrenceId);

        return $published ? ['id' => $published->course_occurrence_id, 'title' => $published->title, 'starts_at' => $published->starts_at, 'ends_at' => $published->ends_at,
            'status' => $published->status, 'timezone' => $published->snapshot['timezone'], 'provenance' => 'institutional'] : null;
    }

    /**
     * Cours terminés récemment et encore sans retour (ou reportés) : la sollicitation visible dans l'application,
     * indépendante de toute notification. Les cours annulés n'y figurent pas.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(User $user, int $limit = 20): Collection
    {
        $now = now()->toImmutable();
        $since = $now->subDays(max(1, (int) config('campus.check_ins.pending_days')));
        $settled = CourseCheckIn::query()->where('owner_id', $user->id)->whereIn('status', CourseCheckIn::SETTLED)
            ->where('course_ends_at', '>=', $since->subDay())->pluck('course_occurrence_id');
        $deferred = CourseCheckIn::query()->where('owner_id', $user->id)->where('status', 'deferred')->pluck('course_occurrence_id')->flip();
        $row = fn (string $id, string $title, $startsAt, $endsAt, string $provenance): array => [
            'occurrenceId' => $id, 'title' => $title, 'startsAt' => $startsAt->utc()->toIso8601String(), 'endsAt' => $endsAt->utc()->toIso8601String(),
            'provenance' => $provenance, 'deferred' => isset($deferred[$id]),
        ];
        $personal = CourseOccurrence::query()->where('scope', 'personal')->where('owner_id', $user->id)->where('status', 'active')
            ->where('ends_at', '<=', $now)->where('ends_at', '>=', $since)->whereNotIn('id', $settled)->orderByDesc('ends_at')->limit($limit)->get()
            ->map(fn (CourseOccurrence $o) => $row($o->id, $o->title, $o->starts_at, $o->ends_at, 'personal'));
        $published = $this->publishedFor($user)->where('status', 'active')->where('ends_at', '<=', $now)->where('ends_at', '>=', $since)
            ->whereNotIn('course_occurrence_id', $settled)->orderByDesc('ends_at')->limit($limit)->get()
            ->map(fn (PublishedOccurrence $o) => $row($o->course_occurrence_id, $o->title, $o->starts_at, $o->ends_at, 'institutional'));

        return $personal->concat($published)->sortByDesc('endsAt')->take($limit)->values();
    }

    /**
     * Règle que le lot 13 recontrôlera juste avant tout envoi : un rappel post-cours n'a de sens que si le cours
     * est toujours prévu, toujours accessible à la personne, et que son retour n'est ni renseigné ni ignoré.
     */
    public function shouldRemind(User $user, string $occurrenceId): bool
    {
        $course = $user->status === 'active' ? $this->course($user, $occurrenceId) : null;

        return $course !== null && $course['status'] === 'active'
            && ! CourseCheckIn::query()->where('owner_id', $user->id)->where('course_occurrence_id', $occurrenceId)->whereIn('status', CourseCheckIn::SETTLED)->exists();
    }

    /**
     * Enregistre le retour du propriétaire. Idempotent : une demande identique rejouée renvoie le retour existant ;
     * une version dépassée provoque un conflit et n'écrase rien. La tâche éventuelle est créée dans la même transaction.
     *
     * @param  array{action: string, expected_version: int, summary?: string|null, work_to_do?: string|null, points_to_review?: string|null, understanding?: int|null, create_task?: bool, task_title?: string|null}  $data
     * @return array{checkIn: CourseCheckIn, task: Task|null, replayed: bool}
     */
    public function save(User $user, string $occurrenceId, array $data): array
    {
        $action = $data['action'];
        $content = ['summary' => null, 'work_to_do' => null, 'points_to_review' => null, 'understanding' => null];
        if ($action === 'save') {
            foreach (['summary', 'work_to_do', 'points_to_review'] as $field) {
                $text = trim((string) ($data[$field] ?? ''));
                $content[$field] = $text === '' ? null : $text;
            }
            $content['understanding'] = isset($data['understanding']) ? (int) $data['understanding'] : null;
            if (array_filter($content, fn ($value) => $value !== null) === []) {
                throw ValidationException::withMessages(['summary' => 'Renseignez au moins un champ, ou choisissez « Rien à signaler ».']);
            }
        }
        $status = ['save' => 'completed', 'nothing' => 'nothing', 'defer' => 'deferred', 'ignore' => 'ignored'][$action];
        $wantsTask = $action === 'save' && ($data['create_task'] ?? false);
        $taskTitle = $wantsTask ? trim((string) ($data['task_title'] ?? '')) : '';
        if ($wantsTask && $taskTitle === '') {
            $taskTitle = Str::limit(trim((string) Str::of((string) $content['work_to_do'])->before("\n")), 250, '');
        }
        if ($wantsTask && $taskTitle === '') {
            throw ValidationException::withMessages(['task_title' => 'Indiquez le travail à effectuer ou un titre pour créer la tâche.']);
        }
        $hash = hash('sha256', json_encode([$status, $content, $wantsTask ? $taskTitle : null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($user, $occurrenceId, $data, $content, $status, $wantsTask, $taskTitle, $hash): array {
            // Verrou de l'espace personnel : deux demandes simultanées du même compte passent l'une après l'autre.
            $space = PersonalSpace::query()->where('owner_id', $user->id)->lockForUpdate()->firstOrFail();
            $this->visibility->personalSpace($user, $space->id);
            $checkIn = CourseCheckIn::query()->where('owner_id', $user->id)->where('course_occurrence_id', $occurrenceId)->lockForUpdate()->first();
            $course = $this->course($user, $occurrenceId);
            abort_if($checkIn === null && $course === null, 404);
            $expected = (int) $data['expected_version'];

            if ($checkIn !== null && $expected !== $checkIn->lock_version) {
                if ($expected < $checkIn->lock_version && hash_equals($checkIn->content_hash, $hash)) {
                    return ['checkIn' => $checkIn, 'task' => $checkIn->task, 'replayed' => true];
                }
                throw new ConflictHttpException('Ce retour a été modifié ailleurs. Rechargez-le avant de l’enregistrer.');
            }
            if ($checkIn === null) {
                abort_unless($expected === 0, 409, 'Ce retour n’existe pas encore.');
                if ($course['status'] !== 'active') {
                    throw ValidationException::withMessages(['summary' => 'Ce cours a été annulé : aucun retour n’est attendu.']);
                }
                if ($course['starts_at']->isFuture()) {
                    throw ValidationException::withMessages(['summary' => 'Le retour s’ouvre au début du cours.']);
                }
                $checkIn = new CourseCheckIn(['owner_id' => $user->id, 'course_occurrence_id' => $occurrenceId, 'lock_version' => 0]);
            } elseif (in_array($status, ['deferred', 'ignored'], true) && in_array($checkIn->status, ['completed', 'nothing'], true)) {
                throw ValidationException::withMessages(['summary' => 'Ce retour est déjà renseigné : il ne peut plus être reporté ni ignoré.']);
            }

            $before = $checkIn->only([...self::FIELDS, 'status']);
            $checkIn->fill([...$content, 'status' => $status, 'content_hash' => $hash, 'lock_version' => $checkIn->lock_version + 1]);
            if ($course !== null) {
                $checkIn->fill(['course_title' => $course['title'], 'course_starts_at' => $course['starts_at'], 'course_ends_at' => $course['ends_at']]);
            }
            $settling = in_array($status, CourseCheckIn::SETTLED, true) && $checkIn->settled_at === null;
            if ($settling) {
                $checkIn->settled_at = now();
            }
            $checkIn->save();
            CourseCheckInEvent::create(['course_check_in_id' => $checkIn->id, 'version' => $checkIn->lock_version, 'status' => $status, 'created_at' => now(),
                'changed_fields' => array_values(array_filter([...self::FIELDS, 'status'], fn (string $field) => ($before[$field] ?? null) !== $checkIn->{$field}))]);

            $task = $checkIn->task()->first();
            if ($wantsTask && $task === null) {
                $task = Task::create(['scope' => 'personal', 'owner_id' => $user->id, 'title' => $taskTitle, 'description' => $content['work_to_do'],
                    'source_occurrence_id' => $occurrenceId, 'source_check_in_id' => $checkIn->id, 'client_key' => (string) Str::uuid()]);
            }
            if ($settling) {
                // Fait métier pour le lot 13 : les rappels encore en attente pour ce cours et cette personne deviennent inutiles. Aucun contenu privé.
                DomainEvent::create(['type' => 'checkin.settled', 'dedup_key' => 'checkin-settled:'.$checkIn->id, 'subject_uuid' => $occurrenceId,
                    'payload' => ['user_id' => $user->id, 'occurrence_id' => $occurrenceId, 'status' => $status], 'occurred_at' => now()]);
            }

            return ['checkIn' => $checkIn, 'task' => $task, 'replayed' => false];
        }, 3);
    }

    /**
     * États des retours du compte pour une liste d'occurrences, pour l'affichage du planning.
     *
     * @param  iterable<string>  $occurrenceIds
     * @return array<string, string>
     */
    public function statuses(User $user, iterable $occurrenceIds): array
    {
        return CourseCheckIn::query()->where('owner_id', $user->id)->whereIn('course_occurrence_id', collect($occurrenceIds)->all())->pluck('status', 'course_occurrence_id')->all();
    }

    private function publishedFor(User $user)
    {
        $memberships = $this->visibility->usableMemberships($user)->where('role', 'learner')->pluck('id');
        $groups = Enrollment::query()->effective()->where('user_id', $user->id)->whereIn('membership_id', $memberships)->pluck('group_id');

        return PublishedOccurrence::query()->whereIn('status', ['active', 'cancelled'])->whereHas('groups', fn ($group) => $group->whereIn('groups.id', $groups));
    }
}
