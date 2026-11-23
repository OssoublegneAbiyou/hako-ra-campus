<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\CourseOccurrence;
use App\Models\CourseOccurrenceRevision;
use App\Models\CourseSeries;
use App\Models\CourseSeriesRevision;
use App\Models\Group;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleManager
{
    public function __construct(private ScheduleAccess $access, private WeeklyRecurrence $calendar) {}

    private function reason(Request $r): string
    {
        return $r->validate(['reason' => 'required|string|min:3|max:1000'])['reason'];
    }

    public function rules(Request $r, ?Membership $acting): array
    {
        $d = $r->validate([
            'title' => 'required|string|max:255', 'kind' => 'required|in:weekly,once',
            'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:valid_from',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'timezone' => 'required|timezone',
            'weekdays' => 'required|array|min:1|max:7', 'weekdays.*' => 'integer|between:1,7|distinct',
            'non_working_days' => 'sometimes|array|max:7', 'non_working_days.*' => 'integer|between:1,7|distinct',
            'excluded_dates' => 'sometimes|array|max:366', 'excluded_dates.*' => 'date_format:Y-m-d|distinct',
            'active_weeks' => 'sometimes|array|max:110', 'active_weeks.*' => 'date_format:Y-m-d|distinct',
            'holidays' => 'sometimes|array|max:30', 'holidays.*.from' => 'required|date_format:Y-m-d', 'holidays.*.until' => 'required|date_format:Y-m-d',
            'group_ids' => 'sometimes|array|max:20', 'group_ids.*' => 'integer|distinct', 'subject_id' => 'nullable|integer', 'personal_subject_id' => 'nullable|integer',
            'teacher_membership_id' => 'nullable|integer', 'room' => 'nullable|string|max:100', 'meeting_url' => 'nullable|url:http,https|max:2048',
        ]);
        $this->calendar->ensure(CarbonImmutable::parse($d['valid_from'])->diffInDays(CarbonImmutable::parse($d['valid_until'])) < WeeklyRecurrence::VALIDITY_DAYS, 'valid_until', 'La validité est limitée à 730 jours inclusifs.');
        foreach (['weekdays', 'non_working_days', 'group_ids'] as $key) {
            $d[$key] = array_values(array_unique(array_map('intval', $d[$key] ?? [])));
            sort($d[$key]);
        }
        foreach (['excluded_dates', 'active_weeks', 'holidays'] as $key) {
            $d[$key] = $d[$key] ?? [];
        }
        foreach ($d['active_weeks'] as $date) {
            $this->calendar->ensure(CarbonImmutable::parse($date)->dayOfWeekIso === 1, 'active_weeks', 'Les semaines actives sont indiquées par leur lundi.');
        }
        foreach ($d['holidays'] as $range) {
            $this->calendar->ensure($range['until'] >= $range['from'], 'holidays', 'La fin des vacances doit suivre le début.');
        }
        if ($d['kind'] === 'once') {
            $this->calendar->ensure($d['valid_from'] === $d['valid_until'], 'valid_until', 'Un événement ponctuel dure une seule date locale.');
            $d['weekdays'] = [CarbonImmutable::parse($d['valid_from'])->dayOfWeekIso];
        }
        $d['room'] = isset($d['room']) && trim($d['room']) !== '' ? mb_strtoupper(trim($d['room'])) : null;
        $d['meeting_url'] = $d['meeting_url'] ?? null;
        if ($acting) {
            $d['timezone'] = $acting->institution->timezone;
            $year = $acting->academicYear;
            $this->calendar->ensure($d['valid_from'] >= $year->starts_on->toDateString() && $d['valid_until'] <= $year->ends_on->toDateString(), 'valid_until', 'La série doit rester dans son année académique.');
            $this->calendar->ensure(count($d['group_ids']) > 0, 'group_ids', 'Choisissez au moins un groupe.');
            foreach ($d['group_ids'] as $id) {
                Group::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)->whereNull('archived_at')->findOrFail($id);
            }
            $d['subject_id'] = Subject::query()->where('institution_id', $acting->institution_id)->whereNull('archived_at')->findOrFail($d['subject_id'] ?? 0)->id;
            $teacher = Membership::query()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)->where('role', 'teacher')->where('status', 'active')->whereNull('revoked_at')->findOrFail($d['teacher_membership_id'] ?? 0);
            $d['teacher_membership_id'] = $teacher->id;
            $d['personal_subject_id'] = null;
        } else {
            $d['personal_subject_id'] = empty($d['personal_subject_id']) ? null : PersonalSubject::query()->where('owner_id', $r->user()->id)->whereNull('archived_at')->findOrFail($d['personal_subject_id'])->id;
            $d['group_ids'] = [];
            $d['subject_id'] = null;
            $d['teacher_membership_id'] = null;
        }

        return $d;
    }

    public function create(Request $r): CourseSeries
    {
        return DB::transaction(function () use ($r): CourseSeries {
            $acting = $this->access->lock($r);
            $rules = $this->rules($r, $acting);
            $key = $r->validate(['request_key' => 'required|uuid'])['request_key'];
            $hash = hash('sha256', json_encode($rules, JSON_THROW_ON_ERROR));
            $existing = $this->access->series($r)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409);

                return $existing;
            }
            $series = CourseSeries::create(['scope' => $acting ? 'institution' : 'personal', 'owner_id' => $acting ? null : $r->user()->id, 'institution_id' => $acting?->institution_id, 'academic_year_id' => $acting?->academic_year_id, 'created_by' => $r->user()->id, 'request_key' => $key, 'request_hash' => $hash]);
            CourseSeriesRevision::create(['course_series_id' => $series->id, 'version' => 1, 'effective_from' => $rules['valid_from'], 'rules' => $rules, 'actor_id' => $r->user()->id, 'reason' => 'Création de la série']);
            $this->materializeLocked($r, $series, $rules['valid_from'], CarbonImmutable::parse($rules['valid_from'])->addDays(55)->min(CarbonImmutable::parse($rules['valid_until']))->toDateString());
            AuditLog::record($r->user(), 'schedule.series.created', $series, $acting?->institution_id);

            return $series;
        }, 3);
    }

    public function materialize(Request $r, string $id): void
    {
        $d = $r->validate(['window_from' => 'required|date_format:Y-m-d', 'window_until' => 'required|date_format:Y-m-d|after_or_equal:window_from']);
        $this->calendar->window($d['window_from'], $d['window_until']);
        DB::transaction(function () use ($r, $id, $d): void {
            $this->access->lock($r);
            $series = $this->access->series($r)->lockForUpdate()->findOrFail($id);
            $this->materializeLocked($r, $series, $d['window_from'], $d['window_until']);
        }, 3);
    }

    /** Au plus une règle par date d’effet, donc au plus 730 dates dans la validité bornée. */
    private function loadRules(CourseSeries $series): void
    {
        $series->load(['revisions' => fn ($query) => $query->whereIn('version', DB::table('course_series_revisions')->where('course_series_id', $series->id)->selectRaw('MAX(version)')->groupBy('effective_from'))]);
    }

    private function applicable(CourseSeries $series, string $date): ?array
    {
        return $series->revisions->filter(fn ($v) => $v->effective_from->toDateString() <= $date)->sortByDesc('version')->first()?->rules;
    }

    private function candidate(CourseSeries $series, array $rules, string $date): array
    {
        $start = $this->calendar->instant($date, $rules['start_time'], $rules['timezone']);
        $end = $this->calendar->instant($date, $rules['end_time'], $rules['timezone']);
        $this->calendar->ensure($end->gt($start) && $start->diffInHours($end) <= 12, 'end_time', 'La séance doit durer au plus 12 heures et se terminer après son début.');
        $data = ['course_series_id' => $series->id, 'scope' => $series->scope, 'owner_id' => $series->owner_id, 'institution_id' => $series->institution_id, 'academic_year_id' => $series->academic_year_id,
            'title' => $rules['title'], 'subject_id' => $rules['subject_id'], 'personal_subject_id' => $rules['personal_subject_id'], 'teacher_membership_id' => $rules['teacher_membership_id'],
            'original_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $rules['timezone'], 'room' => $rules['room'], 'meeting_url' => $rules['meeting_url'], 'status' => 'active', 'is_exception' => false, 'group_ids' => $rules['group_ids']];
        $this->references($data);

        return $data;
    }

    private function references(array $d): void
    {
        if ($d['scope'] === 'personal') {
            if ($d['personal_subject_id']) {
                PersonalSubject::query()->where('owner_id', $d['owner_id'])->whereNull('archived_at')->findOrFail($d['personal_subject_id']);
            }

            return;
        }
        // Les mêmes références reviennent pour chaque date d'une série : elles sont lues une fois par instance, donc par requête ou par job.
        $year = $this->once('year:'.$d['institution_id'].':'.$d['academic_year_id'], fn () => AcademicYear::query()->where('institution_id', $d['institution_id'])->whereNull('archived_at')->findOrFail($d['academic_year_id']));
        $this->once('subject:'.$d['institution_id'].':'.$d['subject_id'], fn () => Subject::query()->where('institution_id', $d['institution_id'])->whereNull('archived_at')->findOrFail($d['subject_id']));
        $teacher = $this->once('teacher:'.$d['academic_year_id'].':'.$d['teacher_membership_id'], fn () => Membership::query()->where('institution_id', $d['institution_id'])->where('academic_year_id', $d['academic_year_id'])->where('role', 'teacher')->where('status', 'active')->whereNull('revoked_at')->findOrFail($d['teacher_membership_id']));
        $start = CarbonImmutable::parse($d['starts_at']);
        $end = CarbonImmutable::parse($d['ends_at']);
        $this->calendar->ensure($start->gte($teacher->starts_at) && (! $teacher->ends_at || $end->lte($teacher->ends_at)), 'teacher_membership_id', 'L’enseignant doit être habilité pendant toute la séance.');
        $this->calendar->ensure($start->setTimezone($d['timezone'])->toDateString() >= $year->starts_on->toDateString() && $end->setTimezone($d['timezone'])->toDateString() <= $year->ends_on->toDateString(), 'starts_at', 'La séance doit rester dans son année.');
        foreach ($d['group_ids'] as $groupId) {
            $this->once('group:'.$d['academic_year_id'].':'.$groupId, fn () => Group::query()->where('institution_id', $d['institution_id'])->where('academic_year_id', $d['academic_year_id'])->whereNull('archived_at')->findOrFail($groupId));
            $windows = $this->once('assignments:'.$teacher->id.':'.$groupId.':'.$d['subject_id'], fn () => TeachingAssignment::query()->where('membership_id', $teacher->id)->where('group_id', $groupId)->where('subject_id', $d['subject_id'])->where('status', 'active')->get(['starts_at', 'ends_at']));
            $assigned = $windows->contains(fn (TeachingAssignment $window) => $window->starts_at->lte($start) && ($window->ends_at === null || $window->ends_at->gte($end)));
            $this->calendar->ensure($assigned, 'teacher_membership_id', 'L’enseignant doit être affecté à chaque groupe et matière pendant la séance.');
        }
    }

    /** @var array<string, mixed> */
    private array $known = [];

    private function once(string $key, callable $load): mixed
    {
        return $this->known[$key] ??= $load();
    }

    private function materializeLocked(Request $r, CourseSeries $series, string $from, string $until): void
    {
        [$start,$end] = $this->calendar->window($from, $until);
        $this->loadRules($series);
        $anchor = $series->revisions->sortBy('version')->first()->rules['valid_from'];
        $this->calendar->ensure($from >= $anchor && $until <= CarbonImmutable::parse($anchor)->addDays(729)->toDateString(), 'window_until', 'La fenêtre doit rester dans les 730 jours du début de la série.');
        $candidates = [];
        $known = $series->occurrences()->whereBetween('original_date', [$from, $until])->get(['original_date'])
            ->mapWithKeys(fn (CourseOccurrence $occurrence) => [$occurrence->original_date->toDateString() => true]);
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $rules = $this->applicable($series, $key);
            if (! $rules || ! $this->calendar->active($rules, $key) || isset($known[$key])) {
                continue;
            }
            $candidates[] = $this->candidate($series, $rules, $key);
        }
        $this->conflicts($r, $candidates, []);
        foreach ($candidates as $candidate) {
            $this->persist($r, null, $candidate, 'Matérialisation hebdomadaire');
        }
        $series->materialized_until = max($series->materialized_until ?? $until, $until);
        $series->save();
    }

    public function changeFuture(Request $r, string $id): void
    {
        $r->validate(['effective_from' => 'required|date_format:Y-m-d', 'lock_version' => 'required|integer|min:1']);
        $reason = $this->reason($r);
        DB::transaction(function () use ($r, $id, $reason): void {
            $acting = $this->access->lock($r);
            $series = $this->access->series($r)->lockForUpdate()->findOrFail($id);
            abort_unless((int) $r->input('lock_version') === (int) $series->lock_version, 409);
            $rules = $this->rules($r, $acting);
            $anchor = $series->revisions()->orderBy('version')->firstOrFail()->rules['valid_from'];
            $this->calendar->ensure($rules['valid_from'] >= $anchor && $rules['valid_until'] <= CarbonImmutable::parse($anchor)->addDays(729)->toDateString(), 'valid_until', 'La révision doit rester dans les 730 jours du début de la série.');
            $effective = $r->string('effective_from')->toString();
            $this->calendar->ensure($effective >= now($rules['timezone'])->toDateString() && $effective >= $rules['valid_from'] && $effective <= $rules['valid_until'], 'effective_from', 'La date d’effet doit être future ou aujourd’hui et dans la validité.');
            $series->lock_version++;
            $series->save();
            CourseSeriesRevision::create(['course_series_id' => $id, 'version' => $series->lock_version, 'effective_from' => $effective, 'rules' => $rules, 'actor_id' => $r->user()->id, 'reason' => $reason]);
            $this->loadRules($series);
            $existing = $series->occurrences()->where('original_date', '>=', $effective)->with('groups')->orderBy('original_date')->get();
            $this->calendar->ensure($existing->count() <= 730, 'effective_from', 'Trop d’occurrences à réviser dans cette opération.');
            $changes = [];
            foreach ($existing as $occurrence) {
                if ($occurrence->is_exception) {
                    continue;
                }
                $date = $occurrence->original_date->toDateString();
                $applicable = $this->applicable($series, $date);
                if ($this->calendar->active($applicable, $date)) {
                    $changes[] = ['old' => $occurrence, 'data' => $this->candidate($series, $applicable, $date)];
                } else {
                    $data = $this->snapshot($occurrence);
                    $data['status'] = 'calendar_excluded';
                    $changes[] = ['old' => $occurrence, 'data' => $data];
                }
            }
            $candidates = array_column($changes, 'data');
            $excluded = array_map(fn ($change) => $change['old']->id, $changes);
            $this->conflicts($r, $candidates, $excluded);
            foreach ($changes as $change) {
                $this->persist($r, $change['old'], $change['data'], $reason);
            }
            $horizon = max($series->materialized_until ?? $effective, CarbonImmutable::parse($effective)->addDays(55)->min(CarbonImmutable::parse($rules['valid_until']))->toDateString());
            for ($from = CarbonImmutable::parse($effective); $from->toDateString() <= $horizon; $from = $from->addDays(56)) {
                $this->materializeLocked($r, $series, $from->toDateString(), $from->addDays(55)->min(CarbonImmutable::parse($horizon))->toDateString());
            }
            AuditLog::record($r->user(), 'schedule.series.future_changed', $series, $acting?->institution_id);
        }, 3);
    }

    public function changeOccurrence(Request $r, string $id): void
    {
        $d = $r->validate(['lock_version' => 'required|integer|min:1', 'action' => 'required|in:reschedule,cancel', 'date' => 'required_if:action,reschedule|date_format:Y-m-d', 'start_time' => 'required_if:action,reschedule|date_format:H:i', 'end_time' => 'required_if:action,reschedule|date_format:H:i|after:start_time', 'room' => 'nullable|string|max:100', 'meeting_url' => 'nullable|url:http,https|max:2048']);
        $reason = $this->reason($r);
        DB::transaction(function () use ($r, $id, $d, $reason): void {
            $this->access->lock($r);
            $occurrence = $this->access->occurrences($r)->lockForUpdate()->with('groups')->findOrFail($id);
            abort_unless((int) $occurrence->lock_version === (int) $d['lock_version'], 409);
            $data = $this->snapshot($occurrence);
            $data['is_exception'] = true;
            if ($d['action'] === 'cancel') {
                $data['status'] = 'cancelled';
            } else {
                $data['starts_at'] = $this->calendar->instant($d['date'], $d['start_time'], $occurrence->timezone);
                $data['ends_at'] = $this->calendar->instant($d['date'], $d['end_time'], $occurrence->timezone);
                $this->calendar->ensure($data['ends_at']->gt($data['starts_at']) && $data['starts_at']->diffInHours($data['ends_at']) <= 12, 'end_time', 'La séance doit durer au plus 12 heures.');
                $data['room'] = isset($d['room']) && trim($d['room']) !== '' ? mb_strtoupper(trim($d['room'])) : null;
                $data['meeting_url'] = $d['meeting_url'] ?? null;
                $data['status'] = 'active';
                $this->references($data);
            }
            $this->conflicts($r, [$data], [$id]);
            $this->persist($r, $occurrence, $data, $reason);
            AuditLog::record($r->user(), 'schedule.occurrence.'.$d['action'], $occurrence, $occurrence->institution_id);
        }, 3);
    }

    private function sameResources(array $a, array $b): bool
    {
        return $a['scope'] === 'personal' || count(array_intersect($a['group_ids'], $b['group_ids'])) > 0 || ($a['teacher_membership_id'] !== null && $a['teacher_membership_id'] === $b['teacher_membership_id']) || ($a['room'] !== null && $a['room'] === $b['room']);
    }

    private function compare(array $a, array $b): ?string
    {
        if ($b['status'] !== 'active' || ! $this->sameResources($a, $b)) {
            return null;
        }
        $startA = CarbonImmutable::parse($a['starts_at']);
        $endA = CarbonImmutable::parse($a['ends_at']);
        $startB = CarbonImmutable::parse($b['starts_at']);
        $endB = CarbonImmutable::parse($b['ends_at']);
        if (! $startA->lt($endB) || ! $endA->gt($startB)) {
            return null;
        }
        $exact = $startA->eq($startB) && $endA->eq($endB) && ($a['scope'] === 'institution' || $a['title'] === $b['title']) && $a['subject_id'] === $b['subject_id'] && $a['personal_subject_id'] === $b['personal_subject_id'] && $a['teacher_membership_id'] === $b['teacher_membership_id'] && $a['room'] === $b['room'] && $a['group_ids'] === $b['group_ids'];

        return $exact ? 'duplicate' : 'overlap';
    }

    private function conflicts(Request $r, array $candidates, array $excluded): void
    {
        $overlap = false;
        foreach ($candidates as $index => $candidate) {
            if ($candidate['status'] !== 'active') {
                continue;
            }
            // Une séance dure au plus 12 heures : seules celles commencées dans les 12 heures précédentes peuvent chevaucher.
            $query = CourseOccurrence::query()->where('scope', $candidate['scope'])->where('status', 'active')->whereNotIn('id', $excluded)
                ->where('starts_at', '<', $candidate['ends_at'])->where('ends_at', '>', $candidate['starts_at'])
                ->where('starts_at', '>', CarbonImmutable::parse($candidate['starts_at'])->subHours(12))->with('groups');
            if ($candidate['scope'] === 'personal') {
                $query->where('owner_id', $candidate['owner_id']);
            } else {
                $query->where('institution_id', $candidate['institution_id']);
            }
            foreach ($query->get() as $existing) {
                $conflict = $this->compare($candidate, $this->snapshot($existing));
                $this->calendar->ensure($conflict !== 'duplicate', 'conflict', 'Un doublon exact de cours est interdit, même avec confirmation.');
                $overlap = $overlap || $conflict === 'overlap';
            }
            for ($other = 0; $other < $index; $other++) {
                $conflict = $this->compare($candidate, $candidates[$other]);
                $this->calendar->ensure($conflict !== 'duplicate', 'conflict', 'Un doublon exact est interdit.');
                $overlap = $overlap || $conflict === 'overlap';
            }
        }
        if ($overlap) {
            $this->calendar->ensure($r->boolean('confirm_conflicts') && mb_strlen(trim((string) $r->input('conflict_reason'))) >= 10, 'conflict', 'Chevauchement de groupe, enseignant, salle ou agenda personnel. Confirmez explicitement avec un motif d’au moins 10 caractères.');
            $r->validate(['confirm_conflicts' => 'accepted', 'conflict_reason' => 'required|string|min:10|max:1000']);
            AuditLog::record($r->user(), 'schedule.conflict.confirmed', null, $candidates[0]['institution_id'], null, ['confirmed' => true]);
        }
    }

    public function snapshot(CourseOccurrence $model): array
    {
        $d = $model->only(['course_series_id', 'scope', 'owner_id', 'institution_id', 'academic_year_id', 'title', 'subject_id', 'personal_subject_id', 'teacher_membership_id', 'original_date', 'starts_at', 'ends_at', 'timezone', 'room', 'meeting_url', 'status', 'is_exception']);
        $d['original_date'] = $model->original_date->toDateString();
        $d['group_ids'] = $model->groups->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $d;
    }

    /**
     * Indique si un état de cours référence encore un enseignant habilité et affecté, des groupes et une matière non archivés.
     *
     * @param  array<string, mixed>  $state
     */
    public function referencesStillValid(array $state): bool
    {
        try {
            $this->references($state);

            return true;
        } catch (ValidationException|ModelNotFoundException) {
            return false;
        }
    }

    /**
     * Nombre de cours actifs du même établissement qui chevauchent celui-ci en partageant un groupe, l'enseignant ou la salle.
     */
    public function overlapping(CourseOccurrence $occurrence): int
    {
        $groups = $occurrence->groups->pluck('id');

        return CourseOccurrence::query()->where('scope', 'institution')->where('institution_id', $occurrence->institution_id)->where('status', 'active')
            ->where('id', '!=', $occurrence->id)->where('starts_at', '<', $occurrence->ends_at)->where('ends_at', '>', $occurrence->starts_at)
            ->where(fn ($shared) => $shared->where('teacher_membership_id', $occurrence->teacher_membership_id)
                ->when($occurrence->room !== null, fn ($b) => $b->orWhere('room', $occurrence->room))
                ->orWhereHas('groups', fn ($b) => $b->whereIn('groups.id', $groups)))->count();
    }

    /**
     * Réapplique à une occurrence un état décidé par l'administration (version publiée restaurée, annulation
     * après signalement). Pas de contrôle de conflit : l'état vient d'une publication déjà validée.
     * L'identifiant, la date d'origine et l'historique sont conservés.
     */
    public function restore(Request $r, CourseOccurrence $occurrence, array $state, string $reason): CourseOccurrence
    {
        $data = array_replace($this->snapshot($occurrence), array_intersect_key($state, array_flip(['title', 'subject_id', 'teacher_membership_id', 'starts_at', 'ends_at', 'timezone', 'room', 'meeting_url', 'status', 'is_exception', 'group_ids'])));
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();
        $data['group_ids'] = array_values(array_map('intval', $data['group_ids']));
        sort($data['group_ids']);

        return $this->persist($r, $occurrence, $data, $reason);
    }

    private function persist(Request $r, ?CourseOccurrence $old, array $data, string $reason): CourseOccurrence
    {
        $before = $old ? $this->snapshot($old) : null;
        if ($r->boolean('confirm_conflicts')) {
            $reason = mb_substr($reason.' — Chevauchement confirmé : '.$r->input('conflict_reason'), 0, 1000);
        }
        $groups = $data['group_ids'];
        unset($data['group_ids']);
        $model = $old ?? new CourseOccurrence;
        $model->fill($data);
        if ($old && ! $model->isDirty() && $groups === $before['group_ids']) {
            return $model;
        }
        $model->lock_version = $old ? $old->lock_version + 1 : 1;
        $model->save();
        $pivot = collect($groups)->mapWithKeys(fn ($id) => [$id => ['institution_id' => $model->institution_id, 'academic_year_id' => $model->academic_year_id]])->all();
        if ($old === null) {
            // Nouvelle occurrence : aucune liaison existante à comparer ni à relire. Deux requêtes de moins par occurrence,
            // ce qui compte pour un import de plusieurs milliers d'occurrences. L'instantané ne lit que les identifiants des groupes.
            if ($pivot !== []) {
                $model->groups()->attach($pivot);
            }
            $model->setRelation('groups', (new Group)->newCollection(array_map(fn ($id) => (new Group)->forceFill(['id' => (int) $id]), $groups)));
        } else {
            $model->groups()->sync($pivot);
            $model->load('groups');
        }
        CourseOccurrenceRevision::create(['course_occurrence_id' => $model->id, 'version' => $model->lock_version, 'before' => $before, 'after' => $this->snapshot($model), 'actor_id' => $r->user()->id, 'reason' => $reason]);
        if ($old === null) {
            // Les groupes complets seront relus à la demande si un appelant en a besoin.
            $model->unsetRelation('groups');
        }

        return $model;
    }
}
