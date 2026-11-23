<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AttachmentReport;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Enrollment;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Models\PersonalSubject;
use App\Models\PublishedOccurrence;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\TaskStep;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Support\PrivilegedAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tâches personnelles, devoirs collectifs et microtâches.
 * Une tâche personnelle n'est lue et modifiée que par son propriétaire. Un devoir collectif est lu par les apprenants
 * effectivement inscrits au groupe, géré par un enseignant effectivement affecté au groupe et à la matière ;
 * l'avancement de chaque apprenant reste dans TaskProgress et n'est lisible que par lui.
 */
class Tasks
{
    public const CONTENT = ['title', 'description', 'due_at', 'priority'];

    public function __construct(private ContextVisibility $visibility, private CourseCheckIns $checkIns) {}

    /**
     * Tâches visibles par le compte, quel que soit le contexte actif : ses tâches personnelles et les devoirs non retirés
     * des groupes où il est effectivement inscrit. effective_status porte l'avancement propre au compte.
     */
    public function visible(User $user, bool $trashed = false): Builder
    {
        $groups = $trashed ? collect() : $this->learnerGroupIds($user);

        return Task::query()
            ->leftJoin('task_progress as progress', fn ($join) => $join->on('progress.task_id', '=', 'tasks.id')->where('progress.owner_id', '=', $user->id))
            ->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $personal) => $personal->where('tasks.scope', 'personal')->where('tasks.owner_id', $user->id)
                    ->when($trashed, fn (Builder $query) => $query->where('tasks.deleted_at', '>', $this->trashLimit()), fn (Builder $query) => $query->whereNull('tasks.deleted_at')))
                ->orWhere(fn (Builder $group) => $group->where('tasks.scope', 'group')->whereNull('tasks.deleted_at')->whereIn('tasks.group_id', $groups)))
            ->select('tasks.*', 'progress.personal_target_at', 'progress.lock_version as progress_version')
            ->selectRaw("CASE WHEN tasks.scope = 'group' THEN COALESCE(progress.status, 'todo') ELSE tasks.status END AS effective_status");
    }

    public function find(User $user, string $taskId, bool $trashed = false): Task
    {
        return $this->visible($user, $trashed)->where('tasks.id', $taskId)->firstOrFail();
    }

    /**
     * Prochaines tâches ouvertes du compte, datées d'abord, pour l'accueil.
     *
     * @return list<array{id: string, title: string, dueAt: string|null, late: bool, collective: bool}>
     */
    public function upcoming(User $user, int $limit): array
    {
        return $this->visible($user)->whereRaw("(CASE WHEN tasks.scope = 'group' THEN COALESCE(progress.status, 'todo') ELSE tasks.status END) IN ('todo', 'in_progress')")
            ->orderByRaw('tasks.due_at IS NULL')->orderBy('tasks.due_at')->orderBy('tasks.id')->limit($limit)->get()
            ->map(fn (Task $task): array => ['id' => $task->id, 'title' => $task->title, 'dueAt' => $task->due_at?->utc()->toIso8601String(),
                'late' => $task->due_at !== null && $task->due_at->isPast(), 'collective' => $task->scope === 'group'])->all();
    }

    /**
     * Tâches ouvertes du compte pour la copie hors connexion. Les tâches personnelles portent leur version et leurs champs modifiables,
     * pour qu'un brouillon écrit sans réseau puisse être comparé à la version du serveur au retour.
     *
     * @return list<array<string, mixed>>
     */
    public function offlineRows(User $user, int $limit, string $timezone): array
    {
        return $this->visible($user)->whereRaw("(CASE WHEN tasks.scope = 'group' THEN COALESCE(progress.status, 'todo') ELSE tasks.status END) IN ('todo', 'in_progress')")
            ->orderByRaw('tasks.due_at IS NULL')->orderBy('tasks.due_at')->orderBy('tasks.id')->limit($limit)->get()
            ->map(function (Task $task) use ($timezone): array {
                $collective = $task->scope === 'group';

                return ['id' => $task->id, 'title' => $task->title, 'dueAt' => $task->due_at?->utc()->toIso8601String(), 'late' => $task->due_at !== null && $task->due_at->isPast(),
                    'collective' => $collective, 'description' => $task->description, 'dueLocal' => $task->due_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
                    'priority' => $task->priority, 'status' => $task->effective_status, 'version' => $collective ? null : $task->lock_version,
                    'personalSubjectId' => $collective ? null : $task->personal_subject_id];
            })->all();
    }

    /**
     * Règle que le lot 13 recontrôlera juste avant tout envoi : aucun rappel pour une tâche terminée, abandonnée,
     * supprimée ou devenue inaccessible, quel que soit l'ordre d'arrivée des synchronisations.
     */
    public function shouldRemind(User $user, string $taskId): bool
    {
        if ($user->fresh()?->status !== 'active') {
            return false;
        }
        $task = $this->visible($user)->where('tasks.id', $taskId)->first();

        return $task !== null && in_array($task->effective_status, Task::OPEN, true);
    }

    /**
     * Crée une tâche personnelle. client_key rend la création rejouable : la même demande renvoie la tâche existante,
     * la même clé avec un autre contenu est refusée.
     *
     * @param  array{client_key: string, title: string, description?: string|null, personal_subject_id?: int|null, due_at?: string|null, priority?: string, source_occurrence_id?: string|null}  $data
     * @return array{task: Task, replayed: bool}
     */
    public function createPersonal(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $space = $this->lockSpace($user);
            $content = $this->content($data, $space->timezone) + [
                'personal_subject_id' => isset($data['personal_subject_id']) ? (int) $data['personal_subject_id'] : null,
                'source_occurrence_id' => $data['source_occurrence_id'] ?? null,
            ];
            $hash = $this->hash($this->creationContent($content));
            $existing = Task::query()->where('owner_id', $user->id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                return $this->replayOrConflict($existing, $hash, 'Cette demande a déjà servi pour une autre tâche.');
            }
            $this->assertPersonalSubject($user, $content['personal_subject_id']);
            if ($content['source_occurrence_id'] !== null && $this->checkIns->course($user, $content['source_occurrence_id']) === null) {
                throw ValidationException::withMessages(['source_occurrence_id' => 'Ce cours est indisponible.']);
            }
            $task = Task::create([...$content, 'scope' => 'personal', 'owner_id' => $user->id, 'client_key' => $data['client_key'], 'content_hash' => $hash]);

            return ['task' => $task, 'replayed' => false];
        }, 3);
    }

    /**
     * Modifie une tâche personnelle, état compris. Une version dépassée ne remplace rien : rejeu identique accepté, sinon conflit.
     *
     * @param  array{expected_version: int, title: string, description?: string|null, personal_subject_id?: int|null, due_at?: string|null, priority: string, status: string}  $data
     * @return array{task: Task, replayed: bool}
     */
    public function updatePersonal(User $user, string $taskId, array $data): array
    {
        return DB::transaction(function () use ($user, $taskId, $data): array {
            $space = $this->lockSpace($user);
            $task = Task::query()->where('scope', 'personal')->where('owner_id', $user->id)->whereNull('deleted_at')->lockForUpdate()->findOrFail($taskId);
            $content = $this->content($data, $space->timezone) + ['personal_subject_id' => isset($data['personal_subject_id']) ? (int) $data['personal_subject_id'] : null, 'status' => $data['status']];
            $hash = $this->hash($content);
            if ((int) $data['expected_version'] !== $task->lock_version) {
                return $this->replayOrConflict($task, $hash, 'Cette tâche a été modifiée ailleurs. Rechargez-la avant de l’enregistrer.', (int) $data['expected_version']);
            }
            if ($content['personal_subject_id'] !== $task->personal_subject_id) {
                $this->assertPersonalSubject($user, $content['personal_subject_id']);
            }
            $wasOpen = in_array($task->status, Task::OPEN, true);
            $task->fill([...$content, 'content_hash' => $hash, 'lock_version' => $task->lock_version + 1])->save();
            $this->settled($user, $task, $wasOpen, $content['status'], $task->lock_version);

            return ['task' => $task, 'replayed' => false];
        }, 3);
    }

    /**
     * Avancement et objectif personnel d'un apprenant sur un devoir collectif. N'écrit que sa propre ligne :
     * ni le contenu officiel, ni la date officielle, ni l'avancement des autres ne sont touchés.
     *
     * @param  array{expected_version: int, status: string, personal_target_at?: string|null}  $data
     * @return array{progress: TaskProgress, replayed: bool}
     */
    public function progress(User $user, string $taskId, array $data): array
    {
        return DB::transaction(function () use ($user, $taskId, $data): array {
            $this->lockSpace($user);
            $task = $this->find($user, $taskId);
            abort_unless($task->scope === 'group', 404);
            $timezone = Institution::query()->whereKey($task->institution_id)->value('timezone');
            $target = $this->instant($data['personal_target_at'] ?? null, $timezone);
            $progress = TaskProgress::query()->where('task_id', $task->id)->where('owner_id', $user->id)->lockForUpdate()->first();
            $expected = (int) $data['expected_version'];
            $current = $progress?->lock_version ?? 0;
            if ($expected !== $current) {
                if ($progress && $expected < $current && $progress->status === $data['status'] && $progress->personal_target_at?->getTimestamp() === $target?->getTimestamp()) {
                    return ['progress' => $progress, 'replayed' => true];
                }
                throw new ConflictHttpException('Votre avancement a été modifié ailleurs. Rechargez-le avant de l’enregistrer.');
            }
            $wasOpen = in_array($progress?->status ?? 'todo', Task::OPEN, true);
            $progress ??= new TaskProgress(['task_id' => $task->id, 'owner_id' => $user->id, 'lock_version' => 0]);
            $progress->fill(['status' => $data['status'], 'personal_target_at' => $target, 'lock_version' => $progress->lock_version + 1])->save();
            $this->settled($user, $task, $wasOpen, $progress->status, $progress->lock_version);

            return ['progress' => $progress, 'replayed' => false];
        }, 3);
    }

    /**
     * Met une tâche personnelle à la corbeille. Rejouable : une tâche déjà à la corbeille reste dans le même état.
     */
    public function trash(User $user, string $taskId, int $expectedVersion): Task
    {
        return DB::transaction(function () use ($user, $taskId, $expectedVersion): Task {
            $this->lockSpace($user);
            $task = Task::query()->where('scope', 'personal')->where('owner_id', $user->id)->lockForUpdate()->findOrFail($taskId);
            if ($task->deleted_at !== null) {
                abort_unless($task->deleted_at->greaterThan($this->trashLimit()), 404);

                return $task;
            }
            if ($expectedVersion !== $task->lock_version) {
                throw new ConflictHttpException('Cette tâche a été modifiée ailleurs. Rechargez-la avant de la supprimer.');
            }
            $task->fill(['deleted_at' => now(), 'lock_version' => $task->lock_version + 1])->save();

            return $task;
        }, 3);
    }

    public function restore(User $user, string $taskId): Task
    {
        return DB::transaction(function () use ($user, $taskId): Task {
            $this->lockSpace($user);
            $task = Task::query()->where('scope', 'personal')->where('owner_id', $user->id)->lockForUpdate()->findOrFail($taskId);
            if ($task->deleted_at === null) {
                return $task;
            }
            abort_unless($task->deleted_at->greaterThan($this->trashLimit()), 404);
            $task->fill(['deleted_at' => null, 'lock_version' => $task->lock_version + 1])->save();

            return $task;
        }, 3);
    }

    /**
     * Ajoute une microtâche privée à une tâche visible (personnelle ou devoir collectif). Rejouable par client_key.
     */
    public function addStep(User $user, string $taskId, string $clientKey, string $title): TaskStep
    {
        return DB::transaction(function () use ($user, $taskId, $clientKey, $title): TaskStep {
            $this->lockSpace($user);
            $task = $this->find($user, $taskId);
            $steps = TaskStep::query()->where('task_id', $task->id)->where('owner_id', $user->id);
            if ($existing = (clone $steps)->where('client_key', $clientKey)->first()) {
                return $existing;
            }
            if ((clone $steps)->count() >= (int) config('campus.tasks.max_steps')) {
                throw ValidationException::withMessages(['title' => 'Cette tâche compte déjà le nombre maximal d’étapes.']);
            }

            return TaskStep::create(['task_id' => $task->id, 'owner_id' => $user->id, 'client_key' => $clientKey, 'title' => trim($title),
                'position' => ((int) (clone $steps)->max('position')) + 1]);
        }, 3);
    }

    public function step(User $user, string $taskId, int $stepId): TaskStep
    {
        $task = $this->find($user, $taskId);

        return TaskStep::query()->where('task_id', $task->id)->where('owner_id', $user->id)->findOrFail($stepId);
    }

    /**
     * Microtâches du compte pour une liste de tâches, dans l'ordre.
     *
     * @param  iterable<string>  $taskIds
     * @return Collection<string, Collection<int, TaskStep>>
     */
    public function steps(User $user, iterable $taskIds): Collection
    {
        return TaskStep::query()->where('owner_id', $user->id)->whereIn('task_id', collect($taskIds)->all())->orderBy('position')->orderBy('id')->get()->groupBy('task_id');
    }

    /**
     * Affectations effectives de l'enseignant : les seuls couples groupe/matière pour lesquels il publie ou modifie un devoir.
     *
     * @return Collection<int, TeachingAssignment>
     */
    public function assignments(User $user, Membership $teacher): Collection
    {
        $teacher = $this->visibility->institutionMembership($user, $teacher->id);
        abort_unless($teacher->role === 'teacher', 403);

        return TeachingAssignment::query()->where('membership_id', $teacher->id)->effective()
            ->whereHas('group', fn (Builder $query) => $query->whereNull('archived_at'))
            ->whereHas('subject', fn (Builder $query) => $query->whereNull('archived_at'))
            ->with(['group:id,code,name', 'subject:id,code,name'])->get();
    }

    /**
     * Devoirs que l'enseignant peut gérer maintenant. Aucun avancement d'apprenant n'est joint.
     */
    public function managed(User $user, Membership $teacher, bool $trashed = false): Builder
    {
        $pairs = $this->assignments($user, $teacher);

        return Task::query()->where('scope', 'group')->where('institution_id', $teacher->institution_id)->where('academic_year_id', $teacher->academic_year_id)
            ->when($trashed, fn (Builder $query) => $query->where('deleted_at', '>', $this->trashLimit()), fn (Builder $query) => $query->whereNull('deleted_at'))
            ->where(fn (Builder $any) => $pairs->isEmpty() ? $any->whereRaw('1 = 0')
                : $pairs->each(fn (TeachingAssignment $pair) => $any->orWhere(fn (Builder $one) => $one->where('group_id', $pair->group_id)->where('subject_id', $pair->subject_id))));
    }

    /**
     * Publie un devoir pour un groupe et une matière de l'enseignant. Rejouable par client_key.
     *
     * @param  array{client_key: string, group_id: int, subject_id: int, title: string, description?: string|null, due_at?: string|null, priority?: string, source_occurrence_id?: string|null}  $data
     * @return array{task: Task, replayed: bool}
     */
    public function publishHomework(User $user, Membership $teacher, array $data): array
    {
        return DB::transaction(function () use ($user, $teacher, $data): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $timezone = Institution::query()->whereKey($teacher->institution_id)->value('timezone');
            $content = $this->content($data, $timezone) + ['group_id' => (int) $data['group_id'], 'subject_id' => (int) $data['subject_id'], 'source_occurrence_id' => $data['source_occurrence_id'] ?? null];
            $hash = $this->hash($this->creationContent($content));
            $existing = Task::query()->where('author_membership_id', $teacher->id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                return $this->replayOrConflict($existing, $hash, 'Cette demande a déjà servi pour un autre devoir.');
            }
            if (! $this->assignments($user, $teacher)->contains(fn (TeachingAssignment $pair) => $pair->group_id === $content['group_id'] && $pair->subject_id === $content['subject_id'])) {
                throw (new ModelNotFoundException)->setModel(TeachingAssignment::class);
            }
            if ($content['source_occurrence_id'] !== null && ! PublishedOccurrence::query()->where('course_occurrence_id', $content['source_occurrence_id'])
                ->where('institution_id', $teacher->institution_id)->where('academic_year_id', $teacher->academic_year_id)
                ->whereHas('groups', fn ($group) => $group->where('groups.id', $content['group_id']))->exists()) {
                throw ValidationException::withMessages(['source_occurrence_id' => 'Ce cours n’est pas un cours publié de ce groupe.']);
            }
            $task = Task::create([...$content, 'scope' => 'group', 'institution_id' => $teacher->institution_id, 'academic_year_id' => $teacher->academic_year_id,
                'author_membership_id' => $teacher->id, 'client_key' => $data['client_key'], 'content_hash' => $hash]);
            $this->homeworkEvent($user, $task, 'homework.published', 'homework-published:'.$task->id);

            return ['task' => $task, 'replayed' => false];
        }, 3);
    }

    /**
     * Modifie le contenu officiel d'un devoir. Les avancements des apprenants ne sont pas touchés ; un changement réel
     * produit un seul événement par version, une demande sans changement n'en produit aucun.
     *
     * @param  array{expected_version: int, title: string, description?: string|null, due_at?: string|null, priority: string}  $data
     * @return array{task: Task, replayed: bool}
     */
    public function updateHomework(User $user, Membership $teacher, string $taskId, array $data): array
    {
        return DB::transaction(function () use ($user, $teacher, $taskId, $data): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $task = $this->managed($user, $teacher)->lockForUpdate()->findOrFail($taskId);
            $timezone = Institution::query()->whereKey($teacher->institution_id)->value('timezone');
            $content = $this->content($data, $timezone);
            $before = ['title' => $task->title, 'description' => $task->description, 'due_at' => $task->due_at?->utc()->format('Y-m-d H:i:s'), 'priority' => $task->priority];
            $changed = array_keys(array_filter($content, fn ($value, string $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
            if ((int) $data['expected_version'] !== $task->lock_version) {
                if ((int) $data['expected_version'] < $task->lock_version && $changed === []) {
                    return ['task' => $task, 'replayed' => true];
                }
                throw new ConflictHttpException('Ce devoir a été modifié ailleurs. Rechargez-le avant de l’enregistrer.');
            }
            if ($changed === []) {
                return ['task' => $task, 'replayed' => true];
            }
            $task->fill([...$content, 'lock_version' => $task->lock_version + 1])->save();
            $this->homeworkEvent($user, $task, 'homework.updated', 'homework-updated:'.$task->id.':v'.$task->lock_version, ['changed' => $changed]);

            return ['task' => $task, 'replayed' => false];
        }, 3);
    }

    /**
     * Retire (corbeille) ou rétablit un devoir. Les avancements sont conservés tant que le devoir n'est pas purgé.
     */
    public function withdrawHomework(User $user, Membership $teacher, string $taskId, bool $restore, ?int $expectedVersion = null): Task
    {
        return DB::transaction(function () use ($user, $teacher, $taskId, $restore, $expectedVersion): Task {
            $teacher = $this->lockTeacher($user, $teacher);
            $task = $this->managed($user, $teacher, $restore)->lockForUpdate()->find($taskId);
            if ($task === null) {
                // Rejeu : le devoir est déjà dans l'état demandé.
                return $this->managed($user, $teacher, ! $restore)->findOrFail($taskId);
            }
            if (! $restore && $expectedVersion !== $task->lock_version) {
                throw new ConflictHttpException('Ce devoir a été modifié ailleurs. Rechargez-le avant de le retirer.');
            }
            $task->fill(['deleted_at' => $restore ? null : now(), 'lock_version' => $task->lock_version + 1])->save();
            $type = $restore ? 'homework.restored' : 'homework.withdrawn';
            $this->homeworkEvent($user, $task, $type, str_replace('.', '-', $type).':'.$task->id.':v'.$task->lock_version);

            return $task;
        }, 3);
    }

    /**
     * Purge définitive des tâches restées à la corbeille au-delà du délai, avec leurs étapes et avancements.
     */
    public function purge(int $limit): int
    {
        $purged = 0;
        Task::query()->whereNotNull('deleted_at')->where('deleted_at', '<=', $this->trashLimit())->orderBy('deleted_at')->limit($limit)->pluck('id')
            ->each(function (string $id) use (&$purged): void {
                DB::transaction(function () use ($id, &$purged): void {
                    $task = Task::query()->whereNotNull('deleted_at')->where('deleted_at', '<=', $this->trashLimit())->lockForUpdate()->find($id);
                    if ($task === null) {
                        return;
                    }
                    foreach (Attachment::query()->where('task_id', $id)->get() as $attachment) {
                        Storage::disk('local')->delete($attachment->path());
                        AttachmentReport::query()->where('attachment_id', $attachment->id)->delete();
                        $attachment->delete();
                    }
                    TaskStep::query()->where('task_id', $id)->delete();
                    TaskProgress::query()->where('task_id', $id)->delete();
                    $task->delete();
                    $purged++;
                });
            });

        return $purged;
    }

    public function trashLimit(): CarbonImmutable
    {
        return now()->toImmutable()->subDays(max(1, (int) config('campus.tasks.trash_days')));
    }

    /**
     * Identifiants des groupes où le compte est apprenant effectivement inscrit, appartenance utilisable.
     *
     * @return Collection<int, int>
     */
    private function learnerGroupIds(User $user): Collection
    {
        $memberships = $this->visibility->usableMemberships($user)->where('role', 'learner')->pluck('id');

        return Enrollment::query()->effective()->where('user_id', $user->id)->whereIn('membership_id', $memberships)->pluck('group_id');
    }

    private function lockSpace(User $user): PersonalSpace
    {
        $space = PersonalSpace::query()->where('owner_id', $user->id)->lockForUpdate()->firstOrFail();
        $this->visibility->personalSpace($user, $space->id);

        return $space;
    }

    private function lockTeacher(User $user, Membership $teacher): Membership
    {
        abort_unless(PrivilegedAccess::available(), 403);
        Membership::query()->lockForUpdate()->findOrFail($teacher->id);
        $teacher = $this->visibility->institutionMembership($user, $teacher->id);
        abort_unless($teacher->role === 'teacher', 403);

        return $teacher;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string|null, due_at: string|null, priority: string}
     */
    private function content(array $data, string $timezone): array
    {
        $description = trim((string) ($data['description'] ?? ''));

        return ['title' => trim($data['title']), 'description' => $description === '' ? null : $description,
            'due_at' => $this->instant($data['due_at'] ?? null, $timezone)?->format('Y-m-d H:i:s'), 'priority' => $data['priority'] ?? 'normal'];
    }

    /**
     * Contenu de création, sans l'état : sert à reconnaître le rejeu d'une même création.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function creationContent(array $content): array
    {
        return ['creation' => $content];
    }

    private function instant(?string $local, string $timezone): ?CarbonImmutable
    {
        return $local === null || $local === '' ? null : CarbonImmutable::createFromFormat('Y-m-d\TH:i', $local, $timezone)->utc();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function hash(array $content): string
    {
        ksort($content);

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{task: Task, replayed: bool}
     */
    private function replayOrConflict(Task $task, string $hash, string $message, ?int $expectedVersion = null): array
    {
        // Création rejouée après que la tâche a évolué : la création a bien eu lieu, rien n'est réécrit.
        $evolvedSinceCreation = $expectedVersion === null && $task->lock_version > 1;
        $same = $task->content_hash !== null && hash_equals($task->content_hash, $hash);
        if ($evolvedSinceCreation || ($same && ($expectedVersion === null || $expectedVersion < $task->lock_version))) {
            return ['task' => $task, 'replayed' => true];
        }
        throw new ConflictHttpException($message);
    }

    private function assertPersonalSubject(User $user, ?int $subjectId): void
    {
        if ($subjectId !== null && ! PersonalSubject::query()->where('owner_id', $user->id)->whereNull('archived_at')->whereKey($subjectId)->exists()) {
            throw ValidationException::withMessages(['personal_subject_id' => 'Cette matière est indisponible.']);
        }
    }

    /**
     * Fait métier pour le lot 13 : la tâche vient d'être terminée ou abandonnée par cette personne, ses rappels deviennent inutiles.
     * Un seul événement par version ; aucun contenu.
     */
    private function settled(User $user, Task $task, bool $wasOpen, string $status, int $version): void
    {
        if ($wasOpen && ! in_array($status, Task::OPEN, true)) {
            DomainEvent::create(['type' => 'task.settled', 'dedup_key' => 'task-settled:'.$task->id.':'.$user->id.':v'.$version, 'subject_uuid' => $task->id,
                'payload' => ['user_id' => $user->id, 'task_id' => $task->id, 'status' => $status], 'occurred_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function homeworkEvent(User $user, Task $task, string $type, string $dedupKey, array $extra = []): void
    {
        // Les destinataires sont recalculés à l'envoi (lot 13) : seuls les inscrits effectifs à ce moment seront informés.
        DomainEvent::create(['type' => $type, 'dedup_key' => $dedupKey, 'subject_uuid' => $task->id, 'occurred_at' => now(),
            'payload' => ['task_id' => $task->id, 'institution_id' => $task->institution_id, 'academic_year_id' => $task->academic_year_id,
                'group_id' => $task->group_id, 'subject_id' => $task->subject_id, 'version' => $task->lock_version, ...$extra]]);
        AuditLog::record($user, $type, $task, $task->institution_id, null, ['group_id' => $task->group_id, 'subject_id' => $task->subject_id, 'version' => $task->lock_version, ...$extra]);
    }
}
