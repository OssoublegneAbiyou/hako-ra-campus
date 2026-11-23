<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentResultRevision;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Enrollment;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalGrade;
use App\Models\PersonalPeriod;
use App\Models\PersonalSpace;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\SubjectCoefficient;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Support\GradeCalculator;
use App\Support\PrivilegedAccess;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Notes personnelles (privées, propriétaire seul) et résultats publiés par un enseignant affecté.
 * Les deux sources ne sont jamais additionnées : chaque moyenne est calculée sur une seule source.
 */
class Grades
{
    public function __construct(private ContextVisibility $visibility, private Tasks $tasks) {}

    // ---------------------------------------------------------------------------------------------------------------
    // Notes personnelles
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param  array{client_key: string, title: string, personal_subject_id: int, personal_period_id?: int|null, status: string, value?: string|null, scale: string, coefficient: string, assessed_on: string}  $data
     * @return array{grade: PersonalGrade, replayed: bool}
     */
    public function createPersonal(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $this->lockSpace($user);
            $content = $this->personalContent($user, $data, null);
            $hash = $this->hash($content);
            $existing = PersonalGrade::query()->where('owner_id', $user->id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                if ($existing->lock_version > 1 || hash_equals($existing->content_hash, $hash)) {
                    return ['grade' => $existing, 'replayed' => true];
                }
                throw new ConflictHttpException('Cette demande a déjà servi pour une autre note.');
            }

            return ['grade' => PersonalGrade::create([...$content, 'owner_id' => $user->id, 'client_key' => $data['client_key'], 'content_hash' => $hash]), 'replayed' => false];
        }, 3);
    }

    /**
     * @param  array{expected_version: int, title: string, personal_subject_id: int, personal_period_id?: int|null, status: string, value?: string|null, scale: string, coefficient: string, assessed_on: string}  $data
     * @return array{grade: PersonalGrade, replayed: bool}
     */
    public function updatePersonal(User $user, string $gradeId, array $data): array
    {
        return DB::transaction(function () use ($user, $gradeId, $data): array {
            $this->lockSpace($user);
            $grade = PersonalGrade::query()->where('owner_id', $user->id)->lockForUpdate()->findOrFail($gradeId);
            $content = $this->personalContent($user, $data, $grade);
            $hash = $this->hash($content);
            if ((int) $data['expected_version'] !== $grade->lock_version) {
                if ((int) $data['expected_version'] < $grade->lock_version && hash_equals($grade->content_hash, $hash)) {
                    return ['grade' => $grade, 'replayed' => true];
                }
                throw new ConflictHttpException('Cette note a été modifiée ailleurs. Rechargez-la avant de l’enregistrer.');
            }
            $grade->fill([...$content, 'content_hash' => $hash, 'lock_version' => $grade->lock_version + 1])->save();

            return ['grade' => $grade, 'replayed' => false];
        }, 3);
    }

    /**
     * Suppression définitive d'une note personnelle. Rejouable : une note déjà absente ne produit aucune erreur ni aucune information.
     */
    public function deletePersonal(User $user, string $gradeId, int $expectedVersion): bool
    {
        return DB::transaction(function () use ($user, $gradeId, $expectedVersion): bool {
            $this->lockSpace($user);
            $grade = PersonalGrade::query()->where('owner_id', $user->id)->lockForUpdate()->find($gradeId);
            if ($grade === null) {
                return false;
            }
            if ($expectedVersion !== $grade->lock_version) {
                throw new ConflictHttpException('Cette note a été modifiée ailleurs. Rechargez-la avant de la supprimer.');
            }

            return (bool) $grade->delete();
        }, 3);
    }

    public function setPersonalCoefficient(User $user, int $subjectId, string $coefficient, int $expectedVersion): PersonalSubject
    {
        return DB::transaction(function () use ($user, $subjectId, $coefficient, $expectedVersion): PersonalSubject {
            $this->lockSpace($user);
            $subject = PersonalSubject::query()->where('owner_id', $user->id)->lockForUpdate()->findOrFail($subjectId);
            $coefficient = $this->positive($coefficient, 'coefficient', 'Le coefficient doit être strictement positif.');
            if (BigDecimal::of($subject->grade_coefficient)->isEqualTo($coefficient)) {
                return $subject;
            }
            if ($expectedVersion !== $subject->lock_version) {
                throw new ConflictHttpException('Cette matière a été modifiée ailleurs. Rechargez-la avant de l’enregistrer.');
            }
            $subject->forceFill(['grade_coefficient' => $coefficient, 'lock_version' => $subject->lock_version + 1])->save();

            return $subject;
        }, 3);
    }

    /**
     * Périodes proposées pour les notes personnelles : celles du compte, plus « aucune » si des notes n'ont pas de période.
     *
     * @return list<array{value: string, label: string, current: bool}>
     */
    public function personalPeriods(User $user): array
    {
        $today = now()->toDateString();
        $periods = PersonalPeriod::query()->where('owner_id', $user->id)->orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on'])
            ->map(fn (PersonalPeriod $period): array => ['value' => (string) $period->id, 'label' => $period->name,
                'current' => $period->starts_on->toDateString() <= $today && $period->ends_on->toDateString() >= $today])->all();

        return [...$periods, ['value' => 'aucune', 'label' => 'Sans période', 'current' => false]];
    }

    /**
     * Notes personnelles d'une période (ou sans période) et leurs moyennes indicatives.
     *
     * @return array<string, mixed>
     */
    public function personalSummary(User $user, ?int $periodId): array
    {
        $grades = PersonalGrade::query()->where('owner_id', $user->id)
            ->when($periodId === null, fn (Builder $query) => $query->whereNull('personal_period_id'), fn (Builder $query) => $query->where('personal_period_id', $periodId))
            ->orderBy('assessed_on')->orderBy('created_at')->get()->groupBy('personal_subject_id');
        $subjects = PersonalSubject::query()->where('owner_id', $user->id)->where(fn (Builder $query) => $query->whereNull('archived_at')->orWhereIn('id', $grades->keys()))
            ->orderBy('name')->get()->map(fn (PersonalSubject $subject): array => [
                'id' => $subject->id, 'name' => $subject->name, 'coefficient' => (string) $subject->grade_coefficient, 'coefficientVersion' => $subject->lock_version,
                'grades' => $grades->get($subject->id, collect())->map(fn (PersonalGrade $grade): array => [
                    'id' => $grade->id, 'title' => $grade->title, 'status' => $grade->status, 'value' => $grade->value, 'scale' => (string) $grade->scale,
                    'coefficient' => (string) $grade->coefficient, 'assessedOn' => $grade->assessed_on->toDateString(), 'version' => $grade->lock_version,
                    'periodId' => $grade->personal_period_id,
                ])->all(),
            ])->all();

        return $this->summarize($subjects, 'personal');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Résultats publiés, vus par l'apprenant
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Appartenances d'apprenant utilisables du compte : une vue de résultats publiés par établissement et par année.
     *
     * @return Collection<int, Membership>
     */
    public function learnerMemberships(User $user): Collection
    {
        return $this->visibility->usableMemberships($user)->where('scope', 'institution')->where('role', 'learner')->with(['institution:id,name', 'academicYear:id,name'])->orderBy('id')->get();
    }

    /**
     * Évaluations publiées concernant l'apprenant : celles où il a un résultat, et celles des groupes où il est
     * effectivement inscrit (note manquante si aucun résultat). Jamais un brouillon ni une évaluation retirée.
     */
    private function publishedFor(User $user, Membership $learner): Builder
    {
        $groups = Enrollment::query()->effective()->where('user_id', $user->id)->where('membership_id', $learner->id)->pluck('group_id');

        return Assessment::query()->where('status', 'published')->where('institution_id', $learner->institution_id)->where('academic_year_id', $learner->academic_year_id)
            ->where(fn (Builder $query) => $query->whereIn('group_id', $groups)->orWhereHas('results', fn (Builder $result) => $result->where('user_id', $user->id)));
    }

    /**
     * @return list<array{value: string, label: string, current: bool}>
     */
    public function publishedPeriods(User $user, Membership $learner): array
    {
        $learner = $this->learner($user, $learner->id);
        $today = now()->toDateString();

        return Period::query()->whereIn('id', $this->publishedFor($user, $learner)->select('period_id'))->orderBy('starts_on')->get(['id', 'name', 'starts_on', 'ends_on'])
            ->map(fn (Period $period): array => ['value' => (string) $period->id, 'label' => $period->name,
                'current' => $period->starts_on->toDateString() <= $today && $period->ends_on->toDateString() >= $today])->all();
    }

    /**
     * Résultats publiés d'une période pour cet apprenant, avec moyennes indicatives. Seuls ses propres résultats sont lus.
     *
     * @return array<string, mixed>
     */
    public function publishedSummary(User $user, Membership $learner, int $periodId): array
    {
        $learner = $this->learner($user, $learner->id);
        $assessments = $this->publishedFor($user, $learner)->where('period_id', $periodId)
            ->with(['results' => fn ($result) => $result->where('user_id', $user->id)])->orderBy('assessed_on')->orderBy('created_at')->get()->groupBy('subject_id');
        $coefficients = SubjectCoefficient::query()->where('academic_year_id', $learner->academic_year_id)->whereIn('subject_id', $assessments->keys())->pluck('coefficient', 'subject_id');
        $subjects = Subject::query()->whereIn('id', $assessments->keys())->orderBy('name')->get()->map(fn (Subject $subject): array => [
            'id' => $subject->id, 'name' => $subject->name, 'coefficient' => isset($coefficients[$subject->id]) ? (string) $coefficients[$subject->id] : null, 'coefficientVersion' => null,
            'grades' => $assessments->get($subject->id)->map(function (Assessment $assessment): array {
                $result = $assessment->results->first();

                return ['id' => $assessment->id, 'title' => $assessment->title, 'status' => $result?->status ?? 'missing', 'value' => $result?->value, 'scale' => (string) $assessment->scale,
                    'coefficient' => (string) $assessment->coefficient, 'assessedOn' => $assessment->assessed_on->toDateString(), 'version' => null, 'periodId' => $assessment->period_id];
            })->all(),
        ])->all();

        return $this->summarize($subjects, 'institutional');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Évaluations gérées par l'enseignant affecté
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Évaluations que l'enseignant peut gérer maintenant : celles des couples groupe/matière de ses affectations effectives.
     */
    public function managed(User $user, Membership $teacher): Builder
    {
        $pairs = $this->tasks->assignments($user, $teacher);

        return Assessment::query()->where('institution_id', $teacher->institution_id)->where('academic_year_id', $teacher->academic_year_id)
            ->where(fn (Builder $any) => $pairs->isEmpty() ? $any->whereRaw('1 = 0')
                : $pairs->each(fn (TeachingAssignment $pair) => $any->orWhere(fn (Builder $one) => $one->where('group_id', $pair->group_id)->where('subject_id', $pair->subject_id))));
    }

    /**
     * Apprenants effectivement inscrits au groupe maintenant, plus ceux qui ont déjà un résultat à cette évaluation.
     *
     * @return Collection<int, array{userId: int, name: string, identifier: string|null, enrolled: bool}>
     */
    public function roster(Assessment $assessment): Collection
    {
        $enrolled = Enrollment::query()->effective()->where('group_id', $assessment->group_id)->whereHas('membership', fn (Builder $membership) => $membership->effective())
            ->with(['user:id,name', 'membership:id,local_identifier'])->get()
            ->mapWithKeys(fn (Enrollment $enrollment): array => [$enrollment->user_id => ['userId' => $enrollment->user_id, 'name' => $enrollment->user->name,
                'identifier' => $enrollment->membership->local_identifier, 'enrolled' => true]]);
        $former = User::query()->whereIn('id', $assessment->results()->whereNotIn('user_id', $enrolled->keys())->select('user_id'))->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => ['userId' => $user->id, 'name' => $user->name, 'identifier' => null, 'enrolled' => false]]);

        return $enrolled->union($former)->sortBy('name')->values();
    }

    /**
     * @param  array{client_key: string, group_id: int, subject_id: int, period_id: int, title: string, scale: string, coefficient: string, assessed_on: string}  $data
     * @return array{assessment: Assessment, replayed: bool}
     */
    public function createAssessment(User $user, Membership $teacher, array $data): array
    {
        return DB::transaction(function () use ($user, $teacher, $data): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $content = ['group_id' => (int) $data['group_id'], 'subject_id' => (int) $data['subject_id'], ...$this->assessmentContent($teacher, $data)];
            $hash = $this->hash($content);
            $existing = Assessment::query()->where('author_membership_id', $teacher->id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                if ($existing->lock_version > 1 || hash_equals($existing->content_hash, $hash)) {
                    return ['assessment' => $existing, 'replayed' => true];
                }
                throw new ConflictHttpException('Cette demande a déjà servi pour une autre évaluation.');
            }
            if (! $this->tasks->assignments($user, $teacher)->contains(fn (TeachingAssignment $pair) => $pair->group_id === $content['group_id'] && $pair->subject_id === $content['subject_id'])) {
                throw (new ModelNotFoundException)->setModel(TeachingAssignment::class);
            }
            $assessment = Assessment::create([...$content, 'institution_id' => $teacher->institution_id, 'academic_year_id' => $teacher->academic_year_id,
                'author_membership_id' => $teacher->id, 'client_key' => $data['client_key'], 'content_hash' => $hash]);
            $this->record($user, $assessment, 'assessment.created');

            return ['assessment' => $assessment, 'replayed' => false];
        }, 3);
    }

    /**
     * Modifie le barème, le coefficient, la date, la période ou le titre. Sur une évaluation publiée, c'est une correction :
     * motif obligatoire, journal, et un événement par version. Les moyennes sont recalculées à la lecture suivante.
     *
     * @param  array{expected_version: int, period_id: int, title: string, scale: string, coefficient: string, assessed_on: string, reason?: string|null}  $data
     * @return array{assessment: Assessment, replayed: bool}
     */
    public function updateAssessment(User $user, Membership $teacher, string $assessmentId, array $data): array
    {
        return DB::transaction(function () use ($user, $teacher, $assessmentId, $data): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $assessment = $this->managed($user, $teacher)->where('status', '!=', 'withdrawn')->lockForUpdate()->findOrFail($assessmentId);
            $content = $this->assessmentContent($teacher, $data);
            $before = ['period_id' => $assessment->period_id, 'title' => $assessment->title, 'scale' => GradeCalculator::decimal((string) $assessment->scale),
                'coefficient' => GradeCalculator::decimal((string) $assessment->coefficient), 'assessed_on' => $assessment->assessed_on->toDateString()];
            $changed = array_keys(array_filter($content, fn ($value, string $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
            if ($changed === [] || (int) $data['expected_version'] !== $assessment->lock_version) {
                if ($changed === [] && (int) $data['expected_version'] <= $assessment->lock_version) {
                    return ['assessment' => $assessment, 'replayed' => true];
                }
                throw new ConflictHttpException('Cette évaluation a été modifiée ailleurs. Rechargez-la avant de l’enregistrer.');
            }
            if (in_array('scale', $changed, true) && $assessment->results()->where('value', '>', $content['scale'])->exists()) {
                throw ValidationException::withMessages(['scale' => 'Un résultat déjà saisi dépasse ce barème. Corrigez-le d’abord.']);
            }
            $reason = $this->reason($assessment, $data['reason'] ?? null);
            $assessment->fill([...$content, 'lock_version' => $assessment->lock_version + 1])->save();
            $this->record($user, $assessment, $assessment->status === 'published' ? 'assessment.corrected' : 'assessment.updated', $reason, ['changed' => $changed]);
            if ($assessment->status === 'published') {
                $this->event($assessment, 'grade.corrected', 'grade-corrected:'.$assessment->id.':v'.$assessment->lock_version, ['changed' => $changed]);
            }

            return ['assessment' => $assessment, 'replayed' => false];
        }, 3);
    }

    /**
     * Saisit ou corrige les résultats. Seuls les résultats réellement modifiés changent de version ; chacun laisse une révision
     * (valeur, barème, auteur, motif). Sur une évaluation publiée, le motif est obligatoire.
     *
     * @param  array{expected_version: int, reason?: string|null, results: list<array{user_id: int, status: string, value?: string|null}>}  $data
     * @return array{assessment: Assessment, replayed: bool, changed: int}
     */
    public function saveResults(User $user, Membership $teacher, string $assessmentId, array $data): array
    {
        return DB::transaction(function () use ($user, $teacher, $assessmentId, $data): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $assessment = $this->managed($user, $teacher)->where('status', '!=', 'withdrawn')->lockForUpdate()->findOrFail($assessmentId);
            $existing = $assessment->results()->lockForUpdate()->get()->keyBy('user_id');
            $allowed = $this->roster($assessment)->pluck('userId')->flip();
            $changes = [];
            foreach (array_values($data['results']) as $index => $row) {
                $userId = (int) $row['user_id'];
                if (! isset($allowed[$userId])) {
                    throw ValidationException::withMessages(["results.$index.user_id" => 'Cette personne n’est pas inscrite dans ce groupe.']);
                }
                $value = $this->value($row['status'], $row['value'] ?? null, (string) $assessment->scale, "results.$index.value");
                $current = $existing->get($userId);
                if ($current === null || $current->status !== $row['status'] || ($current->value === null ? null : GradeCalculator::decimal((string) $current->value)) !== $value) {
                    $changes[$userId] = ['status' => $row['status'], 'value' => $value];
                }
            }
            if ($changes === [] || (int) $data['expected_version'] !== $assessment->lock_version) {
                if ($changes === [] && (int) $data['expected_version'] <= $assessment->lock_version) {
                    return ['assessment' => $assessment, 'replayed' => true, 'changed' => 0];
                }
                throw new ConflictHttpException('Ces résultats ont été modifiés ailleurs. Rechargez-les avant de les enregistrer.');
            }
            $reason = $this->reason($assessment, $data['reason'] ?? null);
            foreach ($changes as $userId => $change) {
                $result = $existing->get($userId) ?? new AssessmentResult(['assessment_id' => $assessment->id, 'user_id' => $userId]);
                $result->fill([...$change, 'version' => $result->version + 1])->save();
                AssessmentResultRevision::create(['assessment_result_id' => $result->id, 'version' => $result->version, ...$change, 'scale' => $assessment->scale,
                    'actor_membership_id' => $teacher->id, 'reason' => $reason, 'created_at' => now()]);
            }
            $assessment->fill(['lock_version' => $assessment->lock_version + 1])->save();
            $learners = array_keys($changes);
            // Le journal et l'événement désignent les apprenants concernés, jamais les valeurs.
            $this->record($user, $assessment, $assessment->status === 'published' ? 'assessment.results_corrected' : 'assessment.results_saved', $reason, ['learner_ids' => $learners]);
            if ($assessment->status === 'published') {
                $this->event($assessment, 'grade.corrected', 'grade-corrected:'.$assessment->id.':v'.$assessment->lock_version, ['learner_ids' => $learners]);
            }

            return ['assessment' => $assessment, 'replayed' => false, 'changed' => count($changes)];
        }, 3);
    }

    /**
     * Publie (brouillon → publiée) ou retire une évaluation. Rejouable : l'état déjà atteint est renvoyé tel quel.
     *
     * @return array{assessment: Assessment, replayed: bool}
     */
    public function transition(User $user, Membership $teacher, string $assessmentId, string $target, int $expectedVersion, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $teacher, $assessmentId, $target, $expectedVersion, $reason): array {
            $teacher = $this->lockTeacher($user, $teacher);
            $assessment = $this->managed($user, $teacher)->lockForUpdate()->findOrFail($assessmentId);
            if ($assessment->status === $target) {
                return ['assessment' => $assessment, 'replayed' => true];
            }
            if ($expectedVersion !== $assessment->lock_version) {
                throw new ConflictHttpException('Cette évaluation a été modifiée ailleurs. Rechargez-la avant de continuer.');
            }
            if ($target === 'published' && $assessment->status !== 'draft') {
                throw ValidationException::withMessages(['reference' => 'Une évaluation retirée ne peut pas être publiée de nouveau. Créez-en une nouvelle.']);
            }
            $wasPublished = $assessment->status === 'published';
            $reason = $target === 'withdrawn' ? $this->reason($assessment, $reason) : null;
            $assessment->fill(['status' => $target, $target === 'published' ? 'published_at' : 'withdrawn_at' => now(), 'lock_version' => $assessment->lock_version + 1])->save();
            $this->record($user, $assessment, 'assessment.'.$target, $reason);
            if ($target === 'published' || $wasPublished) {
                $this->event($assessment, 'grade.'.$target, 'grade-'.$target.':'.$assessment->id);
            }

            return ['assessment' => $assessment, 'replayed' => false];
        }, 3);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Coefficients de matière fixés par l'administration de l'établissement
    // ---------------------------------------------------------------------------------------------------------------

    public function setSubjectCoefficient(User $user, Membership $admin, int $subjectId, string $coefficient, int $expectedVersion): SubjectCoefficient
    {
        return DB::transaction(function () use ($user, $admin, $subjectId, $coefficient, $expectedVersion): SubjectCoefficient {
            abort_unless(PrivilegedAccess::available(), 403);
            Membership::query()->lockForUpdate()->findOrFail($admin->id);
            $admin = $this->visibility->administeredMembership($user, $admin->id);
            $subject = Subject::query()->where('institution_id', $admin->institution_id)->whereNull('archived_at')->findOrFail($subjectId);
            $coefficient = $this->positive($coefficient, 'coefficient', 'Le coefficient doit être strictement positif.');
            $row = SubjectCoefficient::query()->where('academic_year_id', $admin->academic_year_id)->where('subject_id', $subject->id)->lockForUpdate()->first();
            if ($row !== null && BigDecimal::of($row->coefficient)->isEqualTo($coefficient)) {
                return $row;
            }
            if ($expectedVersion !== ($row?->lock_version ?? 0)) {
                throw new ConflictHttpException('Ce coefficient a été modifié ailleurs. Rechargez la page avant de l’enregistrer.');
            }
            $row ??= new SubjectCoefficient(['institution_id' => $admin->institution_id, 'academic_year_id' => $admin->academic_year_id, 'subject_id' => $subject->id, 'lock_version' => 0]);
            $row->fill(['coefficient' => $coefficient, 'lock_version' => $row->lock_version + 1])->save();
            AuditLog::record($user, 'subject_coefficient.set', $row, $admin->institution_id, null, ['subject_id' => $subject->id, 'academic_year_id' => $admin->academic_year_id, 'coefficient' => $coefficient]);

            return $row;
        }, 3);
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Calcule, pour une seule source, les moyennes de matière et la moyenne générale, avec leur couverture.
     *
     * @param  list<array<string, mixed>>  $subjects
     * @return array<string, mixed>
     */
    private function summarize(array $subjects, string $provenance): array
    {
        $threshold = (string) config('campus.grades.watch_threshold');
        $averages = [];
        foreach ($subjects as $index => $subject) {
            $result = GradeCalculator::subject($subject['grades']);
            $averages[] = ['average' => $result['average'], 'coefficient' => $subject['coefficient']];
            $subjects[$index] = [...$subject,
                'average' => GradeCalculator::display($result['average']), 'retained' => $result['retained'], 'total' => $result['total'],
                // Repère explicable : moyenne indicative strictement sous le seuil. Aucune autre interprétation.
                'watch' => GradeCalculator::belowThreshold($result['average'], $threshold),
                'grades' => array_map(fn (array $grade): array => [...$grade, 'provenance' => $provenance,
                    'value' => $grade['value'] === null ? null : $this->trim((string) $grade['value']), 'scale' => $this->trim($grade['scale']), 'coefficient' => $this->trim($grade['coefficient']),
                    'normalized' => $grade['status'] === GradeCalculator::RETAINED && $grade['value'] !== null ? GradeCalculator::display(GradeCalculator::normalized((string) $grade['value'], $grade['scale'])) : null,
                    'retained' => $grade['status'] === GradeCalculator::RETAINED], $subject['grades']),
                'coefficient' => $subject['coefficient'] === null ? null : $this->trim($subject['coefficient']),
            ];
        }
        $general = GradeCalculator::general($averages);

        return ['provenance' => $provenance, 'subjects' => $subjects, 'threshold' => $this->trim(GradeCalculator::decimal($threshold)),
            'general' => ['average' => GradeCalculator::display($general['average']), 'counted' => $general['counted'], 'total' => $general['total'], 'missingCoefficients' => $general['missingCoefficients']]];
    }

    /**
     * Écriture d'affichage d'un décimal : sans zéros inutiles, virgule décimale.
     */
    private function trim(string $decimal): string
    {
        return str_replace('.', ',', str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personalContent(User $user, array $data, ?PersonalGrade $current): array
    {
        $subjectId = (int) $data['personal_subject_id'];
        $periodId = isset($data['personal_period_id']) ? (int) $data['personal_period_id'] : null;
        if ($subjectId !== $current?->personal_subject_id && ! PersonalSubject::query()->where('owner_id', $user->id)->whereNull('archived_at')->whereKey($subjectId)->exists()) {
            throw ValidationException::withMessages(['personal_subject_id' => 'Cette matière est indisponible.']);
        }
        $date = CarbonImmutable::parse($data['assessed_on'])->toDateString();
        if ($periodId !== null) {
            $period = PersonalPeriod::query()->where('owner_id', $user->id)->find($periodId);
            if ($period === null || ($period->archived_at !== null && $periodId !== $current?->personal_period_id)) {
                throw ValidationException::withMessages(['personal_period_id' => 'Cette période est indisponible.']);
            }
            if ($date < $period->starts_on->toDateString() || $date > $period->ends_on->toDateString()) {
                throw ValidationException::withMessages(['assessed_on' => 'La date doit être comprise dans la période choisie.']);
            }
        }
        $scale = $this->positive($data['scale'], 'scale', 'Le barème doit être strictement positif.');

        return ['title' => trim($data['title']), 'personal_subject_id' => $subjectId, 'personal_period_id' => $periodId, 'status' => $data['status'],
            'value' => $this->value($data['status'], $data['value'] ?? null, $scale, 'value'), 'scale' => $scale,
            'coefficient' => $this->positive($data['coefficient'], 'coefficient', 'Le coefficient doit être strictement positif.'), 'assessed_on' => $date];
    }

    /**
     * Période de la même année et du même établissement que l'enseignant, date dans la période, barème et coefficient positifs.
     *
     * @param  array<string, mixed>  $data
     * @return array{period_id: int, title: string, scale: string, coefficient: string, assessed_on: string}
     */
    private function assessmentContent(Membership $teacher, array $data): array
    {
        $period = Period::query()->where('institution_id', $teacher->institution_id)->where('academic_year_id', $teacher->academic_year_id)->whereNull('archived_at')->find((int) $data['period_id']);
        if ($period === null) {
            throw ValidationException::withMessages(['period_id' => 'Cette période n’appartient pas à l’année en cours de cet établissement.']);
        }
        $date = CarbonImmutable::parse($data['assessed_on'])->toDateString();
        if ($date < $period->starts_on->toDateString() || $date > $period->ends_on->toDateString()) {
            throw ValidationException::withMessages(['assessed_on' => 'La date doit être comprise dans la période choisie.']);
        }

        return ['period_id' => $period->id, 'title' => trim($data['title']), 'scale' => $this->positive($data['scale'], 'scale', 'Le barème doit être strictement positif.'),
            'coefficient' => $this->positive($data['coefficient'], 'coefficient', 'Le coefficient doit être strictement positif.'), 'assessed_on' => $date];
    }

    private function positive(string $input, string $field, string $message): string
    {
        $decimal = GradeCalculator::decimal($input);
        if (! BigDecimal::of($decimal)->isPositive()) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $decimal;
    }

    /**
     * Une valeur n'existe que pour une note chiffrée, entre zéro et le barème ; zéro est une vraie note.
     */
    private function value(string $status, ?string $input, string $scale, string $field): ?string
    {
        if ($status !== 'graded') {
            if ($input !== null && trim($input) !== '') {
                throw ValidationException::withMessages([$field => 'Aucune valeur n’est attendue pour cet état : il ne vaut pas zéro.']);
            }

            return null;
        }
        if ($input === null || trim($input) === '') {
            throw ValidationException::withMessages([$field => 'Indiquez la note, ou choisissez un autre état (à venir, absence, dispense, non rendu).']);
        }
        $value = GradeCalculator::decimal($input);
        if (BigDecimal::of($value)->isGreaterThan(BigDecimal::of($scale))) {
            throw ValidationException::withMessages([$field => 'La note doit être comprise entre zéro et le barème.']);
        }

        return $value;
    }

    private function reason(Assessment $assessment, ?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($assessment->status === 'published' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif : cette évaluation est déjà publiée.']);
        }

        return $reason === '' ? null : $reason;
    }

    private function learner(User $user, int $membershipId): Membership
    {
        $membership = $this->visibility->institutionMembership($user, $membershipId);
        abort_unless($membership->role === 'learner', 404);

        return $membership;
    }

    private function lockSpace(User $user): void
    {
        $space = PersonalSpace::query()->where('owner_id', $user->id)->lockForUpdate()->firstOrFail();
        $this->visibility->personalSpace($user, $space->id);
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
     * @param  array<string, mixed>  $content
     */
    private function hash(array $content): string
    {
        ksort($content);

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $user, Assessment $assessment, string $action, ?string $reason = null, array $metadata = []): void
    {
        AuditLog::record($user, $action, $assessment, $assessment->institution_id, $reason,
            ['group_id' => $assessment->group_id, 'subject_id' => $assessment->subject_id, 'period_id' => $assessment->period_id, 'version' => $assessment->lock_version, ...$metadata]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(Assessment $assessment, string $type, string $dedupKey, array $extra = []): void
    {
        // Fait métier pour le lot 13 : ni note ni titre ; les destinataires seront recalculés à l'envoi.
        DomainEvent::create(['type' => $type, 'dedup_key' => $dedupKey, 'subject_uuid' => $assessment->id, 'occurred_at' => now(),
            'payload' => ['assessment_id' => $assessment->id, 'institution_id' => $assessment->institution_id, 'academic_year_id' => $assessment->academic_year_id,
                'group_id' => $assessment->group_id, 'subject_id' => $assessment->subject_id, 'version' => $assessment->lock_version, ...$extra]]);
    }
}
