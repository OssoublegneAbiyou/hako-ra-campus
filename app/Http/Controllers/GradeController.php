<?php

namespace App\Http\Controllers;

use App\Models\AssessmentResult;
use App\Models\Membership;
use App\Models\PersonalGrade;
use App\Models\PersonalPeriod;
use App\Models\PersonalSubject;
use App\Services\ActiveContext;
use App\Services\Grades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notes du compte connecté : ses notes personnelles, ou les résultats publiés par un de ses établissements.
 * Une seule source est affichée et calculée à la fois ; aucune route ne désigne les notes d'une autre personne.
 */
class GradeController extends Controller
{
    public const DECIMAL = 'regex:/^\d{1,5}([.,]\d{1,3})?$/';

    public function index(Request $r, Grades $grades, ActiveContext $context): Response
    {
        $filters = $r->validate(['source' => 'sometimes|in:personnelle,etablissement', 'appartenance' => 'sometimes|integer|min:1', 'periode' => ['sometimes', 'regex:/^(aucune|\d{1,18})$/']]);
        $user = $r->user();
        $active = $context->current($r);
        $memberships = $grades->learnerMemberships($user);
        $source = $filters['source'] ?? ($active !== null && $memberships->contains('id', $active->id) ? 'etablissement' : 'personnelle');
        $learner = null;
        if ($source === 'etablissement') {
            $wanted = $filters['appartenance'] ?? ($active !== null && $memberships->contains('id', $active->id) ? $active->id : $memberships->first()?->id);
            $learner = $memberships->firstWhere('id', (int) $wanted);
            abort_if($learner === null, 404);
        }
        $periods = $learner ? $grades->publishedPeriods($user, $learner) : $grades->personalPeriods($user);
        $period = $filters['periode'] ?? (collect($periods)->firstWhere('current', true)['value'] ?? ($periods[0]['value'] ?? null));
        if ($learner) {
            abort_if($period === 'aucune', 404);
            $summary = $period !== null && collect($periods)->contains('value', $period) ? $grades->publishedSummary($user, $learner, (int) $period) : null;
        } else {
            abort_unless(collect($periods)->contains('value', $period), 404);
            $summary = $grades->personalSummary($user, $period === 'aucune' ? null : (int) $period);
        }

        return Inertia::render('Grades', [
            'source' => $source,
            'sources' => [['source' => 'personnelle', 'membershipId' => null, 'label' => 'Mes notes personnelles'],
                ...$memberships->map(fn (Membership $membership): array => ['source' => 'etablissement', 'membershipId' => $membership->id,
                    'label' => 'Résultats publiés — '.$membership->institution->name.' ('.$membership->academicYear->name.')'])->all()],
            'membershipId' => $learner?->id, 'periods' => $periods, 'period' => $period, 'summary' => $summary,
            'statuses' => $learner ? AssessmentResult::STATUSES : PersonalGrade::STATUSES,
            'personalSubjects' => PersonalSubject::query()->where('owner_id', $user->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (PersonalSubject $subject): array => ['id' => $subject->id, 'name' => $subject->name])->all(),
            'personalPeriods' => PersonalPeriod::query()->where('owner_id', $user->id)->whereNull('archived_at')->orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on'])
                ->map(fn (PersonalPeriod $one): array => ['id' => $one->id, 'name' => $one->name, 'startsOn' => $one->starts_on->toDateString(), 'endsOn' => $one->ends_on->toDateString()])->all(),
            'today' => now()->toDateString(),
            'teaching' => $active !== null && $active->scope === 'institution' && $active->role === 'teacher',
            'administering' => $active !== null && $active->scope === 'institution' && $active->role === 'institution_admin',
        ]);
    }

    public function store(Request $r, Grades $grades): RedirectResponse|JsonResponse
    {
        $result = $grades->createPersonal($r->user(), $r->validate(['client_key' => 'required|uuid', ...$this->rules()]));

        return $this->answer($r, $result['grade'], $result['replayed'], 'Note personnelle enregistrée. Elle reste privée.');
    }

    public function update(Request $r, Grades $grades, string $grade): RedirectResponse|JsonResponse
    {
        $result = $grades->updatePersonal($r->user(), $grade, $r->validate(['expected_version' => 'required|integer|min:1', ...$this->rules()]));

        return $this->answer($r, $result['grade'], $result['replayed'], 'Note corrigée. Les moyennes indicatives sont recalculées.');
    }

    public function destroy(Request $r, Grades $grades, string $grade): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1']);
        $deleted = $grades->deletePersonal($r->user(), $grade, (int) $data['expected_version']);
        if ($r->expectsJson()) {
            return response()->json(['id' => $grade, 'deleted' => true, 'replayed' => ! $deleted], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Note supprimée. Les moyennes indicatives sont recalculées.');
    }

    public function coefficient(Request $r, Grades $grades, int $subject): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_version' => 'required|integer|min:1', 'coefficient' => ['required', self::DECIMAL]]);
        $updated = $grades->setPersonalCoefficient($r->user(), $subject, $data['coefficient'], (int) $data['expected_version']);
        if ($r->expectsJson()) {
            return response()->json(['id' => $updated->id, 'version' => $updated->lock_version], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Coefficient de la matière enregistré.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return ['title' => 'required|string|max:255', 'personal_subject_id' => 'required|integer|min:1', 'personal_period_id' => 'nullable|integer|min:1',
            'status' => 'required|in:'.implode(',', array_keys(PersonalGrade::STATUSES)), 'value' => ['nullable', self::DECIMAL], 'scale' => ['required', self::DECIMAL],
            'coefficient' => ['required', self::DECIMAL], 'assessed_on' => 'required|date_format:Y-m-d'];
    }

    private function answer(Request $r, PersonalGrade $grade, bool $replayed, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $grade->id, 'version' => $grade->lock_version, 'status' => $grade->status, 'replayed' => $replayed], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('grades', ['source' => 'personnelle', 'periode' => $grade->personal_period_id ?? 'aucune'])->with('status', $message);
    }
}
