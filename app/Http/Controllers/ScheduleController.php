<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\PublishedOccurrence;
use App\Models\SchedulePublication;
use App\Models\TeacherUnavailability;
use App\Services\AcademicAdministration;
use App\Services\CourseCheckIns;
use App\Services\ScheduleAccess;
use App\Services\ScheduleManager;
use App\Support\PrivilegedAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends Controller
{
    public function index(Request $r, ScheduleAccess $access, AcademicAdministration $references): Response
    {
        $d = $r->validate(['date' => 'sometimes|date_format:Y-m-d', 'view' => 'sometimes|in:day,week,list', 'page' => 'sometimes|integer|min:1', 'series_page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:100', 'option_q' => 'nullable|string|max:100', 'source' => 'sometimes|in:draft,published']);
        $acting = $access->acting($r);
        if ($acting !== null && ($acting->role === 'learner' || ($d['source'] ?? ($acting->role === 'teacher' ? 'published' : 'draft')) === 'published')) {
            return $this->published($r, $access, $acting, $d);
        }
        $timezone = $acting?->institution->timezone ?? ($r->user()->personalSpace?->timezone ?? config('app.display_timezone'));
        $selected = CarbonImmutable::parse($d['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $view = $d['view'] ?? 'week';
        $start = $view === 'day' ? $selected : $selected->startOfWeek();
        $end = $view === 'day' ? $start->addDay() : $start->addWeek();
        $query = $access->occurrences($r)->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc());
        if (! empty($d['q'])) {
            $query->where('title', 'like', '%'.$d['q'].'%');
        }
        $rows = $query->with(['groups:id,name', 'series'])->orderBy('starts_at')->orderBy('id')->paginate(50)->withQueryString();
        // Retour privé après cours : proposé sur son planning personnel, jamais sur un brouillon institutionnel.
        $checkIns = $acting === null ? app(CourseCheckIns::class)->statuses($r->user(), $rows->getCollection()->pluck('id')) : [];
        $editable = $acting === null || $acting->role === 'institution_admin';
        $series = $access->series($r)->with(['revisions' => fn ($q) => $q->orderByDesc('version')->limit(1)])->latest('created_at')->paginate(10, ['*'], 'series_page')->withQueryString();
        $options = [];
        if ($editable) {
            foreach ($acting ? ['subjects', 'groups'] : ['subjects'] as $kind) {
                $options[$kind] = $references->query($r, $kind)->whereNull('archived_at')->when(! empty($d['option_q']), fn ($b) => $b->where('name', 'like', '%'.$d['option_q'].'%'))->orderBy('name')->limit(200)->get(['id', 'name']);
            }
            $options['teachers'] = $acting ? Membership::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)->where('role', 'teacher')->where('status', 'active')->whereNull('revoked_at')
                ->whereHas('user', fn ($b) => $b->where('name', 'like', '%'.($d['option_q'] ?? '').'%'))->with('user:id,name')->limit(200)->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name])->all() : [];
        }

        return Inertia::render('Schedule', [
            'date' => $selected->toDateString(), 'view' => $view, 'search' => $d['q'] ?? '', 'optionSearch' => $d['option_q'] ?? '',
            'timezone' => $timezone, 'institutional' => $acting !== null, 'editable' => $editable, 'draftsHidden' => $acting?->role === 'learner',
            'source' => $acting ? 'draft' : 'personal', 'canSwitchSource' => $acting !== null, 'publication' => $acting ? $this->publication($acting) : null,
            'canPublish' => $acting?->role === 'institution_admin', 'canReport' => false, 'reports' => [],
            'rangeStart' => $start->toDateString(), 'rangeEnd' => $end->subDay()->toDateString(),
            'previousHref' => route('planning', ['date' => $start->subDays($view === 'day' ? 1 : 7)->toDateString(), 'view' => $view], absolute: false),
            'nextHref' => route('planning', ['date' => $start->addDays($view === 'day' ? 1 : 7)->toDateString(), 'view' => $view], absolute: false),
            'rows' => $rows->getCollection()->map(fn ($o) => [
                'id' => $o->id, 'seriesId' => $o->course_series_id, 'title' => $o->title, 'startsAt' => $o->starts_at->utc()->toIso8601String(), 'endsAt' => $o->ends_at->utc()->toIso8601String(),
                'timezone' => $o->timezone, 'originalDate' => $o->original_date->toDateString(), 'status' => $o->status, 'exception' => $o->is_exception, 'version' => $o->lock_version,
                'room' => $o->room, 'meetingUrl' => $o->meeting_url, 'provenance' => $o->scope === 'personal' ? 'personal' : 'institutional', 'groups' => $o->groups->pluck('name')->all(),
                'checkIn' => $checkIns[$o->id] ?? null,
                'canCheckIn' => $acting === null && (isset($checkIns[$o->id]) || ($o->status === 'active' && ! $o->starts_at->isFuture())),
            ])->all(),
            'pagination' => ['page' => $rows->currentPage(), 'total' => $rows->lastPage(), 'previous' => $rows->previousPageUrl(), 'next' => $rows->nextPageUrl()],
            'series' => $series->getCollection()->map(fn ($s) => ['id' => $s->id, 'version' => $s->lock_version, 'revisions' => $s->revisions->sortBy('version')->values()->map(fn ($v) => ['version' => $v->version, 'effectiveFrom' => $v->effective_from->toDateString(), 'rules' => $v->rules])->all()])->all(),
            'seriesPagination' => ['page' => $series->currentPage(), 'total' => $series->lastPage(), 'previous' => $series->previousPageUrl(), 'next' => $series->nextPageUrl()],
            'options' => $options,
        ]);
    }

    /**
     * Planning publié du contexte actif : seule vue des apprenants, vue par défaut des enseignants.
     *
     * @param  array<string, mixed>  $d
     */
    private function published(Request $r, ScheduleAccess $access, Membership $acting, array $d): Response
    {
        $timezone = $acting->institution->timezone;
        $selected = CarbonImmutable::parse($d['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $view = $d['view'] ?? 'week';
        $start = $view === 'day' ? $selected : $selected->startOfWeek();
        $end = $view === 'day' ? $start->addDay() : $start->addWeek();
        $rows = $access->published($r)->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())
            ->when(! empty($d['q']), fn ($b) => $b->where('title', 'like', '%'.$d['q'].'%'))
            ->with('groups:id,name')->orderBy('starts_at')->orderBy('course_occurrence_id')->paginate(50)->withQueryString();
        $teacher = $acting->role === 'teacher';
        $learner = $acting->role === 'learner';
        $checkIns = $learner ? app(CourseCheckIns::class)->statuses($r->user(), $rows->getCollection()->pluck('course_occurrence_id')) : [];
        $href = fn (CarbonImmutable $date): string => route('planning', ['date' => $date->toDateString(), 'view' => $view, 'source' => 'published'], absolute: false);

        return Inertia::render('Schedule', [
            'date' => $selected->toDateString(), 'view' => $view, 'search' => $d['q'] ?? '', 'optionSearch' => '',
            'timezone' => $timezone, 'institutional' => true, 'editable' => false, 'draftsHidden' => $acting->role === 'learner',
            'source' => 'published', 'canSwitchSource' => $acting->role !== 'learner', 'publication' => $this->publication($acting),
            'canPublish' => $acting->role === 'institution_admin', 'canReport' => $teacher && PrivilegedAccess::available(),
            'reports' => $teacher ? TeacherUnavailability::query()->where('teacher_membership_id', $acting->id)->with('occurrence:id,title,starts_at')
                ->latest('id')->limit(20)->get()->map(fn (TeacherUnavailability $report): array => [
                    'id' => $report->id, 'occurrenceId' => $report->course_occurrence_id, 'title' => $report->occurrence->title,
                    'startsAt' => $report->occurrence->starts_at->utc()->toIso8601String(), 'status' => $report->status,
                    'decision' => $report->decision ? TeacherUnavailability::DECISIONS[$report->decision] : null, 'note' => $report->decision_note,
                ])->all() : [],
            'rangeStart' => $start->toDateString(), 'rangeEnd' => $end->subDay()->toDateString(),
            'previousHref' => $href($start->subDays($view === 'day' ? 1 : 7)), 'nextHref' => $href($start->addDays($view === 'day' ? 1 : 7)),
            'rows' => $rows->getCollection()->map(fn (PublishedOccurrence $o): array => [
                'id' => $o->course_occurrence_id, 'seriesId' => $o->snapshot['course_series_id'], 'title' => $o->title,
                'startsAt' => $o->starts_at->utc()->toIso8601String(), 'endsAt' => $o->ends_at->utc()->toIso8601String(),
                'timezone' => $o->snapshot['timezone'], 'originalDate' => $o->snapshot['original_date'], 'status' => $o->status, 'exception' => false,
                'version' => $o->publication_version, 'room' => $o->snapshot['room'], 'meetingUrl' => $o->snapshot['meeting_url'],
                'provenance' => 'institutional', 'groups' => $o->groups->pluck('name')->all(),
                'checkIn' => $checkIns[$o->course_occurrence_id] ?? null,
                'canCheckIn' => $learner && (isset($checkIns[$o->course_occurrence_id]) || ($o->status === 'active' && ! $o->starts_at->isFuture())),
            ])->all(),
            'pagination' => ['page' => $rows->currentPage(), 'total' => $rows->lastPage(), 'previous' => $rows->previousPageUrl(), 'next' => $rows->nextPageUrl()],
            'series' => [], 'seriesPagination' => ['page' => 1, 'total' => 1, 'previous' => null, 'next' => null], 'options' => [],
        ]);
    }

    /** @return array{version: int, publishedAt: string}|null */
    private function publication(Membership $acting): ?array
    {
        $current = SchedulePublication::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
            ->orderByDesc('version')->first(['version', 'published_at']);

        return $current ? ['version' => $current->version, 'publishedAt' => $current->published_at->utc()->toIso8601String()] : null;
    }

    public function store(Request $r, ScheduleManager $manager): RedirectResponse
    {
        $manager->create($r);

        return back()->with('status', 'Série enregistrée ; première fenêtre matérialisée.');
    }

    public function materialize(Request $r, ScheduleManager $manager, string $series): RedirectResponse
    {
        $manager->materialize($r, $series);

        return back()->with('status', 'Fenêtre matérialisée sans recréer les occurrences existantes.');
    }

    public function future(Request $r, ScheduleManager $manager, string $series): RedirectResponse
    {
        $manager->changeFuture($r, $series);

        return back()->with('status', 'Occurrences futures révisées. Les exceptions manuelles sont conservées.');
    }

    public function occurrence(Request $r, ScheduleManager $manager, string $occurrence): RedirectResponse
    {
        $manager->changeOccurrence($r, $occurrence);

        return back()->with('status', 'Occurrence mise à jour, identifiant conservé.');
    }

    public function history(Request $r, ScheduleAccess $access, string $occurrence): Response
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $o = $access->occurrences($r)->findOrFail($occurrence);
        $revisions = $o->revisions()->orderByDesc('version')->paginate(20)->withQueryString();

        return Inertia::render('ScheduleHistory', ['title' => $o->title, 'id' => $o->id, 'pagination' => ['page' => $revisions->currentPage(), 'total' => $revisions->lastPage(), 'previous' => $revisions->previousPageUrl(), 'next' => $revisions->nextPageUrl()], 'revisions' => $revisions->getCollection()->map(fn ($v) => ['version' => $v->version, 'at' => $v->created_at->toIso8601String(), 'reason' => $v->reason, 'before' => $v->before, 'after' => $v->after])->all()]);
    }
}
