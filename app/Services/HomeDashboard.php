<?php

namespace App\Services;

use App\Models\CourseOccurrence;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityFavorite;
use App\Models\OpportunityProfile;
use App\Models\PersonalGrade;
use App\Models\PersonalSubject;
use App\Models\PublishedOccurrence;
use App\Models\SchedulePublication;
use App\Models\Subject;
use App\Models\Task;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Support\OpportunityMatcher;

/**
 * Accueil de prochaine action. Chaque bloc relit les services des écrans correspondants : mêmes droits, mêmes règles.
 * Rien n'est inventé : un bloc sans donnée est vide, une étape n'est « faite » que si l'objet réel existe.
 */
class HomeDashboard
{
    public function __construct(
        private OfflineSnapshot $agenda, private CourseCheckIns $checkIns, private Tasks $tasks, private Grades $grades,
        private Opportunities $opportunities, private OpportunitySearch $search,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?Membership $active): array
    {
        $timezone = $user->personalSpace?->timezone ?? config('app.display_timezone');
        $start = now()->toImmutable()->setTimezone($timezone)->startOfDay()->utc();

        return [
            'timezone' => $timezone,
            // Cours du jour, toutes sources visibles par le compte : chaque ligne porte sa provenance.
            'today' => $this->agenda->courses($user, $start, $start->addDay()),
            'pendingCheckIns' => $this->checkIns->pending($user, 5)->all(),
            'upcomingTasks' => $this->tasks->upcoming($user, 5),
            'grades' => $this->gradeViews($user),
            'opportunities' => $this->relevantOpportunities($user),
            'individualSteps' => $this->individualSteps($user),
            'institutionSteps' => $active !== null && $active->scope === 'institution' && $active->role === 'institution_admin' ? $this->institutionSteps($active) : null,
        ];
    }

    /**
     * Moyennes indicatives de la période en cours, avec leur couverture : nombre de matières réellement comptées.
     *
     * @return list<array<string, mixed>>
     */
    private function gradeViews(User $user): array
    {
        $pick = fn (array $periods): ?array => collect($periods)->firstWhere('current', true) ?? ($periods[0] ?? null);
        $view = fn (string $label, string $provenance, array $period, array $summary): array => ['label' => $label, 'provenance' => $provenance, 'period' => $period['label'],
            'average' => $summary['general']['average'], 'counted' => $summary['general']['counted'], 'total' => $summary['general']['total'], 'missingCoefficients' => $summary['general']['missingCoefficients']];
        $views = [];
        $period = $pick($this->grades->personalPeriods($user));
        if ($period !== null) {
            $views[] = $view('Mes notes personnelles', 'personal', $period, $this->grades->personalSummary($user, $period['value'] === 'aucune' ? null : (int) $period['value']));
        }
        $this->grades->learnerMemberships($user)->each(function (Membership $membership) use ($user, $pick, $view, &$views): void {
            $period = $pick($this->grades->publishedPeriods($user, $membership));
            if ($period !== null) {
                $views[] = $view('Résultats publiés — '.$membership->institution->name, 'institutional', $period, $this->grades->publishedSummary($user, $membership, (int) $period['value']));
            }
        });

        // Une vue sans aucune matière notée n'est pas affichée : pas de « 0 » qui ressemblerait à un résultat.
        return array_values(array_filter($views, fn (array $row) => $row['total'] > 0));
    }

    /**
     * Opportunités ouvertes compatibles avec les critères que la personne a déclarés. Sans critères déclarés, rien n'est proposé :
     * l'accueil ne prétend pas deviner ce qui lui convient.
     *
     * @return array{hasProfile: bool, items: list<array<string, mixed>>, disclaimer: string}
     */
    private function relevantOpportunities(User $user): array
    {
        $profile = OpportunityProfile::query()->where('user_id', $user->id)->first();
        $declared = $profile !== null && ($profile->level !== null || ($profile->fields ?? []) !== [] || ($profile->languages ?? []) !== [] || $profile->residence_country !== null || $profile->nationality !== null);
        $items = [];
        if ($declared) {
            $items = $this->search->query(['cloture' => 'ouvertes'])->limit(40)->get()
                ->map(function (Opportunity $opportunity) use ($profile): array {
                    $match = OpportunityMatcher::compare($opportunity, $profile);

                    return ['opportunity' => $opportunity, 'overall' => $match['overall'], 'label' => $match['overallLabel'], 'matched' => count(array_filter($match['criteria'], fn (array $criterion) => $criterion['result'] === 'match'))];
                })
                // Retenues : aucune contradiction avec un critère déclaré, et au moins un critère déclaré qui correspond. Le reste peut rester à vérifier.
                ->filter(fn (array $row) => $row['overall'] !== 'mismatch' && $row['matched'] > 0)
                ->sortByDesc('matched')->take(3)
                ->map(fn (array $row): array => [...collect($this->opportunities->present($row['opportunity']))->only(['id', 'title', 'organizer', 'categoryLabel', 'deadline'])->all(),
                    'matchLabel' => $row['overall'] === 'match' ? $row['label'] : $row['matched'].' critère(s) déclaré(s) correspondent ; les autres sont à vérifier sur la fiche'])
                ->values()->all();
        }

        return ['hasProfile' => $declared, 'items' => $items, 'disclaimer' => OpportunityMatcher::DISCLAIMER];
    }

    /**
     * Démarrage en espace individuel : compte, fuseau, matière, cours, tâche, note, opportunité. Une étape est faite quand l'objet existe.
     *
     * @return list<array{key: string, label: string, done: bool, href: string, action: string}>
     */
    private function individualSteps(User $user): array
    {
        $step = fn (string $key, string $label, bool $done, string $href, string $action): array => compact('key', 'label', 'done', 'href', 'action');

        return [
            $step('account', 'Compte créé et vérifié', true, '/profil', 'Voir mon profil'),
            $step('timezone', 'Fuseau horaire confirmé', isset(($user->personalSpace?->preferences ?? [])['timezone_confirmed_at']), '/profil#fuseau', 'Confirmer mon fuseau'),
            $step('subject', 'Première matière', PersonalSubject::query()->where('owner_id', $user->id)->exists(), '/etudes/referentiels', 'Ajouter une matière'),
            $step('course', 'Premier cours au planning', CourseOccurrence::query()->where('scope', 'personal')->where('owner_id', $user->id)->exists(), '/planning', 'Ajouter un cours'),
            $step('task', 'Première tâche', Task::query()->where('scope', 'personal')->where('owner_id', $user->id)->exists(), '/taches', 'Ajouter une tâche'),
            $step('grade', 'Première note', PersonalGrade::query()->where('owner_id', $user->id)->exists(), '/notes', 'Ajouter une note'),
            $step('opportunity', 'Première opportunité suivie ou critères déclarés', OpportunityFavorite::query()->where('user_id', $user->id)->exists() || OpportunityProfile::query()->where('user_id', $user->id)->exists(), '/opportunites', 'Découvrir les opportunités'),
        ];
    }

    /**
     * Démarrage d'un établissement, pour son administration : référentiels, import, publication, invitation acceptée, planning,
     * signalement d'un enseignant, décision. Uniquement des existences dans le périmètre de l'appartenance active ; aucun nom, aucun contenu.
     *
     * @return list<array{key: string, label: string, done: bool, href: string, action: string}>
     */
    private function institutionSteps(Membership $admin): array
    {
        $step = fn (string $key, string $label, bool $done, string $href, string $action): array => compact('key', 'label', 'done', 'href', 'action');
        $scope = ['institution_id' => $admin->institution_id, 'academic_year_id' => $admin->academic_year_id];
        $reports = TeacherUnavailability::query()->where($scope);

        return [
            $step('references', 'Référentiels : au moins un groupe et une matière', Group::query()->where($scope)->exists() && Subject::query()->where('institution_id', $admin->institution_id)->exists(), '/etudes/referentiels', 'Ouvrir les référentiels'),
            $step('import', 'Import de planning analysé (simulation avant application)', ImportBatch::query()->where($scope)->whereNotIn('status', ['uploaded', 'analyzing'])->exists(), '/planning/import', 'Importer un planning'),
            $step('publication', 'Planning publié', SchedulePublication::query()->where($scope)->exists(), '/planning/publication', 'Préparer la publication'),
            $step('invitation', 'Invitation acceptée par un compte', Invitation::query()->where($scope)->where('status', 'accepted')->exists(), '/administration/etablissement', 'Inviter des membres'),
            $step('planning', 'Cours publiés visibles au planning', PublishedOccurrence::query()->where($scope)->where('status', 'active')->exists(), '/planning?source=published', 'Voir le planning publié'),
            $step('report', 'Signalement d’indisponibilité reçu d’un enseignant', (clone $reports)->exists(), '/planning?source=published', 'Voir les signalements'),
            $step('decision', 'Signalement traité par l’administration', (clone $reports)->where('status', '!=', 'pending')->exists(), '/planning?source=published', 'Traiter les signalements'),
        ];
    }
}
