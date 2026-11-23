<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentResultRevision;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalGrade;
use App\Models\PersonalPeriod;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\SubjectCoefficient;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\ActiveContext;
use App\Support\GradeCalculator;
use Brick\Math\BigRational;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class GradeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $name, string $letter = 'A'): Membership
    {
        return $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail();
    }

    private function as(string $name, ?string $letter = null): static
    {
        return $this->freshDevice()->actingAs($this->user($name))
            ->withSession($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []);
    }

    private function personalSubject(string $name, string $owner = 'independant'): PersonalSubject
    {
        return PersonalSubject::factory()->create(['owner_id' => $this->user($owner)->id, 'name' => $name]);
    }

    private function personalPeriod(string $owner = 'independant'): PersonalPeriod
    {
        return PersonalPeriod::factory()->create(['owner_id' => $this->user($owner)->id, 'name' => 'Semestre fictif personnel']);
    }

    /** Enregistre une note personnelle par la route réelle. */
    private function grade(PersonalSubject $subject, string $value, string $scale, string $coefficient = '1', array $data = [], string $owner = 'independant'): PersonalGrade
    {
        $payload = ['client_key' => (string) Str::uuid(), 'title' => 'Évaluation fictive personnelle', 'personal_subject_id' => $subject->id, 'status' => 'graded',
            'value' => $value, 'scale' => $scale, 'coefficient' => $coefficient, 'assessed_on' => '2026-11-10', ...$data];
        $this->as($owner)->post('/notes', $payload)->assertSessionHasNoErrors();

        return PersonalGrade::where('client_key', $payload['client_key'])->firstOrFail();
    }

    /** Vue « notes personnelles » du compte, pour la période donnée (ou sans période). */
    private function personal(string $name = 'independant', string $period = 'aucune'): array
    {
        return $this->as($name)->get('/notes?source=personnelle&periode='.$period)->assertOk()->viewData('page')['props']['summary'];
    }

    /** Vue « résultats publiés » de l'apprenant pour l'établissement A, période S1. */
    private function published(string $name): ?array
    {
        return $this->as($name)->get('/notes?source=etablissement&appartenance='.$this->member($name)->id.'&periode='.$this->period()->id)->assertOk()->viewData('page')['props']['summary'];
    }

    private function period(string $letter = 'A'): Period
    {
        return Period::where('institution_id', $this->member('admin-'.strtolower($letter), $letter)->institution_id)->where('code', 'S1')->firstOrFail();
    }

    private function methodology(): Subject
    {
        return Subject::where('institution_id', $this->member('enseignant-a')->institution_id)->where('code', 'METHODO')->firstOrFail();
    }

    /** Seconde matière de l'établissement A, affectée à l'enseignant fictif pour A-G1. */
    private function french(): Subject
    {
        $teacher = $this->member('enseignant-a');
        $subject = Subject::factory()->create(['institution_id' => $teacher->institution_id, 'name' => 'Français fictif']);
        TeachingAssignment::create(['membership_id' => $teacher->id, 'user_id' => $teacher->user_id, 'institution_id' => $teacher->institution_id, 'academic_year_id' => $teacher->academic_year_id,
            'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id, 'subject_id' => $subject->id, 'starts_at' => '2026-09-01 00:00:00']);

        return $subject;
    }

    private function assessmentPayload(array $data = []): array
    {
        return ['client_key' => (string) Str::uuid(), 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id, 'subject_id' => $this->methodology()->id,
            'period_id' => $this->period()->id, 'title' => 'Contrôle fictif', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10', ...$data];
    }

    private function assessment(array $data = []): Assessment
    {
        $payload = $this->assessmentPayload($data);
        $this->as('enseignant-a', 'A')->post('/notes/evaluations', $payload)->assertSessionHasNoErrors();

        return Assessment::where('client_key', $payload['client_key'])->firstOrFail();
    }

    /** @param array<string, array{0: string, 1?: string|null}> $results nom du compte => [état, valeur] */
    private function enter(Assessment $assessment, array $results, array $extra = [], bool $json = false): TestResponse
    {
        $payload = ['expected_version' => $assessment->fresh()->lock_version, ...$extra,
            'results' => collect($results)->map(fn (array $result, string $name) => ['user_id' => $this->user($name)->id, 'status' => $result[0], 'value' => $result[1] ?? null])->values()->all()];
        $url = '/notes/evaluations/'.$assessment->id.'/resultats';

        return $json ? $this->as('enseignant-a', 'A')->putJson($url, $payload) : $this->as('enseignant-a', 'A')->put($url, $payload);
    }

    private function publish(Assessment $assessment): void
    {
        $this->as('enseignant-a', 'A')->post('/notes/evaluations/'.$assessment->id.'/publication', ['expected_version' => $assessment->fresh()->lock_version])->assertSessionHasNoErrors();
    }

    /** Évaluation publiée avec les résultats donnés. */
    private function graded(array $results, array $data = []): Assessment
    {
        $assessment = $this->assessment($data);
        $this->enter($assessment, $results)->assertSessionHasNoErrors();
        $this->publish($assessment);

        return $assessment->fresh();
    }

    public function test_r26_r27_the_calculator_uses_exact_values_and_rounds_only_for_display(): void
    {
        $maths = GradeCalculator::subject([
            ['status' => 'graded', 'value' => '14', 'scale' => '20', 'coefficient' => '1'],
            ['status' => 'graded', 'value' => '36', 'scale' => '60', 'coefficient' => '2'],
        ]);
        $this->assertTrue($maths['average']->isEqualTo(BigRational::of('38/3')));
        $this->assertSame(['12,67', 2, 2], [GradeCalculator::display($maths['average']), $maths['retained'], $maths['total']]);
        $this->assertSame('12,00', GradeCalculator::display(GradeCalculator::normalized('36', '60')));

        $french = GradeCalculator::subject([['status' => 'graded', 'value' => '15', 'scale' => '20', 'coefficient' => '1']]);
        $general = GradeCalculator::general([['average' => $maths['average'], 'coefficient' => '3'], ['average' => $french['average'], 'coefficient' => '2']]);
        $this->assertTrue($general['average']->isEqualTo(BigRational::of('68/5')));
        $this->assertSame('13,60', GradeCalculator::display($general['average']));

        // Preuve que la moyenne générale part des valeurs non arrondies : 12,665 et 12 donnent 12,33 ; avec 12,67 arrondi on lirait 12,34.
        $unrounded = GradeCalculator::subject([['status' => 'graded', 'value' => '12.665', 'scale' => '20', 'coefficient' => '1']]);
        $this->assertSame('12,67', GradeCalculator::display($unrounded['average']));
        $whole = GradeCalculator::subject([['status' => 'graded', 'value' => '12', 'scale' => '20', 'coefficient' => '1']]);
        $this->assertSame('12,33', GradeCalculator::display(GradeCalculator::general([['average' => $unrounded['average'], 'coefficient' => '1'], ['average' => $whole['average'], 'coefficient' => '1']])['average']));

        // Matière sans note : ignorée, pas comptée pour zéro. Coefficient non défini : pas de moyenne générale.
        $empty = GradeCalculator::subject([['status' => 'absent', 'value' => null, 'scale' => '20', 'coefficient' => '5']]);
        $this->assertSame([null, 0, 1], [$empty['average'], $empty['retained'], $empty['total']]);
        $with = GradeCalculator::general([['average' => $maths['average'], 'coefficient' => '3'], ['average' => $french['average'], 'coefficient' => '2'], ['average' => null, 'coefficient' => '9']]);
        $this->assertSame(['13,60', 2, 3], [GradeCalculator::display($with['average']), $with['counted'], $with['total']]);
        $undefined = GradeCalculator::general([['average' => $maths['average'], 'coefficient' => null], ['average' => $french['average'], 'coefficient' => '2']]);
        $this->assertSame([null, 1], [$undefined['average'], $undefined['missingCoefficients']]);
        $this->assertSame([null, 0, 0], [GradeCalculator::general([])['average'], GradeCalculator::general([])['counted'], GradeCalculator::general([])['total']]);

        // Une vraie note zéro compte ; le seuil est strict.
        $zero = GradeCalculator::subject([['status' => 'graded', 'value' => '0', 'scale' => '20', 'coefficient' => '1'], ['status' => 'graded', 'value' => '20', 'scale' => '20', 'coefficient' => '1']]);
        $this->assertSame('10,00', GradeCalculator::display($zero['average']));
        $this->assertFalse(GradeCalculator::belowThreshold($zero['average'], '10'));
        $this->assertTrue(GradeCalculator::belowThreshold(BigRational::of('9.999'), '10'));
        $this->assertFalse(GradeCalculator::belowThreshold(null, '10'));
        $this->assertSame(['12.500', '0.000', '7.250', '100.000'], [GradeCalculator::decimal('12,5'), GradeCalculator::decimal('0'), GradeCalculator::decimal('07.25'), GradeCalculator::decimal('100')]);
    }

    public function test_r26_r27_personal_grades_show_subject_and_general_averages_with_their_coverage(): void
    {
        $maths = $this->personalSubject('Mathématiques fictives');
        $french = $this->personalSubject('Français fictif');
        $empty = $this->personalSubject('Histoire fictive');
        $this->grade($maths, '14', '20', '1');
        $this->grade($maths, '36', '60', '2');
        $this->grade($french, '15', '20');
        $this->as('independant')->patch('/notes/matieres/'.$maths->id.'/coefficient', ['expected_version' => 1, 'coefficient' => '3'])->assertSessionHasNoErrors();
        $this->as('independant')->patch('/notes/matieres/'.$french->id.'/coefficient', ['expected_version' => 1, 'coefficient' => '2'])->assertSessionHasNoErrors();
        $this->as('independant')->patch('/notes/matieres/'.$empty->id.'/coefficient', ['expected_version' => 1, 'coefficient' => '9'])->assertSessionHasNoErrors();

        $summary = $this->personal();
        $subjects = collect($summary['subjects'])->keyBy('name');
        $pick = fn (array $row, array $keys) => array_map(fn (string $key) => $row[$key], $keys);
        $this->assertSame(['12,67', 2, 2, '3', false], $pick($subjects['Mathématiques fictives'], ['average', 'retained', 'total', 'coefficient', 'watch']));
        $this->assertSame(['15,00', 1, 1], $pick($subjects['Français fictif'], ['average', 'retained', 'total']));
        // Matière sans note : pas de moyenne, et la moyenne générale n'est pas abaissée malgré son coefficient 9.
        $this->assertSame([null, 0, 0, false], $pick($subjects['Histoire fictive'], ['average', 'retained', 'total', 'watch']));
        $this->assertSame(['average' => '13,60', 'counted' => 2, 'total' => 3, 'missingCoefficients' => 0], $summary['general']);
        $this->assertSame(['personal', '10'], [$summary['provenance'], $summary['threshold']]);
        $second = collect($subjects['Mathématiques fictives']['grades'])->firstWhere('scale', '60');
        $this->assertSame(['36', '60', '2', '12,00', 'personal', true], [$second['value'], $second['scale'], $second['coefficient'], $second['normalized'], $second['provenance'], $second['retained']]);

        $this->as('independant')->get('/notes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Grades')->where('source', 'personnelle')->where('period', 'aucune')->has('sources', 1));
        $this->as('independant')->patch('/notes/matieres/'.$maths->id.'/coefficient', ['expected_version' => 2, 'coefficient' => '0'])->assertSessionHasErrors('coefficient');
        $this->as('independant')->patchJson('/notes/matieres/'.$maths->id.'/coefficient', ['expected_version' => 1, 'coefficient' => '4'])->assertStatus(409);
        $this->as('editeur')->patch('/notes/matieres/'.$maths->id.'/coefficient', ['expected_version' => 2, 'coefficient' => '4'])->assertNotFound();
        $this->assertSame('3.000', $maths->fresh()->grade_coefficient);
    }

    public function test_r28_missing_upcoming_absent_exempt_and_not_submitted_never_count_as_zero_and_a_zero_scale_is_refused(): void
    {
        $subject = $this->personalSubject('Mathématiques fictives');
        $this->grade($subject, '14', '20');
        foreach (['pending', 'absent', 'exempt', 'not_submitted'] as $status) {
            $this->grade($subject, '', '20', '4', ['status' => $status, 'value' => null]);
        }
        $row = collect($this->personal()['subjects'])->firstWhere('id', $subject->id);
        $this->assertSame(['14,00', 1, 5], [$row['average'], $row['retained'], $row['total']]);
        $this->assertEqualsCanonicalizing(['graded', 'pending', 'absent', 'exempt', 'not_submitted'], collect($row['grades'])->pluck('status')->all());
        $absent = collect($row['grades'])->firstWhere('status', 'absent');
        $this->assertSame([null, null, false, '4'], [$absent['value'], $absent['normalized'], $absent['retained'], $absent['coefficient']]);

        // Une vraie note zéro, elle, compte : (14 + 0) / 2 = 7, sous le seuil de 10.
        $zero = $this->grade($subject, '0', '20');
        $this->assertSame('0.000', $zero->value);
        $row = collect($this->personal()['subjects'])->firstWhere('id', $subject->id);
        $this->assertSame(['7,00', 2, 6, true], [$row['average'], $row['retained'], $row['total'], $row['watch']]);

        $base = ['client_key' => (string) Str::uuid(), 'title' => 'Refus fictif', 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '5', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'];
        $before = PersonalGrade::count();
        $this->as('independant')->post('/notes', [...$base, 'scale' => '0'])->assertSessionHasErrors('scale');
        $this->as('independant')->post('/notes', [...$base, 'scale' => '0,000'])->assertSessionHasErrors('scale');
        $this->as('independant')->post('/notes', [...$base, 'scale' => '-20'])->assertSessionHasErrors('scale');
        $this->as('independant')->post('/notes', [...$base, 'coefficient' => '0'])->assertSessionHasErrors('coefficient');
        $this->as('independant')->post('/notes', [...$base, 'value' => '20,5'])->assertSessionHasErrors('value');
        $this->as('independant')->post('/notes', [...$base, 'value' => '-1'])->assertSessionHasErrors('value');
        $this->as('independant')->post('/notes', [...$base, 'value' => null])->assertSessionHasErrors('value');
        $this->as('independant')->post('/notes', [...$base, 'status' => 'absent'])->assertSessionHasErrors('value');
        $this->as('independant')->post('/notes', [...$base, 'status' => 'zero'])->assertSessionHasErrors('status');
        $this->as('independant')->post('/notes', [...$base, 'value' => 'douze'])->assertSessionHasErrors('value');
        $this->assertSame($before, PersonalGrade::count());
        foreach ([['scale' => 0], ['coefficient' => 0], ['value' => 21], ['status' => 'absent'], ['status' => 'zero']] as $invalid) {
            try {
                DB::table('personal_grades')->where('id', $zero->id)->update($invalid);
                $this->fail('MySQL a accepté '.json_encode($invalid));
            } catch (QueryException $error) {
                $this->assertSame(3819, (int) $error->errorInfo[1]);
            }
        }
    }

    public function test_g12_01_personal_grades_check_subject_period_and_date_and_recalculate_after_correction_or_deletion(): void
    {
        $subject = $this->personalSubject('Mathématiques fictives');
        $period = $this->personalPeriod();
        $elsewhere = PersonalPeriod::factory()->create(['owner_id' => $this->user('independant')->id, 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $base = ['client_key' => (string) Str::uuid(), 'title' => 'Refus fictif', 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '5', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'];
        $this->as('independant')->post('/notes', [...$base, 'personal_subject_id' => $this->personalSubject('Matière fictive d’autrui', 'editeur')->id])->assertSessionHasErrors('personal_subject_id');
        $this->as('independant')->post('/notes', [...$base, 'personal_period_id' => $this->personalPeriod('editeur')->id])->assertSessionHasErrors('personal_period_id');
        $this->as('independant')->post('/notes', [...$base, 'personal_period_id' => $elsewhere->id])->assertSessionHasErrors('assessed_on');
        $this->as('independant')->post('/notes', [...$base, 'assessed_on' => '10/11/2026'])->assertSessionHasErrors('assessed_on');
        $this->assertSame(0, PersonalGrade::count());

        $first = $this->grade($subject, '14', '20', '1', ['personal_period_id' => $period->id]);
        $second = $this->grade($subject, '36', '60', '2', ['personal_period_id' => $period->id]);
        $average = fn () => collect($this->personal('independant', (string) $period->id)['subjects'])->firstWhere('id', $subject->id)['average'];
        $this->assertSame('12,67', $average());
        $this->assertNull(collect($this->personal()['subjects'])->firstWhere('id', $subject->id)['average']);
        $this->as('independant')->get('/notes')->assertInertia(fn (Assert $page) => $page->where('period', (string) $period->id));

        // Correction : 36/60 devient 48/60 → (14 + 16 × 2) / 3 = 15,33. Suppression : il reste 14.
        $change = ['expected_version' => 1, 'title' => $second->title, 'personal_subject_id' => $subject->id, 'personal_period_id' => $period->id, 'status' => 'graded', 'value' => '48', 'scale' => '60', 'coefficient' => '2', 'assessed_on' => '2026-11-10'];
        $this->as('independant')->patchJson('/notes/'.$second->id, $change)->assertOk()->assertJson(['version' => 2, 'replayed' => false]);
        $this->as('independant')->patchJson('/notes/'.$second->id, $change)->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->as('independant')->patchJson('/notes/'.$second->id, [...$change, 'value' => '1'])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->assertSame('15,33', $average());
        $this->as('independant')->deleteJson('/notes/'.$second->id, ['expected_version' => 1])->assertStatus(409);
        $this->as('independant')->deleteJson('/notes/'.$second->id, ['expected_version' => 2])->assertOk()->assertJson(['deleted' => true, 'replayed' => false]);
        $this->as('independant')->deleteJson('/notes/'.$second->id, ['expected_version' => 2])->assertOk()->assertJson(['deleted' => true, 'replayed' => true]);
        $this->assertSame(['14,00', 1], [$average(), PersonalGrade::count()]);

        // Double clic : une seule note ; même clé et autre contenu : conflit.
        $payload = [...$base, 'client_key' => (string) Str::uuid()];
        $this->as('independant')->postJson('/notes', $payload)->assertOk()->assertJson(['replayed' => false]);
        $this->as('independant')->postJson('/notes', $payload)->assertOk()->assertJson(['replayed' => true]);
        $this->as('independant')->postJson('/notes', [...$payload, 'value' => '6'])->assertStatus(409);
        $this->assertSame([2, 1], [PersonalGrade::count(), $first->fresh()->lock_version]);
    }

    public function test_r58_personal_grades_are_private_to_their_owner_and_leave_no_trace_in_logs(): void
    {
        $subject = $this->personalSubject('Matière confidentielle fictive', 'apprenant-double');
        $grade = $this->grade($subject, '7.25', '20', '1', ['title' => 'Note confidentielle fictive'], 'apprenant-double');
        $change = ['expected_version' => 1, 'title' => 'Écrasement fictif', 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '20', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'];

        foreach ([['enseignant-a', 'A'], ['admin-a', 'A'], ['admin-b', 'B'], ['plateforme', null], ['editeur', null], ['independant', null], ['polyvalent', 'A']] as [$name, $letter]) {
            $actor = fn () => in_array($name, ['plateforme', 'editeur'], true)
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            foreach (['/notes?source=personnelle', '/'] as $url) {
                $actor()->get($url)->assertOk()->assertDontSee('confidentielle fictive')->assertDontSee('7,25');
            }
            $actor()->patch('/notes/'.$grade->id, $change)->assertNotFound();
            // La suppression répond de la même façon qu'une note existe ou non chez autrui : elle ne révèle rien et ne supprime rien.
            $actor()->deleteJson('/notes/'.$grade->id, ['expected_version' => 1])->assertOk()->assertJson(['replayed' => true]);
            $actor()->patch('/notes/matieres/'.$subject->id.'/coefficient', ['expected_version' => 1, 'coefficient' => '5'])->assertNotFound();
            $this->assertTrue(Gate::forUser($this->user($name))->denies('view', $grade));
            $this->assertTrue(Gate::forUser($this->user($name))->denies('update', $grade));
        }
        foreach (['/notes/evaluations', '/taches/devoirs'] as $url) {
            $this->as('enseignant-a', 'A')->get($url)->assertOk()->assertDontSee('confidentielle fictive');
        }
        foreach (['/notes/coefficients', '/administration/etablissement', '/planning?source=draft'] as $url) {
            $this->as('admin-a', 'A')->get($url)->assertOk()->assertDontSee('confidentielle fictive');
        }
        // owner_id envoyé par le client : ignoré.
        $this->as('independant')->post('/notes', ['client_key' => (string) Str::uuid(), 'owner_id' => $this->user('apprenant-double')->id, 'title' => 'Intrusion fictive',
            'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '1', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasErrors('personal_subject_id');

        $this->assertTrue(Gate::forUser($this->user('apprenant-double'))->allows('view', $grade));
        $this->assertSame(['Note confidentielle fictive', '7.250', 1, 1], [$grade->fresh()->title, $grade->fresh()->value, $grade->fresh()->lock_version, PersonalGrade::count()]);
        // Aucune note privée, même sans sa valeur, n'est journalisée ni émise en événement.
        $this->assertSame([0, 0], [AuditLog::count(), DomainEvent::count()]);
        $this->freshDevice()->get('/notes')->assertRedirect('/connexion');
        $this->freshDevice()->post('/notes', $change)->assertRedirect('/connexion');
    }

    public function test_g12_02_published_results_reach_only_the_learners_concerned_with_institutional_averages(): void
    {
        $french = $this->french();
        $draft = $this->assessment();
        $this->enter($draft, ['apprenant-double' => ['graded', '14'], 'polyvalent' => ['graded', '3,5']])->assertSessionHasNoErrors();
        // Brouillon : rien n'est visible des apprenants.
        $this->assertNull($this->published('apprenant-double'));
        $this->as('apprenant-double', 'A')->get('/notes')->assertOk()->assertInertia(fn (Assert $page) => $page->where('source', 'etablissement')->where('summary', null)->has('sources', 3));
        $this->publish($draft);
        $this->graded(['apprenant-double' => ['graded', '36'], 'polyvalent' => ['absent']], ['title' => 'Devoir fictif sur 60', 'scale' => '60', 'coefficient' => '2']);
        $this->graded(['apprenant-double' => ['graded', '15']], ['title' => 'Dictée fictive', 'subject_id' => $french->id]);

        $mine = $this->published('apprenant-double');
        $subjects = collect($mine['subjects'])->keyBy('name');
        $this->assertSame(['institutional', '12,67', 2, 2, null], [$mine['provenance'], $subjects['Méthodologie fictive']['average'], $subjects['Méthodologie fictive']['retained'], $subjects['Méthodologie fictive']['total'], $subjects['Méthodologie fictive']['coefficient']]);
        $this->assertSame('institutional', $subjects['Méthodologie fictive']['grades'][0]['provenance']);
        // Coefficients de matière non définis par l'établissement : aucune moyenne générale n'est inventée.
        $this->assertSame(['average' => null, 'counted' => 0, 'total' => 2, 'missingCoefficients' => 2], $mine['general']);

        $this->as('admin-a', 'A')->put('/notes/coefficients/'.$this->methodology()->id, ['expected_version' => 0, 'coefficient' => '3'])->assertSessionHasNoErrors();
        $this->as('admin-a', 'A')->put('/notes/coefficients/'.$french->id, ['expected_version' => 0, 'coefficient' => '2'])->assertSessionHasNoErrors();
        $this->assertSame(['average' => '13,60', 'counted' => 2, 'total' => 2, 'missingCoefficients' => 0], $this->published('apprenant-double')['general']);

        // L'autre apprenant : sa propre note, son absence (distincte de zéro) et une note manquante ; jamais celles du premier.
        $other = collect($this->published('polyvalent')['subjects'])->keyBy('name');
        $this->assertSame(['3,50', 1, 2, true], [$other['Méthodologie fictive']['average'], $other['Méthodologie fictive']['retained'], $other['Méthodologie fictive']['total'], $other['Méthodologie fictive']['watch']]);
        $this->assertEqualsCanonicalizing(['graded', 'absent'], collect($other['Méthodologie fictive']['grades'])->pluck('status')->all());
        $this->assertSame([['missing', null, false]], collect($other['Français fictif']['grades'])->map(fn ($grade) => [$grade['status'], $grade['value'], $grade['retained']])->all());
        $this->assertSame([null, 0, 1, false], [$other['Français fictif']['average'], $other['Français fictif']['retained'], $other['Français fictif']['total'], $other['Français fictif']['watch']]);
        $page = $this->as('polyvalent')->get('/notes?source=etablissement&appartenance='.$this->member('polyvalent')->id.'&periode='.$this->period()->id)->getContent();
        foreach (['"14"', '"36"', '"15"', '12,67', '13,60'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $page);
        }

        // Ni un tiers, ni l'appartenance d'autrui, ni un autre établissement n'ouvrent ces résultats.
        $this->as('independant')->get('/notes?source=etablissement')->assertNotFound();
        $this->as('polyvalent')->get('/notes?source=etablissement&appartenance='.$this->member('apprenant-double')->id)->assertNotFound();
        $this->as('enseignant-a', 'A')->get('/notes?source=etablissement&appartenance='.$this->member('apprenant-double')->id)->assertNotFound();
        $this->as('apprenant-double')->get('/notes?source=etablissement&appartenance='.$this->member('apprenant-double', 'B')->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('summary', null)->where('periods', []));
        // Appartenance retirée : les résultats de l'établissement ne sont plus servis.
        $this->member('polyvalent')->revoke();
        $this->as('polyvalent')->get('/notes?source=etablissement&appartenance='.$this->member('polyvalent')->id)->assertNotFound();
    }

    public function test_r29_a_learner_cannot_replace_a_published_result_and_sources_are_never_merged(): void
    {
        $assessment = $this->graded(['apprenant-double' => ['graded', '8']]);
        $result = AssessmentResult::sole();
        $payload = ['expected_version' => $assessment->lock_version, 'reason' => 'Tentative fictive', 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '20']]];

        // Aucune route d'écriture de l'enseignant n'est ouverte à l'apprenant, quel que soit son contexte.
        foreach ([$this->as('apprenant-double', 'A'), $this->as('apprenant-double')] as $actor) {
            $actor->put('/notes/evaluations/'.$assessment->id.'/resultats', $payload)->assertForbidden();
        }
        $this->as('apprenant-double', 'A')->patch('/notes/evaluations/'.$assessment->id, ['expected_version' => $assessment->lock_version, ...$this->assessmentPayload(['scale' => '8']), 'reason' => 'x'])->assertForbidden();
        $this->as('apprenant-double', 'A')->delete('/notes/evaluations/'.$assessment->id, ['expected_version' => $assessment->lock_version, 'reason' => 'x'])->assertForbidden();
        $this->as('apprenant-double', 'A')->post('/notes/evaluations', $this->assessmentPayload())->assertForbidden();
        $this->as('apprenant-double', 'A')->get('/notes/evaluations')->assertForbidden();
        // Les routes des notes personnelles ne touchent jamais un résultat publié.
        $this->as('apprenant-double')->patch('/notes/'.$assessment->id, ['expected_version' => 1, 'title' => 'x', 'personal_subject_id' => 1, 'status' => 'graded', 'value' => '20', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertNotFound();
        $this->as('apprenant-double')->deleteJson('/notes/'.$assessment->id, ['expected_version' => $assessment->lock_version])->assertOk()->assertJson(['replayed' => true]);
        $this->assertSame(['8.000', 1, 'published'], [$result->fresh()->value, $result->fresh()->version, $assessment->fresh()->status]);

        // Une note personnelle portant le même intitulé reste dans sa propre source : aucune fusion, aucun doublon implicite.
        $subject = $this->personalSubject('Méthodologie fictive', 'apprenant-double');
        $this->grade($subject, '20', '20', '1', ['title' => 'Contrôle fictif'], 'apprenant-double');
        $published = $this->published('apprenant-double');
        $this->assertSame(['institutional', '8,00', 1, 1, true], [$published['provenance'], $published['subjects'][0]['average'], $published['subjects'][0]['total'], count($published['subjects']), $published['subjects'][0]['watch']]);
        $this->assertSame(['institutional'], collect($published['subjects'][0]['grades'])->pluck('provenance')->unique()->all());
        $personal = $this->personal('apprenant-double');
        $this->assertSame(['personal', '20,00', 1], [$personal['provenance'], $personal['subjects'][0]['average'], $personal['subjects'][0]['total']]);
        $this->assertSame(['personal'], collect($personal['subjects'][0]['grades'])->pluck('provenance')->unique()->all());
        $this->as('apprenant-double')->get('/notes?source=tout')->assertSessionHasErrors('source');
    }

    public function test_r30_an_assessment_must_match_the_assignment_the_year_the_period_and_its_dates(): void
    {
        $teacher = $this->member('enseignant-a');
        $nextYear = AcademicYear::factory()->create(['institution_id' => $teacher->institution_id, 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31']);
        $nextPeriod = Period::factory()->create(['academic_year_id' => $nextYear->id]);
        $post = fn (array $data) => $this->as('enseignant-a', 'A')->post('/notes/evaluations', $this->assessmentPayload($data));

        // Période d'une autre année du même établissement, ou d'un autre établissement : rattachement refusé.
        $post(['period_id' => $nextPeriod->id])->assertSessionHasErrors('period_id');
        $post(['period_id' => $nextPeriod->id, 'assessed_on' => '2027-10-01'])->assertSessionHasErrors('period_id');
        $post(['period_id' => $this->period('B')->id])->assertSessionHasErrors('period_id');
        $post(['assessed_on' => '2027-03-01'])->assertSessionHasErrors('assessed_on');
        $post(['assessed_on' => '2026-08-31'])->assertSessionHasErrors('assessed_on');
        $post(['scale' => '0'])->assertSessionHasErrors('scale');
        $post(['coefficient' => '0'])->assertSessionHasErrors('coefficient');
        $post(['scale' => '-20'])->assertSessionHasErrors('scale');
        // Groupe ou matière hors affectation, institution_id forcé : introuvable.
        $post(['group_id' => Group::where('code', 'A-G2')->firstOrFail()->id])->assertNotFound();
        $post(['group_id' => Group::where('code', 'B-G1')->firstOrFail()->id])->assertNotFound();
        $post(['subject_id' => Subject::factory()->create(['institution_id' => $teacher->institution_id])->id])->assertNotFound();
        $post(['institution_id' => $this->member('admin-b', 'B')->institution_id, 'academic_year_id' => $nextYear->id, 'period_id' => $nextPeriod->id])->assertSessionHasErrors('period_id');
        $this->assertSame(0, Assessment::count());
        try {
            DB::table('assessments')->insert(['id' => (string) Str::uuid(), 'institution_id' => $teacher->institution_id, 'academic_year_id' => $teacher->academic_year_id, 'group_id' => Group::where('code', 'A-G1')->firstOrFail()->id,
                'subject_id' => $this->methodology()->id, 'period_id' => $nextPeriod->id, 'author_membership_id' => $teacher->id, 'title' => 'x', 'scale' => 20, 'assessed_on' => '2026-11-10',
                'client_key' => (string) Str::uuid(), 'content_hash' => str_repeat('a', 64)]);
            $this->fail('MySQL a accepté une période d’une autre année.');
        } catch (QueryException $error) {
            $this->assertSame(1452, (int) $error->errorInfo[1]);
        }

        $assessment = $this->assessment();
        $update = fn (array $data) => $this->as('enseignant-a', 'A')->patch('/notes/evaluations/'.$assessment->id, ['expected_version' => 1, ...$this->assessmentPayload($data)]);
        $update(['period_id' => $nextPeriod->id])->assertSessionHasErrors('period_id');
        $update(['scale' => '0'])->assertSessionHasErrors('scale');
        // Résultat : valeur dans le barème, apprenant du groupe seulement, états sans valeur.
        $this->enter($assessment, ['apprenant-double' => ['graded', '20,5']])->assertSessionHasErrors('results.0.value');
        $this->enter($assessment, ['apprenant-double' => ['graded', null]])->assertSessionHasErrors('results.0.value');
        $this->enter($assessment, ['apprenant-double' => ['absent', '0']])->assertSessionHasErrors('results.0.value');
        $this->enter($assessment, ['apprenant-double' => ['graded', '-1']])->assertSessionHasErrors('results.0.value');
        $this->enter($assessment, ['independant' => ['graded', '10']])->assertSessionHasErrors('results.0.user_id');
        $this->enter($assessment, ['enseignant-a' => ['graded', '10']])->assertSessionHasErrors('results.0.user_id');
        $this->assertSame([0, 1], [AssessmentResult::count(), $assessment->fresh()->lock_version]);
        // Une vraie note zéro est acceptée et retenue.
        $this->enter($assessment, ['apprenant-double' => ['graded', '0']])->assertSessionHasNoErrors();
        $this->publish($assessment);
        $row = $this->published('apprenant-double')['subjects'][0];
        $this->assertSame(['0,00', 1, '0', true], [$row['average'], $row['retained'], $row['grades'][0]['value'], $row['watch']]);
    }

    public function test_g12_03_only_an_assigned_teacher_with_reinforced_access_manages_assessments(): void
    {
        $assessment = $this->assessment();
        $this->enter($assessment, ['apprenant-double' => ['graded', '12']])->assertSessionHasNoErrors();
        $url = '/notes/evaluations/'.$assessment->id;
        $results = ['expected_version' => 2, 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '1']]];

        foreach ([['admin-a', 'A', 403], ['enseignant-a', null, 403], ['polyvalent', 'B', 404], ['admin-b', 'B', 403], ['plateforme', null, 403]] as [$name, $letter, $status]) {
            $actor = fn () => $name === 'plateforme'
                ? $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $this->user($name)->memberships()->firstOrFail()->id])
                : $this->as($name, $letter);
            $actor()->put($url.'/resultats', $results)->assertStatus($status);
            $actor()->patch($url, ['expected_version' => 2, ...$this->assessmentPayload(['title' => 'Intrusion fictive'])])->assertStatus($status);
            $actor()->post($url.'/publication', ['expected_version' => 2])->assertStatus($status);
            $actor()->delete($url, ['expected_version' => 2])->assertStatus($status);
            $actor()->get('/notes/evaluations?evaluation='.$assessment->id)->assertStatus($status);
        }
        $this->as('polyvalent', 'B')->get('/notes/evaluations')->assertOk()->assertInertia(fn (Assert $page) => $page->has('assessments', 0));
        $this->as('enseignant-a', 'A')->get('/notes/evaluations?evaluation='.$assessment->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Gradebook')
            ->has('assessments', 1)->where('selected.status', 'draft')->has('selected.roster', 2)->where('canWrite', true)
            ->where('selected.roster', fn ($roster) => collect($roster)->pluck('status', 'name')->all() === ['Apprenant fictif A et B' => 'graded', 'Apprenant fictif A et enseignant B' => 'missing']));

        // Coefficients de matière : administration de l'établissement seulement.
        $coefficient = '/notes/coefficients/'.$this->methodology()->id;
        $this->as('enseignant-a', 'A')->put($coefficient, ['expected_version' => 0, 'coefficient' => '2'])->assertForbidden();
        $this->as('apprenant-double', 'A')->put($coefficient, ['expected_version' => 0, 'coefficient' => '2'])->assertForbidden();
        $this->as('admin-b', 'B')->put($coefficient, ['expected_version' => 0, 'coefficient' => '2'])->assertNotFound();
        $this->as('admin-a', 'A')->put($coefficient, ['expected_version' => 0, 'coefficient' => '0'])->assertSessionHasErrors('coefficient');
        $this->as('admin-a', 'A')->putJson($coefficient, ['expected_version' => 0, 'coefficient' => '2,5'])->assertOk()->assertJson(['version' => 1]);
        $this->as('admin-a', 'A')->putJson($coefficient, ['expected_version' => 0, 'coefficient' => '2,5'])->assertOk()->assertJson(['version' => 1]);
        $this->as('admin-a', 'A')->putJson($coefficient, ['expected_version' => 0, 'coefficient' => '4'])->assertStatus(409);
        $this->assertSame(['2.500', 1], [SubjectCoefficient::sole()->coefficient, AuditLog::where('action', 'subject_coefficient.set')->count()]);
        $this->as('admin-a', 'A')->get('/notes/coefficients')->assertOk()->assertInertia(fn (Assert $page) => $page->component('GradeCoefficients')->where('subjects.0.coefficient', '2,5'));
        $this->as('enseignant-a', 'A')->get('/notes/coefficients')->assertForbidden();

        // Sans vérification renforcée : lecture seule pour l'enseignant, rien pour l'administration.
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->as('enseignant-a', 'A')->put($url.'/resultats', $results)->assertForbidden();
        $this->as('enseignant-a', 'A')->post('/notes/evaluations', $this->assessmentPayload())->assertForbidden();
        $this->as('enseignant-a', 'A')->get('/notes/evaluations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWrite', false));
        $this->as('admin-a', 'A')->put($coefficient, ['expected_version' => 1, 'coefficient' => '9'])->assertForbidden();
        config(['campus.identity.privileged_without_mfa' => true]);

        // Affectation clôturée : plus aucune gestion ; inscription clôturée : plus de saisie pour cet apprenant.
        Enrollment::where('membership_id', $this->member('polyvalent')->id)->firstOrFail()->close();
        $this->as('enseignant-a', 'A')->put($url.'/resultats', ['expected_version' => 2, 'results' => [['user_id' => $this->user('polyvalent')->id, 'status' => 'graded', 'value' => '9']]])->assertSessionHasErrors('results.0.user_id');
        TeachingAssignment::where('membership_id', $this->member('enseignant-a')->id)->firstOrFail()->close();
        $this->as('enseignant-a', 'A')->put($url.'/resultats', $results)->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/notes/evaluations', $this->assessmentPayload())->assertNotFound();
        $this->assertSame(['12.000', 1, 'draft'], [AssessmentResult::sole()->value, Assessment::count(), $assessment->fresh()->status]);
    }

    public function test_g12_04_a_correction_or_withdrawal_is_audited_without_values_and_averages_are_recalculated(): void
    {
        $first = $this->graded(['apprenant-double' => ['graded', '14'], 'polyvalent' => ['graded', '10']]);
        $second = $this->graded(['apprenant-double' => ['graded', '36']], ['title' => 'Devoir fictif sur 60', 'scale' => '60', 'coefficient' => '2']);
        $average = fn (string $name = 'apprenant-double') => $this->published($name)['subjects'][0]['average'];
        $this->assertSame('12,67', $average());
        $this->assertSame(1, DomainEvent::where('type', 'grade.published')->where('subject_uuid', $first->id)->count());

        // Correction d'un résultat publié : motif obligatoire, une révision, un événement, moyenne recalculée (14 + 16 × 2) / 3.
        $this->enter($second, ['apprenant-double' => ['graded', '48']])->assertSessionHasErrors('reason');
        $this->assertSame('12,67', $average());
        $this->enter($second, ['apprenant-double' => ['graded', '48']], ['reason' => 'Erreur de report fictive'], json: true)->assertOk()->assertJson(['version' => 4, 'replayed' => false]);
        $this->assertSame('15,33', $average());
        // Rejeu, puis même contenu avec une version dépassée : rien de plus ; autre contenu sur version dépassée : conflit.
        $replay = ['reason' => 'Erreur de report fictive', 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '48']]];
        $this->as('enseignant-a', 'A')->putJson('/notes/evaluations/'.$second->id.'/resultats', ['expected_version' => 3, ...$replay])->assertOk()->assertJson(['version' => 4, 'replayed' => true]);
        $this->as('enseignant-a', 'A')->putJson('/notes/evaluations/'.$second->id.'/resultats', ['expected_version' => 3, 'reason' => 'x', 'results' => [['user_id' => $this->user('apprenant-double')->id, 'status' => 'graded', 'value' => '1']]])
            ->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);

        $result = AssessmentResult::where('assessment_id', $second->id)->sole();
        $revisions = AssessmentResultRevision::where('assessment_result_id', $result->id)->orderBy('version')->get();
        $this->assertSame([[1, '36.000', '60.000', null], [2, '48.000', '60.000', 'Erreur de report fictive']], $revisions->map(fn ($revision) => [$revision->version, $revision->value, $revision->scale, $revision->reason])->all());
        $this->assertSame($this->member('enseignant-a')->id, $revisions[1]->actor_membership_id);
        try {
            $revisions[0]->update(['value' => 60]);
            $this->fail('Une révision a été modifiée.');
        } catch (LogicException) {
            $this->assertSame('36.000', $revisions[0]->fresh()->value);
        }
        $event = DomainEvent::where('type', 'grade.corrected')->sole();
        $this->assertSame('grade-corrected:'.$second->id.':v4', $event->dedup_key);
        $this->assertEquals(['assessment_id' => $second->id, 'institution_id' => $second->institution_id, 'academic_year_id' => $second->academic_year_id, 'group_id' => $second->group_id,
            'subject_id' => $second->subject_id, 'version' => 4, 'learner_ids' => [$this->user('apprenant-double')->id]], $event->payload);
        $audit = AuditLog::where('action', 'assessment.results_corrected')->sole();
        $this->assertSame(['Erreur de report fictive', $second->id, $this->user('enseignant-a')->id], [$audit->reason, $audit->subject_uuid, $audit->actor_id]);
        $this->assertEquals(['group_id' => $second->group_id, 'subject_id' => $second->subject_id, 'period_id' => $second->period_id, 'version' => 4, 'learner_ids' => [$this->user('apprenant-double')->id]], $audit->metadata);
        // Ni le journal ni les événements ne portent de note, de barème ou d'intitulé.
        foreach ([AuditLog::where('action', 'like', 'assessment.%')->get(), DomainEvent::query()->get()] as $records) {
            // Les identifiants aléatoires sont retirés avant la recherche : seuls les contenus comptent.
            $text = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'id', json_encode($records->map(fn ($record) => [$record->metadata ?? $record->payload])->all(), JSON_UNESCAPED_UNICODE));
            foreach (['value', 'scale', '48', '36', 'fictif'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text);
            }
        }
        $this->assertEqualsCanonicalizing(['assessment.created', 'assessment.results_saved', 'assessment.published', 'assessment.results_corrected'], AuditLog::where('subject_uuid', $second->id)->pluck('action')->all());

        // Correction du barème publié : refusée si un résultat le dépasse, sinon motivée ; 48/80 = 12 → (14 + 12 × 2) / 3.
        $meta = fn (array $data) => ['expected_version' => $second->fresh()->lock_version, ...$this->assessmentPayload(['title' => 'Devoir fictif sur 60', 'scale' => '60', 'coefficient' => '2', ...$data])];
        $this->as('enseignant-a', 'A')->patch('/notes/evaluations/'.$second->id, $meta(['scale' => '40', 'reason' => 'Barème fictif']))->assertSessionHasErrors('scale');
        $this->as('enseignant-a', 'A')->patch('/notes/evaluations/'.$second->id, $meta(['scale' => '80']))->assertSessionHasErrors('reason');
        $this->as('enseignant-a', 'A')->patchJson('/notes/evaluations/'.$second->id, $meta(['scale' => '80', 'reason' => 'Barème fictif corrigé']))->assertOk()->assertJson(['version' => 5, 'replayed' => false]);
        $this->assertSame(['12,67', 2], [$average(), DomainEvent::where('type', 'grade.corrected')->count()]);
        $this->assertEquals(['scale'], AuditLog::where('action', 'assessment.corrected')->sole()->metadata['changed']);

        // Retrait motivé : l'évaluation disparaît et la moyenne est recalculée ; l'autre apprenant n'a jamais été touché.
        $this->as('enseignant-a', 'A')->delete('/notes/evaluations/'.$second->id, ['expected_version' => 5])->assertSessionHasErrors('reason');
        $this->as('enseignant-a', 'A')->deleteJson('/notes/evaluations/'.$second->id, ['expected_version' => 5, 'reason' => 'Évaluation fictive annulée'])->assertOk()->assertJson(['status' => 'withdrawn', 'replayed' => false]);
        $this->as('enseignant-a', 'A')->deleteJson('/notes/evaluations/'.$second->id, ['expected_version' => 5, 'reason' => 'Évaluation fictive annulée'])->assertOk()->assertJson(['status' => 'withdrawn', 'replayed' => true]);
        $this->assertSame(['14,00', 1, '10,00'], [$average(), $this->published('apprenant-double')['subjects'][0]['total'], $average('polyvalent')]);
        $this->enter($second, ['apprenant-double' => ['graded', '1']], ['reason' => 'x'])->assertNotFound();
        $this->as('enseignant-a', 'A')->post('/notes/evaluations/'.$second->id.'/publication', ['expected_version' => 6])->assertSessionHasErrors('reference');
        $this->assertSame([1, 1, 2], [DomainEvent::where('type', 'grade.withdrawn')->count(), AuditLog::where('action', 'assessment.withdrawn')->count(), AssessmentResultRevision::where('assessment_result_id', $result->id)->count()]);

        // Double clic à la création et à la publication : une évaluation, un événement.
        $payload = $this->assessmentPayload(['title' => 'Double clic fictif']);
        $created = $this->as('enseignant-a', 'A')->postJson('/notes/evaluations', $payload)->assertOk()->assertJson(['replayed' => false]);
        $this->as('enseignant-a', 'A')->postJson('/notes/evaluations', $payload)->assertOk()->assertJson(['id' => $created->json('id'), 'replayed' => true]);
        $this->as('enseignant-a', 'A')->postJson('/notes/evaluations', [...$payload, 'scale' => '10'])->assertStatus(409);
        $this->as('enseignant-a', 'A')->postJson('/notes/evaluations/'.$created->json('id').'/publication', ['expected_version' => 1])->assertOk()->assertJson(['version' => 2, 'replayed' => false]);
        $this->as('enseignant-a', 'A')->postJson('/notes/evaluations/'.$created->json('id').'/publication', ['expected_version' => 1])->assertOk()->assertJson(['version' => 2, 'replayed' => true]);
        $this->assertSame([3, 1], [Assessment::count(), DomainEvent::where('type', 'grade.published')->where('subject_uuid', $created->json('id'))->count()]);
    }
}
