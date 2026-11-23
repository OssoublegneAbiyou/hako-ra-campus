<?php

namespace App\Http\Controllers;

use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\ActiveContext;
use App\Services\Attachments;
use App\Services\CourseCheckIns;
use App\Services\Tasks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tâches du compte connecté : ses tâches personnelles et les devoirs de ses groupes, avec son propre avancement.
 * Aucune route ne désigne les tâches ou l'avancement d'une autre personne.
 */
class TaskController extends Controller
{
    private const EFFECTIVE_STATUS = "(CASE WHEN tasks.scope = 'group' THEN COALESCE(progress.status, 'todo') ELSE tasks.status END)";

    public function index(Request $r, Tasks $tasks, CourseCheckIns $checkIns, Attachments $attachments): Response
    {
        $filters = $r->validate([
            'statut' => 'sometimes|in:open,all,'.implode(',', array_keys(Task::STATUSES)), 'priorite' => 'sometimes|in:'.implode(',', array_keys(Task::PRIORITIES)),
            'matiere' => ['sometimes', 'regex:/^[ps]-\d{1,18}$/'], 'du' => 'sometimes|date_format:Y-m-d', 'au' => 'sometimes|date_format:Y-m-d',
            'vue' => 'sometimes|in:liste,corbeille', 'cours' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1',
        ]);
        $user = $r->user();
        $timezone = $user->personalSpace?->timezone ?? config('app.display_timezone');
        $trash = ($filters['vue'] ?? 'liste') === 'corbeille';
        $status = $filters['statut'] ?? 'open';

        $query = $tasks->visible($user, $trash);
        if (! $trash) {
            $query->when($status === 'open', fn (Builder $q) => $q->whereRaw(self::EFFECTIVE_STATUS." IN ('todo', 'in_progress')"))
                ->when(! in_array($status, ['open', 'all'], true), fn (Builder $q) => $q->whereRaw(self::EFFECTIVE_STATUS.' = ?', [$status]))
                ->when(isset($filters['priorite']), fn (Builder $q) => $q->where('tasks.priority', $filters['priorite']))
                ->when(isset($filters['matiere']), fn (Builder $q) => $q->where(str_starts_with($filters['matiere'], 'p-') ? 'tasks.personal_subject_id' : 'tasks.subject_id', (int) substr($filters['matiere'], 2)));
        }
        $query->with(['group:id,code,name', 'subject:id,code,name', 'personalSubject:id,code,name']);

        // Les tâches sans échéance restent toujours visibles : le filtre de date ne porte que sur les tâches datées.
        $undated = $trash ? collect() : (clone $query)->whereNull('tasks.due_at')->orderByDesc('tasks.created_at')->limit(100)->get();
        $dated = (clone $query)->when(! $trash, fn (Builder $q) => $q->whereNotNull('tasks.due_at')
            ->when(isset($filters['du']), fn (Builder $q) => $q->where('tasks.due_at', '>=', CarbonImmutable::parse($filters['du'], $timezone)->startOfDay()->utc()))
            ->when(isset($filters['au']), fn (Builder $q) => $q->where('tasks.due_at', '<=', CarbonImmutable::parse($filters['au'], $timezone)->endOfDay()->utc()))
            ->orderBy('tasks.due_at'), fn (Builder $q) => $q->orderByDesc('tasks.deleted_at'))
            ->orderBy('tasks.id')->paginate(30)->withQueryString();

        $steps = $tasks->steps($user, $dated->getCollection()->concat($undated)->pluck('id'));
        $files = $trash ? collect() : $attachments->forTasks($user, $dated->getCollection()->concat($undated)->pluck('id'));
        $row = fn (Task $task): array => [...$this->row($task, $steps->get($task->id, collect()), $timezone, $tasks), 'attachments' => $files->get($task->id, [])];
        $course = isset($filters['cours']) ? $checkIns->course($user, $filters['cours']) : null;

        return Inertia::render('Tasks', [
            'tasks' => $dated->getCollection()->map($row)->all(),
            'undated' => $undated->map($row)->all(),
            'pagination' => ['page' => $dated->currentPage(), 'total' => $dated->lastPage(), 'previous' => $dated->previousPageUrl(), 'next' => $dated->nextPageUrl()],
            'filters' => ['statut' => $status, 'priorite' => $filters['priorite'] ?? '', 'matiere' => $filters['matiere'] ?? '', 'du' => $filters['du'] ?? '', 'au' => $filters['au'] ?? '', 'vue' => $trash ? 'corbeille' : 'liste'],
            'subjects' => $this->subjects($r, $tasks),
            'personalSubjects' => PersonalSubject::query()->where('owner_id', $user->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (PersonalSubject $subject): array => ['id' => $subject->id, 'name' => $subject->name])->all(),
            'statuses' => Task::STATUSES, 'priorities' => Task::PRIORITIES, 'timezone' => $timezone,
            'trashDays' => max(1, (int) config('campus.tasks.trash_days')),
            'attachmentLimits' => $attachments->limits($user),
            'sourceCourse' => $course ? ['id' => $course['id'], 'title' => $course['title']] : null,
            'teaching' => ($active = app(ActiveContext::class)->current($r)) !== null && $active->scope === 'institution' && $active->role === 'teacher',
        ]);
    }

    public function store(Request $r, Tasks $tasks): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['client_key' => 'required|uuid', ...$this->contentRules(), 'personal_subject_id' => 'nullable|integer|min:1', 'source_occurrence_id' => 'nullable|uuid']);
        $result = $tasks->createPersonal($r->user(), $data);

        return $this->answer($r, $result['task'], $result['task']->lock_version, $result['task']->status, $result['replayed'], 'Tâche enregistrée.');
    }

    public function update(Request $r, Tasks $tasks, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', ...$this->contentRules(), 'priority' => 'required|in:'.implode(',', array_keys(Task::PRIORITIES)),
            'personal_subject_id' => 'nullable|integer|min:1', 'status' => 'required|in:'.implode(',', array_keys(Task::STATUSES))]);
        $result = $tasks->updatePersonal($r->user(), $task, $data);

        return $this->answer($r, $result['task'], $result['task']->lock_version, $result['task']->status, $result['replayed'], 'Tâche mise à jour.');
    }

    /**
     * Avancement personnel sur un devoir collectif : expected_version vaut 0 tant que l'apprenant n'a rien enregistré.
     */
    public function progress(Request $r, Tasks $tasks, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:0', 'status' => 'required|in:'.implode(',', array_keys(Task::STATUSES)),
            'personal_target_at' => 'nullable|date_format:Y-m-d\TH:i']);
        $result = $tasks->progress($r->user(), $task, $data);

        return $this->answer($r, $result['progress']->task_id, $result['progress']->lock_version, $result['progress']->status, $result['replayed'], 'Votre avancement est enregistré. Il n’est visible que par vous.');
    }

    public function destroy(Request $r, Tasks $tasks, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1']);
        $trashed = $tasks->trash($r->user(), $task, (int) $data['expected_version']);

        return $this->answer($r, $trashed, $trashed->lock_version, $trashed->status, false,
            'Tâche placée dans la corbeille. Vous pouvez la restaurer pendant '.max(1, (int) config('campus.tasks.trash_days')).' jours.');
    }

    public function restore(Request $r, Tasks $tasks, string $task): RedirectResponse|JsonResponse
    {
        $restored = $tasks->restore($r->user(), $task);

        return $this->answer($r, $restored, $restored->lock_version, $restored->status, false, 'Tâche restaurée.');
    }

    public function storeStep(Request $r, Tasks $tasks, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['client_key' => 'required|uuid', 'title' => 'required|string|max:160']);
        $step = $tasks->addStep($r->user(), $task, $data['client_key'], $data['title']);

        return $this->stepAnswer($r, $step, 'Étape ajoutée.');
    }

    public function updateStep(Request $r, Tasks $tasks, string $task, int $step): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['done' => 'required|boolean']);
        $found = $tasks->step($r->user(), $task, $step);
        $found->update(['done' => (bool) $data['done']]);

        return $this->stepAnswer($r, $found, $found->done ? 'Étape terminée.' : 'Étape rouverte.');
    }

    public function destroyStep(Request $r, Tasks $tasks, string $task, int $step): RedirectResponse|JsonResponse
    {
        $found = $tasks->step($r->user(), $task, $step);
        $found->delete();

        return $this->stepAnswer($r, $found, 'Étape supprimée.');
    }

    /**
     * @return array<string, string>
     */
    private function contentRules(): array
    {
        return ['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'due_at' => 'nullable|date_format:Y-m-d\TH:i',
            'priority' => 'sometimes|in:'.implode(',', array_keys(Task::PRIORITIES))];
    }

    /**
     * @param  Collection<int, TaskStep>  $steps
     * @return array<string, mixed>
     */
    private function row(Task $task, Collection $steps, string $timezone, Tasks $tasks): array
    {
        $collective = $task->scope === 'group';
        $open = in_array($task->effective_status, Task::OPEN, true);
        $target = $task->personal_target_at;

        return [
            'id' => $task->id, 'collective' => $collective, 'title' => $task->title, 'description' => $task->description,
            'subject' => $collective ? $task->subject?->name : $task->personalSubject?->name,
            'personalSubjectId' => $task->personal_subject_id, 'group' => $collective ? $task->group?->name : null,
            'dueAt' => $task->due_at?->utc()->toIso8601String(), 'dueLocal' => $task->due_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'personalTargetAt' => $target?->utc()->toIso8601String(), 'personalTargetLocal' => $target?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'priority' => $task->priority, 'status' => $task->effective_status,
            // Le retard est calculé à l'affichage : il ne remplace jamais l'état d'avancement.
            'late' => $open && $task->due_at !== null && $task->due_at->isPast(),
            'version' => $collective ? (int) ($task->progress_version ?? 0) : $task->lock_version,
            'sourceOccurrenceId' => $task->source_occurrence_id,
            'steps' => $steps->map(fn (TaskStep $step): array => ['id' => $step->id, 'title' => $step->title, 'done' => $step->done])->values()->all(),
            'nextStep' => $steps->firstWhere('done', false)?->title,
            'deletedAt' => $task->deleted_at?->utc()->toIso8601String(),
            'purgeAt' => $task->deleted_at?->addDays(max(1, (int) config('campus.tasks.trash_days')))->utc()->toIso8601String(),
        ];
    }

    /**
     * Matières proposées au filtre : matières personnelles du compte et matières des devoirs qui lui sont visibles.
     *
     * @return list<array{value: string, label: string}>
     */
    private function subjects(Request $r, Tasks $tasks): array
    {
        $personal = PersonalSubject::query()->where('owner_id', $r->user()->id)->orderBy('name')->get(['id', 'name'])
            ->map(fn (PersonalSubject $subject): array => ['value' => 'p-'.$subject->id, 'label' => $subject->name]);
        $collective = Subject::query()->whereIn('id', $tasks->visible($r->user())->where('tasks.scope', 'group')->reorder()->distinct()->pluck('tasks.subject_id'))
            ->orderBy('name')->get(['id', 'name'])->map(fn (Subject $subject): array => ['value' => 's-'.$subject->id, 'label' => $subject->name.' (établissement)']);

        return $personal->concat($collective)->values()->all();
    }

    private function answer(Request $r, Task|string $task, int $version, string $status, bool $replayed, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $task instanceof Task ? $task->id : $task, 'version' => $version, 'status' => $status, 'replayed' => $replayed,
                'deleted' => $task instanceof Task && $task->deleted_at !== null], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('tasks')->with('status', $message);
    }

    private function stepAnswer(Request $r, TaskStep $step, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $step->id, 'task_id' => $step->task_id, 'done' => $step->done], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', $message);
    }
}
