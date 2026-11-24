<?php

namespace Database\Seeders;

use App\Models\Opportunity;
use App\Models\OpportunityProfile;
use App\Models\OpportunityRevision;
use App\Models\PersonalPeriod;
use App\Models\PersonalSubject;
use App\Models\User;
use App\Services\Grades;
use App\Services\ScheduleManager;
use App\Services\Tasks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Jeu de démonstration pour essayer l'application à la main : une scolarité fictive (matières, période, emploi du temps, tâches, notes)
 * pour deux comptes fictifs, et un catalogue d'opportunités varié. Tout est inventé : fiches préfixées « [FICTIF] », organisateurs
 * imaginaires, adresses en example.test. Rejouable : relancer le jeu ne crée aucun doublon.
 * Cours, tâches et notes passent par les services des écrans, avec leurs contrôles habituels.
 */
class CampusShowcaseSeeder extends Seeder
{
    private const ACCOUNTS = ['independant', 'apprenant-double'];

    /** Matière => [code, coefficient]. */
    private const SUBJECTS = [
        'Mathématiques' => ['MATH', '3'], 'Français' => ['FRAN', '3'], 'Histoire-géographie' => ['HIS-GEO', '2'],
        'Anglais' => ['ANGL', '2'], 'Physique-chimie' => ['PHY-CHI', '2'],
    ];

    /** Cours hebdomadaires : matière, jours (1 = lundi), début, fin, salle. */
    private const TIMETABLE = [
        ['Mathématiques', [1], '08:00', '09:30', 'Salle fictive 12'], ['Physique-chimie', [1], '10:00', '11:30', 'Laboratoire fictif'],
        ['Français', [2], '08:00', '09:30', 'Salle fictive 8'], ['Anglais', [2], '14:00', '15:00', 'Salle fictive 3'],
        ['Histoire-géographie', [2], '15:30', '17:00', 'Salle fictive 5'], ['Histoire-géographie', [3], '08:00', '09:30', 'Salle fictive 5'],
        ['Mathématiques', [4], '10:00', '11:30', 'Salle fictive 12'], ['Anglais', [4], '14:00', '15:00', 'Salle fictive 3'],
        ['Français', [5], '10:00', '11:30', 'Salle fictive 8'], ['Physique-chimie', [5], '14:00', '15:30', 'Laboratoire fictif'],
    ];

    /** Tâches : titre, matière, échéance en jours depuis aujourd'hui, heure, priorité, terminée. */
    private const TASKS = [
        ['Exercices 12 à 18, chapitre 3', 'Mathématiques', 0, '18:00', 'high', false],
        ['Fiche de lecture : premier chapitre', 'Français', 1, '08:00', 'normal', false],
        ['Apprendre la carte des fleuves', 'Histoire-géographie', -1, '17:00', 'normal', false],
        ['Liste de vocabulaire, unité 4', 'Anglais', 3, '14:00', 'low', false],
        ['Compte rendu du TP sur les solutions', 'Physique-chimie', 5, '12:00', 'high', false],
        ['Réviser les identités remarquables', 'Mathématiques', 7, '19:00', 'normal', false],
        ['Rédiger l’introduction du commentaire', 'Français', -3, '18:00', 'normal', true],
        ['Ranger et relire mes cours de la semaine', null, null, null, 'low', false],
    ];

    /** Notes : matière, intitulé, note, barème, coefficient, jours avant aujourd'hui. */
    private const GRADES = [
        ['Mathématiques', 'Contrôle sur les fonctions', '14', '20', '2', 21], ['Mathématiques', 'Interrogation de calcul', '7,5', '10', '1', 9],
        ['Français', 'Dictée', '16', '20', '1', 24], ['Français', 'Commentaire de texte', '12,5', '20', '2', 10],
        ['Histoire-géographie', 'Exposé en groupe', '15', '20', '1', 17], ['Anglais', 'Compréhension orale', '36', '60', '1', 12],
        ['Anglais', 'Expression écrite', '13', '20', '1', 5], ['Physique-chimie', 'TP noté', '11', '20', '1', 14],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])
            || config('database.default') !== 'mysql'
            || ! in_array(config('database.connections.mysql.database'), ['hako_ra_campus_dev', 'hako_ra_campus_test'], true)) {
            throw new RuntimeException('Fixtures réservées aux bases locales Campus dédiées.');
        }
        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->where('email', $account.'@example.test')->firstOrFail();
            $subjects = $this->subjects($user);
            $period = $this->period($user);
            $this->timetable($user, $subjects, $account);
            $this->tasks($user, $subjects, $account);
            $this->grades($user, $subjects, $period, $account);
        }
        // Critères déclarés pour un des deux comptes : son accueil propose des opportunités, l'autre montre l'invitation à les déclarer.
        $declared = User::query()->where('email', 'apprenant-double@example.test')->firstOrFail();
        OpportunityProfile::query()->firstOrCreate(['user_id' => $declared->id], ['level' => 'bachelor', 'fields' => ['Informatique', 'Mathématiques'], 'languages' => ['fr', 'en'], 'residence_country' => 'CI', 'lock_version' => 1]);
        $this->opportunities();
    }

    /**
     * @return array<string, PersonalSubject>
     */
    private function subjects(User $user): array
    {
        $subjects = [];
        foreach (self::SUBJECTS as $name => [$code, $coefficient]) {
            $subjects[$name] = PersonalSubject::query()->where('owner_id', $user->id)->where('name', $name)->first()
                ?? PersonalSubject::create(['owner_id' => $user->id, 'name' => $name, 'grade_coefficient' => $coefficient,
                    'code' => PersonalSubject::query()->where('owner_id', $user->id)->where('code', $code)->exists() ? $code.'-DEMO' : $code]);
        }

        return $subjects;
    }

    private function period(User $user): PersonalPeriod
    {
        return PersonalPeriod::query()->firstOrCreate(['owner_id' => $user->id, 'code' => 'TRIM-1-DEMO'],
            ['name' => 'Trimestre 1', 'starts_on' => now()->subDays(75)->toDateString(), 'ends_on' => now()->addDays(30)->toDateString(), 'timezone' => 'Africa/Abidjan', 'weight' => 1]);
    }

    /**
     * @param  array<string, PersonalSubject>  $subjects
     */
    private function timetable(User $user, array $subjects, string $account): void
    {
        $manager = app(ScheduleManager::class);
        $from = CarbonImmutable::now('Africa/Abidjan')->startOfWeek();
        foreach (self::TIMETABLE as $index => [$subject, $weekdays, $start, $end, $room]) {
            // Sans session, la demande agit dans l'espace individuel du compte : c'est son planning personnel.
            $request = Request::create('/planning/series', 'POST', [
                'request_key' => $this->key($account.':cours:'.$index), 'title' => $subject, 'kind' => 'weekly', 'valid_from' => $from->toDateString(),
                'valid_until' => $from->addWeeks(16)->subDay()->toDateString(), 'start_time' => $start, 'end_time' => $end, 'timezone' => 'Africa/Abidjan',
                'weekdays' => $weekdays, 'personal_subject_id' => $subjects[$subject]->id, 'room' => $room,
            ]);
            $request->setUserResolver(fn () => $user);
            try {
                $manager->create($request);
            } catch (ValidationException $refused) {
                // Un cours déjà présent sur ce créneau (essai précédent) : ce cours de démonstration est laissé de côté.
                $this->command?->warn($account.' — '.$subject.' '.$start.' non ajouté : '.implode(' ', array_merge(...array_values($refused->errors()))));
            }
        }
    }

    /**
     * @param  array<string, PersonalSubject>  $subjects
     */
    private function tasks(User $user, array $subjects, string $account): void
    {
        $tasks = app(Tasks::class);
        $today = CarbonImmutable::now('Africa/Abidjan')->startOfDay();
        foreach (self::TASKS as $index => [$title, $subject, $days, $time, $priority, $done]) {
            $content = ['title' => $title, 'description' => null, 'due_at' => $days === null ? null : $today->addDays($days)->format('Y-m-d').'T'.$time,
                'priority' => $priority, 'personal_subject_id' => $subject === null ? null : $subjects[$subject]->id];
            $result = $tasks->createPersonal($user, ['client_key' => $this->key($account.':tache:'.$index), ...$content]);
            if ($done && ! $result['replayed']) {
                $tasks->updatePersonal($user, $result['task']->id, ['expected_version' => $result['task']->lock_version, ...$content, 'status' => 'done']);
            }
        }
    }

    /**
     * @param  array<string, PersonalSubject>  $subjects
     */
    private function grades(User $user, array $subjects, PersonalPeriod $period, string $account): void
    {
        $grades = app(Grades::class);
        foreach (self::GRADES as $index => [$subject, $title, $value, $scale, $coefficient, $daysAgo]) {
            $grades->createPersonal($user, ['client_key' => $this->key($account.':note:'.$index), 'title' => $title, 'personal_subject_id' => $subjects[$subject]->id,
                'personal_period_id' => $period->id, 'status' => 'graded', 'value' => $value, 'scale' => $scale, 'coefficient' => $coefficient,
                'assessed_on' => now()->subDays($daysAgo)->toDateString()]);
        }
    }

    /**
     * Catalogue varié, pour que chaque filtre renvoie quelque chose : catégories, niveaux, modalités, financements, pays, langues, domaines et dates limites.
     */
    private function opportunities(): void
    {
        $editor = User::query()->where('email', 'editeur@example.test')->firstOrFail();
        // Catégorie, titre, organisateur, pays, modalité, niveaux, domaines, langues, financement, frais, jours avant la date limite (null : clôture continue).
        $examples = [
            ['scholarship', 'Bourse d’excellence en informatique', 'Fondation fictive Horizon', ['CI'], 'onsite', ['bachelor', 'master'], ['Informatique'], ['fr'], 'full', 'free', 20],
            ['scholarship', 'Bourse de mobilité pour un semestre à l’étranger', 'Université fictive du Littoral', ['FR', 'CA'], 'onsite', ['bachelor'], ['Économie', 'Gestion'], ['fr', 'en'], 'partial', 'free', 55],
            ['scholarship', 'Bourse de recherche en mathématiques appliquées', 'Institut fictif des Sciences', ['SN'], 'hybrid', ['master', 'doctorate'], ['Mathématiques'], ['fr'], 'full', 'free', 80],
            ['competition', 'Concours national de plaidoirie', 'Association fictive des Juristes', ['CI'], 'onsite', ['bachelor', 'master'], ['Droit'], ['fr'], 'none', 'paid', 12],
            ['competition', 'Olympiade de programmation', 'Cercle fictif du Code', ['CI', 'SN', 'BJ'], 'remote', ['secondary', 'bachelor'], ['Informatique', 'Mathématiques'], ['fr', 'en'], 'none', 'free', 35],
            ['competition', 'Prix de la nouvelle francophone', 'Maison fictive des Lettres', ['FR'], 'remote', ['any'], ['Lettres'], ['fr'], 'unspecified', 'free', 65],
            ['internship', 'Stage de développement web, six mois', 'Société fictive Baobab Numérique', ['CI'], 'hybrid', ['bachelor', 'master'], ['Informatique'], ['fr'], 'partial', 'free', null],
            ['internship', 'Stage d’assistant en santé publique', 'Organisation fictive Santé pour Tous', ['BJ'], 'onsite', ['master'], ['Santé'], ['fr', 'en'], 'partial', 'free', 28],
            ['internship', 'Stage en communication et réseaux sociaux', 'Agence fictive Palmier', ['CI'], 'remote', ['bachelor', 'professional'], ['Communication'], ['fr'], 'none', 'free', 9],
            ['training', 'Formation courte : bases de la comptabilité', 'Centre fictif des Métiers', ['CI'], 'onsite', ['professional', 'bachelor'], ['Gestion'], ['fr'], 'none', 'paid', 18],
            ['training', 'Formation en ligne : anglais professionnel', 'École fictive des Langues', ['CI', 'SN', 'FR'], 'remote', ['any'], ['Langues'], ['en'], 'unspecified', 'paid', null],
            ['training', 'Atelier d’agronomie : sols et cultures', 'Ferme-école fictive du Bandama', ['CI'], 'onsite', ['secondary', 'bachelor'], ['Agronomie'], ['fr'], 'full', 'free', 42],
            ['scholarship', 'Bourse de rentrée (édition close)', 'Fondation fictive Horizon', ['CI'], 'onsite', ['bachelor'], ['Informatique'], ['fr'], 'partial', 'free', -10],
        ];
        $normalize = fn (string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
        foreach ($examples as $index => [$category, $name, $organizer, $countries, $modality, $levels, $fields, $languages, $funding, $fees, $days]) {
            $title = '[FICTIF] '.$name;
            if (Opportunity::query()->where('normalized_title', $normalize($title))->where('campaign', 'Démonstration 2026')->exists()) {
                continue;
            }
            $day = $days === null ? null : CarbonImmutable::now('UTC')->addDays($days)->startOfDay();
            $slug = 'demonstration-'.($index + 1);
            $opportunity = Opportunity::create([
                'category' => $category, 'title' => $title, 'organizer' => $organizer, 'campaign' => 'Démonstration 2026', 'countries' => $countries, 'modality' => $modality,
                'levels' => $levels, 'fields' => $fields, 'languages' => $languages, 'funding' => $funding, 'fees' => $fees, 'fees_detail' => $fees === 'paid' ? 'Montant fictif' : null,
                'summary' => 'Fiche de démonstration. Cette opportunité n’existe pas : elle sert à essayer la recherche, les filtres et le suivi.',
                'conditions' => 'Conditions fictives, rédigées uniquement pour illustrer l’écran. Aucune candidature n’est possible.',
                'source_url' => 'https://example.test/'.$slug, 'application_url' => 'https://example.test/'.$slug.'/candidature',
                'deadline_kind' => $day === null ? 'rolling' : 'date', 'deadline_date' => $day?->toDateString(), 'last_day_starts_at' => $day?->subHours(14), 'closes_at' => $day?->addDay()->addHours(12),
                'status' => 'published', 'verified_at' => now(), 'verified_by' => $editor->id, 'published_at' => now(), 'created_by' => $editor->id,
                'normalized_title' => $normalize($title), 'normalized_organizer' => $normalize($organizer),
                'normalized_fields' => implode(' | ', array_map($normalize, $fields)), 'source_hash' => hash('sha256', 'example.test/'.$slug.'?'),
            ]);
            OpportunityRevision::create(['opportunity_id' => $opportunity->id, 'version' => 1, 'action' => 'created', 'actor_id' => $editor->id,
                'note' => 'Fiche fictive de démonstration', 'snapshot' => ['status' => $opportunity->status, 'title' => $opportunity->title], 'created_at' => now()]);
        }
    }

    /** Clé de demande stable : relancer le jeu retrouve les mêmes objets au lieu d'en créer d'autres. */
    private function key(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://example.test/campus-demonstration/'.$name)->toString();
    }
}
