<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalGrade;
use App\Models\Subject;
use App\Models\SubjectCoefficient;
use App\Models\TeachingAssignment;
use App\Services\ActiveContext;
use App\Services\ContextVisibility;
use App\Services\Grades;
use App\Services\Tasks;
use App\Support\PrivilegedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Évaluations et résultats d'un enseignant pour ses groupes et matières affectés, et coefficients de matière de l'administration.
 * Aucune note personnelle d'apprenant n'est lisible ici.
 */
class GradebookController extends Controller
{
    public function __construct(private ActiveContext $context, private Grades $grades, private Tasks $tasks, private ContextVisibility $visibility) {}

    public function index(Request $r): Response
    {
        $filters = $r->validate(['evaluation' => 'sometimes|uuid', 'page' => 'sometimes|integer|min:1']);
        $teacher = $this->teacher($r);
        $row = fn (Assessment $assessment): array => [
            'id' => $assessment->id, 'title' => $assessment->title, 'group' => $assessment->group?->name, 'subject' => $assessment->subject?->name, 'period' => $assessment->period?->name,
            'periodId' => $assessment->period_id, 'scale' => $this->plain($assessment->scale), 'coefficient' => $this->plain($assessment->coefficient),
            'assessedOn' => $assessment->assessed_on->toDateString(), 'status' => $assessment->status, 'statusLabel' => Assessment::STATUSES[$assessment->status], 'version' => $assessment->lock_version,
        ];
        $list = $this->grades->managed($r->user(), $teacher)->with(['group:id,name', 'subject:id,name', 'period:id,name'])->orderByDesc('assessed_on')->orderBy('id')->paginate(20)->withQueryString();
        $selected = isset($filters['evaluation']) ? $this->grades->managed($r->user(), $teacher)->with(['group:id,name', 'subject:id,name', 'period:id,name'])->findOrFail($filters['evaluation']) : null;
        $results = $selected?->results()->get()->keyBy('user_id');

        return Inertia::render('Gradebook', [
            'assignments' => $this->tasks->assignments($r->user(), $teacher)->map(fn (TeachingAssignment $pair): array => [
                'groupId' => $pair->group_id, 'subjectId' => $pair->subject_id, 'label' => $pair->group->name.' — '.$pair->subject->name])->values()->all(),
            'periods' => Period::query()->where('institution_id', $teacher->institution_id)->where('academic_year_id', $teacher->academic_year_id)->whereNull('archived_at')->orderBy('starts_on')
                ->get(['id', 'name', 'starts_on', 'ends_on'])->map(fn (Period $period): array => ['id' => $period->id, 'name' => $period->name,
                    'startsOn' => $period->starts_on->toDateString(), 'endsOn' => $period->ends_on->toDateString()])->all(),
            'assessments' => $list->getCollection()->map($row)->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
            'selected' => $selected ? [...$row($selected), 'roster' => $this->grades->roster($selected)->map(fn (array $learner): array => [...$learner,
                'status' => $results->get($learner['userId'])?->status ?? 'missing', 'value' => ($value = $results->get($learner['userId'])?->value) === null ? '' : $this->plain($value)])->all()] : null,
            'statuses' => PersonalGrade::STATUSES, 'missingLabel' => AssessmentResult::STATUSES['missing'], 'canWrite' => PrivilegedAccess::available(), 'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $r): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['client_key' => 'required|uuid', 'group_id' => 'required|integer|min:1', 'subject_id' => 'required|integer|min:1', ...$this->rules()]);
        $result = $this->grades->createAssessment($r->user(), $this->teacher($r), $data);

        return $this->answer($r, $result['assessment'], $result['replayed'], 'Évaluation créée en brouillon. Saisissez les résultats, puis publiez.');
    }

    public function update(Request $r, string $assessment): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', ...$this->rules(), 'reason' => 'nullable|string|max:500']);
        $result = $this->grades->updateAssessment($r->user(), $this->teacher($r), $assessment, $data);

        return $this->answer($r, $result['assessment'], $result['replayed'], $result['replayed'] ? 'Aucun changement à enregistrer.' : 'Évaluation mise à jour. Les moyennes indicatives sont recalculées.');
    }

    public function results(Request $r, string $assessment): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:500', 'results' => 'required|array|min:1|max:500',
            'results.*.user_id' => 'required|integer|min:1|distinct', 'results.*.status' => 'required|in:'.implode(',', array_keys(PersonalGrade::STATUSES)),
            'results.*.value' => ['nullable', GradeController::DECIMAL]]);
        $result = $this->grades->saveResults($r->user(), $this->teacher($r), $assessment, $data);

        return $this->answer($r, $result['assessment'], $result['replayed'], $result['replayed'] ? 'Aucun changement à enregistrer.' : $result['changed'].' résultat(s) enregistré(s).');
    }

    public function publish(Request $r, string $assessment): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1']);
        $result = $this->grades->transition($r->user(), $this->teacher($r), $assessment, 'published', (int) $data['expected_version']);

        return $this->answer($r, $result['assessment'], $result['replayed'], 'Évaluation publiée : chaque apprenant ne voit que son propre résultat.');
    }

    public function destroy(Request $r, string $assessment): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:500']);
        $result = $this->grades->transition($r->user(), $this->teacher($r), $assessment, 'withdrawn', (int) $data['expected_version'], $data['reason'] ?? null);

        return $this->answer($r, $result['assessment'], $result['replayed'], 'Évaluation retirée. Elle ne compte plus dans les moyennes indicatives.');
    }

    public function coefficients(Request $r): Response
    {
        $admin = $this->admin($r);
        $rows = SubjectCoefficient::query()->where('academic_year_id', $admin->academic_year_id)->get()->keyBy('subject_id');

        return Inertia::render('GradeCoefficients', [
            'subjects' => Subject::query()->where('institution_id', $admin->institution_id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject): array => ['id' => $subject->id, 'name' => $subject->name,
                    'coefficient' => isset($rows[$subject->id]) ? $this->plain($rows[$subject->id]->coefficient) : '', 'version' => $rows[$subject->id]->lock_version ?? 0])->all(),
            'year' => $admin->academicYear?->name,
        ]);
    }

    public function setCoefficient(Request $r, int $subject): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:0', 'coefficient' => ['required', GradeController::DECIMAL]]);
        $row = $this->grades->setSubjectCoefficient($r->user(), $this->admin($r), $subject, $data['coefficient'], (int) $data['expected_version']);
        if ($r->expectsJson()) {
            return response()->json(['subject_id' => $row->subject_id, 'version' => $row->lock_version], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Coefficient de la matière enregistré pour cette année.');
    }

    /**
     * Appartenance d'enseignant du contexte actif ; tout autre contexte est refusé.
     */
    private function teacher(Request $r): Membership
    {
        $membership = $this->context->current($r);
        abort_unless($membership !== null && $membership->scope === 'institution' && $membership->role === 'teacher', 403);

        return $membership;
    }

    private function admin(Request $r): Membership
    {
        $membership = $this->context->current($r);
        abort_unless($membership !== null && $membership->scope === 'institution' && $membership->role === 'institution_admin' && PrivilegedAccess::available(), 403);

        return $this->visibility->administeredMembership($r->user(), $membership->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return ['period_id' => 'required|integer|min:1', 'title' => 'required|string|max:255', 'scale' => ['required', GradeController::DECIMAL],
            'coefficient' => ['required', GradeController::DECIMAL], 'assessed_on' => 'required|date_format:Y-m-d'];
    }

    private function plain(string|float|int $decimal): string
    {
        $decimal = (string) $decimal;

        return str_replace('.', ',', str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
    }

    private function answer(Request $r, Assessment $assessment, bool $replayed, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $assessment->id, 'version' => $assessment->lock_version, 'status' => $assessment->status, 'replayed' => $replayed], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('gradebook.index', ['evaluation' => $assessment->id])->with('status', $message);
    }
}
