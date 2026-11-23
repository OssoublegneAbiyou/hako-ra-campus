<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\Group;
use App\Models\Membership;
use App\Models\SchedulePublication;
use App\Models\SchedulePublicationChange;
use App\Models\TeacherUnavailability;
use App\Services\ScheduleAccess;
use App\Services\ScheduleManager;
use App\Services\SchedulePublisher;
use App\Support\PrivilegedAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SchedulePublicationController extends Controller
{
    private const PAGE = 25;

    /**
     * Prévisualisation des différences, versions publiées et signalements : réservée à l'administration de l'année active.
     */
    public function index(Request $r, ScheduleAccess $access, SchedulePublisher $publisher): Response
    {
        $filters = $r->validate(['page' => 'sometimes|integer|min:1', 'versions_page' => 'sometimes|integer|min:1', 'version' => 'sometimes|integer|min:1', 'detail_page' => 'sometimes|integer|min:1']);
        $acting = $access->acting($r, true);
        abort_unless($acting !== null, 403);
        $current = $publisher->current($acting);
        $blocked = null;
        try {
            $diff = $publisher->diff($acting);
        } catch (ValidationException $error) {
            $diff = ['changes' => [], 'stateHash' => ''];
            $blocked = collect($error->errors())->flatten()->first();
        }
        $pending = collect($diff['changes'])->map(fn (array $change, string $id): array => ['id' => $id, ...$change])
            ->sortBy(fn (array $change) => $change['after']['starts_at'].$change['id'])->values();
        $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil($pending->count() / self::PAGE)));

        $detail = null;
        if (isset($filters['version'])) {
            $selected = $publisher->publications($acting)->where('version', $filters['version'])->with('author:id,name')->firstOrFail();
            $rows = SchedulePublicationChange::query()->where('schedule_publication_id', $selected->id)->orderBy('id')
                ->paginate(self::PAGE, ['*'], 'detail_page')->withQueryString();
            $detail = [
                ...$this->version($selected),
                'changes' => $this->describe($acting, $rows->getCollection()->map(fn (SchedulePublicationChange $change): array => [
                    'id' => $change->course_occurrence_id, 'change' => $change->change, 'before' => $change->before_state, 'after' => $change->after_state,
                ])),
                'pagination' => ['page' => $rows->currentPage(), 'total' => $rows->lastPage(), 'previous' => $rows->previousPageUrl(), 'next' => $rows->nextPageUrl()],
            ];
        }
        $versions = $publisher->publications($acting)->with('author:id,name')->orderByDesc('version')->paginate(10, ['*'], 'versions_page')->withQueryString();
        $link = fn (int $target): ?string => $target >= 1 && $target <= max(1, (int) ceil($pending->count() / self::PAGE)) && $target !== $page
            ? route('planning.publication', ['page' => $target], absolute: false) : null;

        return Inertia::render('SchedulePublication', [
            'institution' => $acting->institution->name,
            'year' => $acting->academicYear->name,
            'timezone' => $acting->institution->timezone,
            'current' => $current ? $this->version($current->load('author:id,name')) : null,
            'preview' => [
                'hash' => $diff['stateHash'],
                'blocked' => $blocked,
                'total' => $pending->count(),
                'counts' => $pending->countBy('change')->all(),
                'changes' => $this->describe($acting, $pending->forPage($page, self::PAGE)->values()),
                'pagination' => ['page' => $page, 'total' => max(1, (int) ceil($pending->count() / self::PAGE)), 'previous' => $link($page - 1), 'next' => $link($page + 1)],
            ],
            'versions' => $versions->getCollection()->map(fn (SchedulePublication $publication): array => $this->version($publication))->all(),
            'versionsPagination' => ['page' => $versions->currentPage(), 'total' => $versions->lastPage(), 'previous' => $versions->previousPageUrl(), 'next' => $versions->nextPageUrl()],
            'detail' => $detail,
            'reports' => TeacherUnavailability::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
                ->where('status', 'pending')->with(['occurrence:id,title,starts_at,ends_at,status', 'reporter:id,name'])->oldest('id')->limit(50)->get()
                ->map(fn (TeacherUnavailability $report): array => [
                    'id' => $report->id, 'teacher' => $report->reporter->name, 'reason' => $report->reason, 'title' => $report->occurrence->title,
                    'startsAt' => $report->occurrence->starts_at->utc()->toIso8601String(), 'draftStatus' => $report->occurrence->status,
                ])->all(),
            'changeLabels' => SchedulePublisher::CHANGE_LABELS,
        ]);
    }

    public function store(Request $r, SchedulePublisher $publisher): RedirectResponse
    {
        $publication = $publisher->publish($r);

        return redirect()->route('planning.publication')->with('status', 'Version '.$publication->version.' publiée. Les membres concernés voient désormais ce planning.');
    }

    public function rollback(Request $r, SchedulePublisher $publisher, int $version): RedirectResponse
    {
        $publication = $publisher->rollback($r, $version);

        $skipped = $publication->summary['skipped'] ?? 0;
        $overlaps = $publication->summary['overlaps'] ?? 0;

        return redirect()->route('planning.publication')->with('status', 'Retour à la version '.$version.' publié comme version '.$publication->version.'. L’historique et les cours passés sont conservés.'
            .($skipped > 0 ? ' '.$skipped.' cours non rétablis : leur enseignant, groupe ou matière n’est plus valide.' : '')
            .($overlaps > 0 ? ' '.$overlaps.' cours rétablis chevauchent un autre cours : à vérifier dans le brouillon.' : ''));
    }

    /**
     * Signalement par l'enseignant du cours. Il reste en attente et ne change ni le brouillon ni le planning publié.
     */
    public function report(Request $r, ScheduleAccess $access, string $occurrence): RedirectResponse
    {
        $acting = $access->acting($r);
        abort_unless($acting !== null && $acting->role === 'teacher' && PrivilegedAccess::available(), 403);
        $data = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $course = $access->occurrences($r)->findOrFail($occurrence);
        if ($course->starts_at->isPast() || $course->status !== 'active') {
            throw ValidationException::withMessages(['reason' => 'Seul un cours prévu et à venir peut faire l’objet d’un signalement.']);
        }
        try {
            $report = TeacherUnavailability::create([
                'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'course_occurrence_id' => $course->id,
                'teacher_membership_id' => $acting->id, 'reporter_id' => $r->user()->id, 'reason' => $data['reason'],
            ]);
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['reason' => 'Un signalement est déjà en attente pour ce cours.']);
            }
            throw $error;
        }
        AuditLog::record($r->user(), 'schedule.unavailability.reported', $report, $acting->institution_id);

        return back()->with('status', 'Indisponibilité signalée. Le cours reste inchangé tant que l’administration n’a pas publié sa décision.');
    }

    /**
     * Décision de l'administration. Une acceptation n'agit que sur le brouillon : seule une publication change le planning officiel.
     */
    public function decide(Request $r, ScheduleAccess $access, ScheduleManager $manager, int $report): RedirectResponse
    {
        $data = $r->validate(['decision' => 'required|in:decline,accept_cancel,accept_other', 'note' => 'nullable|string|max:500']);
        DB::transaction(function () use ($r, $access, $manager, $report, $data): void {
            $acting = $access->lock($r);
            abort_unless($acting !== null, 403);
            $pending = TeacherUnavailability::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
                ->lockForUpdate()->findOrFail($report);
            if ($pending->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Ce signalement a déjà reçu une décision.']);
            }
            if ($data['decision'] === 'accept_cancel') {
                $course = CourseOccurrence::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
                    ->with('groups')->lockForUpdate()->findOrFail($pending->course_occurrence_id);
                $manager->restore($r, $course, ['status' => 'cancelled', 'is_exception' => true], 'Indisponibilité de l’enseignant acceptée');
            }
            $pending->forceFill([
                'status' => $data['decision'] === 'decline' ? 'declined' : 'accepted', 'decision' => $data['decision'],
                'decision_note' => $data['note'] ?? null, 'decided_by' => $r->user()->id, 'decided_at' => now(),
            ])->save();
            AuditLog::record($r->user(), 'schedule.unavailability.decided', $pending, $acting->institution_id, $data['decision']);
        }, 3);

        return back()->with('status', $data['decision'] === 'decline' ? 'Signalement refusé : le planning ne change pas.'
            : 'Signalement accepté. La modification reste dans le brouillon jusqu’à sa publication.');
    }

    /** @return array<string, mixed> */
    private function version(SchedulePublication $publication): array
    {
        return [
            'version' => $publication->version, 'kind' => $publication->kind, 'sourceVersion' => $publication->source_version,
            'publishedAt' => $publication->published_at->utc()->toIso8601String(), 'author' => $publication->author?->name,
            'reason' => $publication->reason, 'summary' => $publication->summary,
        ];
    }

    /**
     * Rend lisibles les états avant/après d'une page de différences ; les noms sont résolus dans le seul périmètre de l'acteur.
     *
     * @param  Collection<int, array{id: string, change: string, before: array<string, mixed>|null, after: array<string, mixed>}>  $changes
     * @return list<array<string, mixed>>
     */
    private function describe(Membership $acting, Collection $changes): array
    {
        $states = $changes->flatMap(fn (array $change) => array_filter([$change['before'], $change['after']]));
        $groups = Group::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
            ->whereIn('id', $states->flatMap(fn (array $state) => $state['group_ids'])->unique())->pluck('name', 'id');
        $teachers = Membership::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
            ->whereIn('id', $states->pluck('teacher_membership_id')->filter()->unique())->with('user:id,name')->get()->mapWithKeys(fn (Membership $m) => [$m->id => $m->user->name]);
        $readable = fn (?array $state): ?array => $state === null ? null : [
            'title' => $state['title'], 'startsAt' => $state['starts_at'], 'endsAt' => $state['ends_at'], 'status' => $state['status'], 'room' => $state['room'],
            'teacher' => $teachers[$state['teacher_membership_id']] ?? null,
            'groups' => collect($state['group_ids'])->map(fn ($id) => $groups[$id] ?? null)->filter()->values()->all(),
        ];

        return $changes->map(fn (array $change): array => [
            'id' => $change['id'], 'change' => $change['change'], 'before' => $readable($change['before']), 'after' => $readable($change['after']),
        ])->values()->all();
    }
}
