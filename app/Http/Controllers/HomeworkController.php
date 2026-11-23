<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\Membership;
use App\Models\Task;
use App\Models\TeachingAssignment;
use App\Services\ActiveContext;
use App\Services\Attachments;
use App\Services\Tasks;
use App\Support\PrivilegedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Devoirs collectifs publiés par un enseignant pour ses groupes et matières affectés.
 * L'enseignant gère le contenu officiel ; il ne lit aucun avancement individuel.
 */
class HomeworkController extends Controller
{
    public function __construct(private ActiveContext $context, private Tasks $tasks) {}

    public function index(Request $r, Attachments $attachments): Response
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $teacher = $this->teacher($r);
        $timezone = Institution::query()->whereKey($teacher->institution_id)->value('timezone');
        $row = fn (Task $task): array => [
            'id' => $task->id, 'title' => $task->title, 'description' => $task->description, 'group' => $task->group?->name, 'subject' => $task->subject?->name,
            'dueAt' => $task->due_at?->utc()->toIso8601String(), 'dueLocal' => $task->due_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'priority' => $task->priority, 'version' => $task->lock_version,
            'purgeAt' => $task->deleted_at?->addDays(max(1, (int) config('campus.tasks.trash_days')))->utc()->toIso8601String(),
        ];
        $published = $this->tasks->managed($r->user(), $teacher)->with(['group:id,name', 'subject:id,name'])->orderByDesc('created_at')->orderBy('id')->paginate(30)->withQueryString();
        $files = $attachments->forTasks($r->user(), $published->getCollection()->pluck('id'), true);

        return Inertia::render('Homework', [
            'assignments' => $this->tasks->assignments($r->user(), $teacher)->map(fn (TeachingAssignment $pair): array => [
                'groupId' => $pair->group_id, 'subjectId' => $pair->subject_id, 'label' => $pair->group->name.' — '.$pair->subject->name,
            ])->values()->all(),
            'homework' => $published->getCollection()->map(fn (Task $task): array => [...$row($task), 'attachments' => $files->get($task->id, [])])->all(),
            'attachmentLimits' => $attachments->limits($r->user()),
            'withdrawn' => $this->tasks->managed($r->user(), $teacher, true)->with(['group:id,name', 'subject:id,name'])->orderByDesc('deleted_at')->limit(50)->get()->map($row)->all(),
            'pagination' => ['page' => $published->currentPage(), 'total' => $published->lastPage(), 'previous' => $published->previousPageUrl(), 'next' => $published->nextPageUrl()],
            'priorities' => Task::PRIORITIES, 'timezone' => $timezone, 'canWrite' => PrivilegedAccess::available(),
            'trashDays' => max(1, (int) config('campus.tasks.trash_days')),
        ]);
    }

    public function store(Request $r): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['client_key' => 'required|uuid', 'group_id' => 'required|integer|min:1', 'subject_id' => 'required|integer|min:1',
            ...$this->contentRules(), 'source_occurrence_id' => 'nullable|uuid']);
        $result = $this->tasks->publishHomework($r->user(), $this->teacher($r), $data);

        return $this->answer($r, $result['task'], $result['replayed'], 'Devoir publié pour le groupe.');
    }

    public function update(Request $r, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', ...$this->contentRules(), 'priority' => 'required|in:'.implode(',', array_keys(Task::PRIORITIES))]);
        $result = $this->tasks->updateHomework($r->user(), $this->teacher($r), $task, $data);

        return $this->answer($r, $result['task'], $result['replayed'], $result['replayed'] ? 'Aucun changement à enregistrer.' : 'Devoir mis à jour. L’avancement des apprenants est conservé.');
    }

    public function destroy(Request $r, string $task): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1']);
        $withdrawn = $this->tasks->withdrawHomework($r->user(), $this->teacher($r), $task, false, (int) $data['expected_version']);

        return $this->answer($r, $withdrawn, false, 'Devoir retiré. Vous pouvez le rétablir pendant '.max(1, (int) config('campus.tasks.trash_days')).' jours.');
    }

    public function restore(Request $r, string $task): RedirectResponse|JsonResponse
    {
        $restored = $this->tasks->withdrawHomework($r->user(), $this->teacher($r), $task, true);

        return $this->answer($r, $restored, false, 'Devoir rétabli, avec l’avancement des apprenants.');
    }

    /**
     * Appartenance d'enseignant du contexte actif, relue en base ; tout autre contexte est refusé.
     */
    private function teacher(Request $r): Membership
    {
        $membership = $this->context->current($r);
        abort_unless($membership !== null && $membership->scope === 'institution' && $membership->role === 'teacher', 403);

        return $membership;
    }

    /**
     * @return array<string, string>
     */
    private function contentRules(): array
    {
        return ['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'due_at' => 'nullable|date_format:Y-m-d\TH:i',
            'priority' => 'sometimes|in:'.implode(',', array_keys(Task::PRIORITIES))];
    }

    private function answer(Request $r, Task $task, bool $replayed, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $task->id, 'version' => $task->lock_version, 'replayed' => $replayed, 'deleted' => $task->deleted_at !== null], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('homework.index')->with('status', $message);
    }
}
