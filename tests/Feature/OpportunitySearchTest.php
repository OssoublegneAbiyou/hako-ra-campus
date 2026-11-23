<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Opportunity;
use App\Models\OpportunityProfile;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\OpportunitySearch;
use App\Support\OpportunityMatcher;
use Carbon\CarbonImmutable;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OpportunitySearchTest extends TestCase
{
    use DatabaseTransactions;

    private const CONDITIONS = 'Conditions fictives publiées par l’organisme fictif : être inscrit dans un établissement.';

    /** @var array<string, Opportunity> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-11-16 08:00');
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function as(string $name, ?string $letter = null): static
    {
        $membership = $letter ? $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail()->id : null;

        return $this->freshDevice()->actingAs($this->user($name))->withSession($membership ? [ActiveContext::SESSION_KEY => $membership] : []);
    }

    private function editor(): static
    {
        $membership = $this->user('editeur')->memberships()->where('scope', 'platform')->where('role', 'catalogue_editor')->firstOrFail();

        return $this->freshDevice()->actingAs($this->user('editeur'))->withSession([ActiveContext::SESSION_KEY => $membership->id]);
    }

    /** Crée une fiche par les routes de l'éditeur ; publiée sauf mention contraire. */
    private function record(string $key, array $overrides, string $status = 'published'): Opportunity
    {
        $payload = array_replace(['category' => 'scholarship', 'title' => 'Fiche fictive '.$key, 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/'.$key,
            'application_url' => null, 'summary' => null, 'conditions' => self::CONDITIONS, 'countries' => [], 'modality' => 'unspecified', 'levels' => [], 'fields' => [], 'languages' => [],
            'eligible_nationalities' => [], 'eligible_residences' => [], 'funding' => 'unspecified', 'fees' => 'unspecified', 'fees_detail' => null, 'deadline_kind' => 'unknown',
            'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null], $overrides);
        $id = $this->editor()->postJson('/administration/catalogue', $payload)->assertOk()->json('id');
        $transition = fn (string $action, int $version, array $extra = []) => $this->editor()->postJson('/administration/catalogue/'.$id.'/transition', ['action' => $action, 'expected_version' => $version, ...$extra])->assertOk();
        if ($status !== 'draft') {
            $transition('submit', 1);
            $transition('verify', 2, ['attest' => true]);
        }
        if ($status === 'suspended') {
            $transition('suspend', 3, ['note' => 'Motif interne fictif']);
        }

        return $this->records[$key] = Opportunity::findOrFail($id);
    }

    /** Jeu de fiches fictives, publiées à une minute d'intervalle pour le tri par publication. */
    private function catalogue(): void
    {
        $this->record('alpha', ['title' => 'Bourse fictive de physique', 'organizer' => 'Fondation fictive Alpha', 'levels' => ['master'], 'countries' => ['FR'], 'fields' => ['Physique', 'Sciences'],
            'modality' => 'onsite', 'funding' => 'full', 'fees' => 'free', 'languages' => ['fr'], 'application_url' => 'https://candidature.example.test/alpha?ref=fictif',
            'deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $this->at('2026-11-16 08:01');
        $this->record('beta', ['category' => 'internship', 'title' => 'Stage fictif de physique appliquée', 'organizer' => 'Laboratoire fictif Beta', 'levels' => ['bachelor', 'master'], 'countries' => ['CI'],
            'fields' => ['Physique'], 'modality' => 'remote', 'funding' => 'partial', 'languages' => ['en'], 'deadline_kind' => 'date', 'deadline_date' => '2026-11-30']);
        $this->at('2026-11-16 08:02');
        $this->record('gamma', ['category' => 'training', 'title' => 'Formation fictive en gestion', 'organizer' => 'Institut fictif de physique', 'levels' => ['any'], 'countries' => ['CI', 'FR'],
            'fields' => ['Gestion'], 'modality' => 'hybrid', 'funding' => 'none', 'languages' => ['fr', 'en'], 'deadline_kind' => 'rolling']);
        $this->at('2026-11-16 08:03');
        $this->record('delta', ['category' => 'competition', 'title' => 'Concours fictif de lettres', 'organizer' => 'Académie fictive', 'levels' => ['secondary'], 'deadline_kind' => 'unknown']);
        $this->at('2026-11-16 08:04');
        $this->record('epsilon', ['title' => 'Bourse fictive de chimie bientôt close', 'levels' => ['doctorate'], 'countries' => ['SN'], 'fields' => ['Chimie'], 'languages' => ['fr'],
            'deadline_kind' => 'instant', 'deadline_local' => '2026-11-17T10:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $this->record('brouillon', ['title' => 'Brouillon fictif de physique', 'levels' => ['master'], 'countries' => ['FR'], 'fields' => ['Physique']], 'draft');
        $this->record('suspendue', ['title' => 'Bourse fictive suspendue de physique', 'levels' => ['master'], 'countries' => ['FR'], 'fields' => ['Physique']], 'suspended');
        $this->at('2026-11-16 09:00');
    }

    private function search(string $query = '', ?string $as = null): TestResponse
    {
        return ($as ? $this->as($as) : $this->freshDevice())->get('/opportunites'.$query);
    }

    /** Clés des fiches renvoyées, dans l'ordre. */
    private function keys(string $query = ''): array
    {
        $ids = collect($this->records)->mapWithKeys(fn (Opportunity $record, string $key) => [$record->id => $key]);

        return collect($this->search($query)->assertOk()->viewData('page')['props']['opportunities'])->map(fn (array $item) => $ids[$item['id']] ?? $item['title'])->all();
    }

    private function profile(string $name, array $data): TestResponse
    {
        $version = OpportunityProfile::where('user_id', $this->user($name)->id)->value('lock_version') ?? 0;

        return $this->as($name)->putJson('/opportunites/profil', ['expected_version' => $version, 'level' => null, 'fields' => [], 'languages' => [], 'residence_country' => null, 'nationality' => null, ...$data]);
    }

    /** Résultats par critère de la comparaison affichée sur une fiche. */
    private function criteria(string $name, Opportunity $record): array
    {
        $match = $this->as($name)->get('/opportunites/'.$record->id)->assertOk()->viewData('page')['props']['match'];

        return ['overall' => $match['overall'], ...collect($match['criteria'])->pluck('result', 'key')->all()];
    }

    public function test_r32_combined_filters_search_and_sorts_return_only_matching_published_records(): void
    {
        $this->catalogue();
        // Liste ouverte par défaut : clôture connue la plus proche d'abord, puis les fiches sans date, par titre. Ni brouillon ni fiche suspendue.
        $this->assertSame(['epsilon', 'beta', 'alpha', 'delta', 'gamma'], $this->keys());
        // Un filtre à la fois.
        $this->assertSame(['beta', 'alpha', 'gamma'], $this->keys('?niveau=master'));
        $this->assertSame(['alpha', 'gamma'], $this->keys('?pays=FR'));
        $this->assertSame(['alpha', 'gamma'], $this->keys('?pays=fr'));
        $this->assertSame(['beta', 'alpha'], $this->keys('?domaine=Physique'));
        $this->assertSame(['beta', 'alpha'], $this->keys('?domaine=physique'));
        $this->assertSame(['beta'], $this->keys('?modalite=remote'));
        $this->assertSame(['alpha'], $this->keys('?financement=full'));
        $this->assertSame(['beta', 'gamma'], $this->keys('?langue=en'));
        $this->assertSame(['beta'], $this->keys('?categorie=internship'));
        // Filtres combinés : niveau, pays, domaine, modalité.
        $this->assertSame(['alpha', 'gamma'], $this->keys('?niveau=master&pays=FR'));
        $this->assertSame(['alpha'], $this->keys('?niveau=master&pays=FR&domaine=Physique'));
        $this->assertSame(['alpha'], $this->keys('?niveau=master&pays=FR&domaine=Physique&modalite=onsite'));
        $this->assertSame([], $this->keys('?niveau=master&pays=FR&domaine=Physique&modalite=remote'));
        $this->assertSame(['beta'], $this->keys('?niveau=bachelor&pays=CI&langue=en&financement=partial&categorie=internship'));
        // « Tous niveaux » répond à tout niveau demandé ; une fiche sans niveau publié ne répond à aucun.
        $this->assertSame(['gamma'], $this->keys('?niveau=professional'));

        // Recherche sur le titre, l'organisateur et les domaines, sans accents ni casse ; tous les mots doivent être trouvés.
        $this->assertSame(['beta', 'alpha', 'gamma'], $this->keys('?q=physique'));
        $this->assertSame(['beta', 'alpha', 'gamma'], $this->keys('?q=PHYSIQUE'));
        $this->assertSame(['beta'], $this->keys('?q=physique+appliquee'));
        $this->assertSame(['alpha'], $this->keys('?q=fondation'));
        $this->assertSame(['gamma'], $this->keys('?q=gestion'));
        $this->assertSame(['alpha'], $this->keys('?q=physique&pays=FR&niveau=master&modalite=onsite'));
        $this->assertSame([], $this->keys('?q=introuvable'));
        // Aucun caractère de la saisie n'est interprété : ni joker, ni fragment de requête.
        $this->assertSame(5, count($this->keys('?q=%25')));
        $this->assertSame(5, count($this->keys('?q=_')));
        // Une tentative d'injection n'est qu'une recherche de mots : ici le seul mot retenu est « or », cherché comme n'importe quel autre.
        $this->assertSame($this->keys('?q=or'), $this->keys('?q='.urlencode("' OR 1=1 --")));
        $this->assertSame(['or'], app(OpportunitySearch::class)->terms("' OR 1=1 --"));
        $this->assertSame([], $this->keys('?q='.urlencode('"; DROP TABLE opportunities; --')));
        $this->assertSame(7, Opportunity::count());
        $this->assertSame([], $this->keys('?domaine='.urlencode('%')));
        $this->assertSame(['physique', 'appliquee'], app(OpportunitySearch::class)->terms(' Physique  appliquée ! a '));
        $this->assertCount(5, app(OpportunitySearch::class)->terms('un deux trois quatre cinq six sept'));

        // Tris. Pertinence : titre 3 + domaine 1 pour alpha et beta (départagées par la clôture), organisateur 2 pour gamma.
        $this->assertSame(['beta', 'alpha', 'gamma'], $this->keys('?q=physique&tri=pertinence'));
        $this->assertSame(['gamma', 'beta', 'alpha'], $this->keys('?q=physique&tri=publication'));
        $this->assertSame(['epsilon', 'delta', 'gamma', 'beta', 'alpha'], $this->keys('?tri=publication'));
        // Pertinence sans mot recherché : le tri appliqué, et affiché, est la clôture.
        $this->search('?tri=pertinence')->assertInertia(fn (Assert $page) => $page->where('filters.tri', 'cloture')->where('searchedTerms', []));
        $this->assertSame(['epsilon', 'beta', 'alpha', 'delta', 'gamma'], $this->keys('?tri=pertinence'));

        // Filtres renvoyés pour l'affichage et la réinitialisation ; valeurs proposées tirées des fiches consultables seulement.
        $this->search('?niveau=master&pays=fr&q=physique&tri=pertinence')->assertInertia(fn (Assert $page) => $page->component('Opportunities')
            ->where('filters', ['q' => 'physique', 'categorie' => '', 'niveau' => 'master', 'pays' => 'FR', 'domaine' => '', 'modalite' => '', 'financement' => '', 'langue' => '', 'cloture' => 'ouvertes', 'tri' => 'pertinence'])
            ->where('searchedTerms', ['physique'])->where('pagination.count', 2)->where('relevanceRule', OpportunitySearch::RELEVANCE)
            ->where('options.countries', fn ($countries) => collect($countries)->pluck('value')->all() === ['CI', 'FR', 'SN'] && collect($countries)->firstWhere('value', 'FR')['label'] === 'France (FR)')
            ->where('options.languages', fn ($languages) => collect($languages)->pluck('value')->all() === ['en', 'fr'])
            ->where('options.fields', ['Chimie', 'Gestion', 'Physique', 'Sciences'])->has('options.closings', 5)->has('options.sorts', 3));

        // Brouillon et fiche suspendue : absents de toute recherche, de tout filtre et de toute page.
        foreach (['', '?q=physique', '?q=brouillon', '?q=suspendue', '?cloture=toutes', '?cloture=closes', '?cloture=sans_date', '?niveau=master&pays=FR&domaine=Physique&cloture=toutes'] as $query) {
            $this->assertEmpty(array_intersect(['brouillon', 'suspendue'], $this->keys($query)), $query);
            $this->search($query)->assertDontSee('Brouillon fictif')->assertDontSee('suspendue de physique')->assertDontSee('Motif interne');
        }
    }

    public function test_search_parameters_are_validated_and_results_are_paginated(): void
    {
        $this->catalogue();
        foreach (['niveau=genie', 'pays=FRA', 'pays=1', 'langue=francais', 'categorie=loterie', 'modalite=lune', 'financement=garanti', 'cloture=demain', 'tri=hasard', 'page=0', 'page=abc', 'page=1001',
            'q='.str_repeat('a', 101), 'domaine='.str_repeat('a', 61), 'categorie[]=scholarship', 'q[]=physique'] as $invalid) {
            $this->search('?'.$invalid)->assertSessionHasErrors(explode('=', str_replace('[]', '', $invalid))[0]);
            $this->freshDevice()->getJson('/opportunites?'.$invalid)->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
        }
        // Paramètre inconnu : ignoré, jamais un moyen d'élargir la recherche.
        $this->assertSame(['epsilon', 'beta', 'alpha', 'delta', 'gamma'], $this->keys('?statut=draft&status=draft&institution_id=1'));

        // Vingt fiches par page ; les filtres suivent la pagination.
        $editor = $this->user('editeur')->id;
        foreach (range(1, 23) as $index) {
            Opportunity::create(['category' => 'training', 'title' => sprintf('Atelier fictif paginé n° %02d', $index), 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/atelier-'.$index,
                'conditions' => self::CONDITIONS, 'countries' => ['CI'], 'levels' => [], 'fields' => [], 'languages' => [], 'status' => 'published', 'verified_at' => now(), 'verified_by' => $editor, 'published_at' => now(),
                'normalized_title' => sprintf('atelier fictif pagine n %02d', $index), 'normalized_organizer' => 'organisme fictif', 'source_hash' => hash('sha256', 'atelier-'.$index), 'created_by' => $editor]);
        }
        $first = $this->search('?q=atelier&pays=CI')->assertOk()->viewData('page')['props'];
        $this->assertSame([20, 1, 2, 23, null], [count($first['opportunities']), $first['pagination']['page'], $first['pagination']['total'], $first['pagination']['count'], $first['pagination']['previous']]);
        $this->assertStringContainsString('q=atelier', $first['pagination']['next']);
        $this->assertStringContainsString('pays=CI', $first['pagination']['next']);
        $this->assertStringContainsString('page=2', $first['pagination']['next']);
        $second = $this->search('?q=atelier&pays=CI&page=2')->assertOk()->viewData('page')['props'];
        $this->assertSame([3, 2, null, 'Atelier fictif paginé n° 21'], [count($second['opportunities']), $second['pagination']['page'], $second['pagination']['next'], $second['opportunities'][0]['title']]);
        $this->assertEmpty(array_intersect(array_column($first['opportunities'], 'id'), array_column($second['opportunities'], 'id')));
        $this->search('?q=atelier&page=9')->assertOk()->assertInertia(fn (Assert $page) => $page->has('opportunities', 0)->where('pagination.count', 23));
    }

    public function test_r34_unknown_and_rolling_deadlines_stay_visible_and_closed_records_leave_the_open_list(): void
    {
        $this->catalogue();
        // Clôture dans les 30 jours : seulement des fiches à date connue ; les fiches sans date n'y sont pas, et ne sont pas présentées comme closes.
        $this->assertSame(['epsilon', 'beta', 'alpha'], $this->keys('?cloture=30j'));
        $this->assertSame(['delta', 'gamma'], $this->keys('?cloture=sans_date'));
        $this->assertSame([], $this->keys('?cloture=closes'));
        $this->assertSame(['epsilon', 'beta', 'alpha', 'delta', 'gamma'], $this->keys('?cloture=toutes'));
        // La précision d'origine est conservée dans la liste comme sur la fiche.
        $items = collect($this->search()->viewData('page')['props']['opportunities'])->keyBy('title');
        $pick = fn (string $title, array $keys) => array_map(fn (string $key) => $items[$title]['deadline'][$key], $keys);
        $this->assertSame(['unknown', 'Date limite non communiquée', 'unknown', null], $pick('Concours fictif de lettres', ['kind', 'text', 'state', 'closesAt']));
        $this->assertSame(['rolling', 'Clôture continue : pas de date limite annoncée', 'rolling'], $pick('Formation fictive en gestion', ['kind', 'text', 'state']));
        $this->assertSame(['date', '30/11/2026 (heure non communiquée)', 'open'], $pick('Stage fictif de physique appliquée', ['kind', 'text', 'state']));
        $this->assertSame('17/11/2026 à 10:00 (Africa/Abidjan)', $items['Bourse fictive de chimie bientôt close']['deadline']['text']);
        $this->search('/'.$this->records['delta']->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.deadline.kind', 'unknown')->where('opportunity.deadline.kindLabel', 'Date non communiquée')
            ->where('opportunity.deadline.instantUtc', null)->where('opportunity.deadline.date', null)->where('opportunity.availability', 'published'));

        // Clôture passée, avant l'archivage : la fiche quitte la liste ouverte, reste identifiée comme close dans « clôturées ».
        $this->at('2026-11-17 10:00');
        $this->assertSame(['beta', 'alpha', 'delta', 'gamma'], $this->keys());
        $this->assertSame(['epsilon'], $this->keys('?cloture=closes'));
        $this->assertSame(['beta', 'alpha'], $this->keys('?cloture=30j'));
        $this->search('?cloture=closes')->assertInertia(fn (Assert $page) => $page->where('opportunities.0.deadline.state', 'closed')->where('opportunities.0.availability', 'published'));
        // Après archivage : même résultat pour le public, la fiche est identifiée « archivée » et sans lien de candidature.
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame(['beta', 'alpha', 'delta', 'gamma'], $this->keys());
        $this->assertSame(['epsilon'], $this->keys('?cloture=closes'));
        $this->assertSame(['epsilon', 'beta', 'alpha', 'delta', 'gamma'], $this->keys('?cloture=toutes'));
        $this->search('?cloture=closes')->assertInertia(fn (Assert $page) => $page->where('opportunities.0.availability', 'archived'));
        // Les fiches sans date connue ne sont jamais closes, même des années plus tard.
        $this->at('2030-01-01 00:00');
        $this->artisan('campus:opportunities:archive-closed')->assertSuccessful();
        $this->assertSame(['delta', 'gamma'], $this->keys());
        $this->assertSame(['delta', 'gamma'], $this->keys('?cloture=sans_date'));
        $this->assertEqualsCanonicalizing(['epsilon', 'beta', 'alpha'], $this->keys('?cloture=closes'));
    }

    public function test_r33_an_incomplete_profile_gives_information_to_verify_and_never_an_invented_ineligibility(): void
    {
        $this->catalogue();
        $alpha = $this->records['alpha'];
        $strict = $this->record('stricte', ['title' => 'Bourse fictive à conditions de nationalité', 'levels' => ['master'], 'fields' => ['Physique'], 'languages' => ['fr'],
            'eligible_nationalities' => ['ci', 'SN'], 'eligible_residences' => ['FR']]);
        $this->assertSame([['CI', 'SN'], ['FR']], [$strict->eligible_nationalities, $strict->eligible_residences]);

        // Visiteur : consultation libre, aucune comparaison. Compte sans critère déclaré : rien n'est comparé ni déduit, même avec des appartenances.
        $this->search('/'.$alpha->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('signedIn', false)->where('hasProfile', false)->where('match', null));
        $this->search()->assertInertia(fn (Assert $page) => $page->where('signedIn', false)->where('opportunities.0.match', null));
        $this->search('/'.$strict->id, 'apprenant-double')->assertInertia(fn (Assert $page) => $page->where('signedIn', true)->where('hasProfile', false)->where('match', null));
        $this->search('', 'apprenant-double')->assertInertia(fn (Assert $page) => $page->where('hasProfile', false)->where('opportunities.0.match', null));
        $this->freshDevice()->get('/opportunites/profil')->assertRedirect('/connexion');
        $this->freshDevice()->put('/opportunites/profil', ['expected_version' => 0])->assertRedirect('/connexion');

        // Saisie : tout est facultatif ; niveau « tous niveaux » non déclarable ; codes à deux lettres ; pays de résidence et nationalité distincts.
        foreach ([['level' => 'any'], ['level' => 'genie'], ['languages' => ['français']], ['nationality' => 'CIV'], ['residence_country' => 'Côte d’Ivoire'], ['fields' => array_fill(0, 11, 'x')], ['fields' => 'Physique']] as $invalid) {
            $this->profile('apprenant-double', $invalid)->assertStatus(422);
        }
        $this->assertSame(0, OpportunityProfile::count());
        $this->profile('apprenant-double', ['level' => 'master'])->assertOk()->assertJson(['version' => 1]);
        $this->as('apprenant-double')->putJson('/opportunites/profil', ['expected_version' => 0, 'level' => 'bachelor', 'fields' => [], 'languages' => []])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);

        // Profil incomplet (niveau seul) : ce qui est connu est comparé ; tout le reste est « à vérifier », jamais « non satisfait ».
        $this->assertSame(['overall' => 'check', 'level' => 'match', 'language' => 'check', 'field' => 'check', 'nationality' => 'check', 'residence' => 'check'], $this->criteria('apprenant-double', $strict));
        $shown = $this->as('apprenant-double')->get('/opportunites/'.$strict->id)->viewData('page')['props'];
        $this->assertSame(['Information à vérifier', OpportunityMatcher::DISCLAIMER, true], [$shown['match']['overallLabel'], $shown['matchDisclaimer'], $shown['hasProfile']]);
        $nationality = collect($shown['match']['criteria'])->firstWhere('key', 'nationality');
        $this->assertSame('Information à vérifier', $nationality['resultLabel']);
        $this->assertStringContainsString('Vous n’avez pas déclaré votre nationalité', $nationality['explanation']);
        $this->assertStringContainsString('Sénégal (SN)', $nationality['explanation']);
        // Fiche qui ne publie pas un critère : « à vérifier » aussi, quel que soit le profil.
        $this->assertSame(['overall' => 'check', 'level' => 'match', 'language' => 'check', 'field' => 'check', 'nationality' => 'check', 'residence' => 'check'], $this->criteria('apprenant-double', $alpha));
        $this->assertSame(['overall' => 'check', 'level' => 'match', 'language' => 'check', 'field' => 'check', 'nationality' => 'check', 'residence' => 'check'], $this->criteria('apprenant-double', $this->records['gamma']));
        // Seul un critère réellement déclaré et contraire donne « non satisfait » : ici le niveau, face à une fiche réservée au secondaire.
        $this->assertSame(['overall' => 'mismatch', 'level' => 'mismatch', 'language' => 'check', 'field' => 'check', 'nationality' => 'check', 'residence' => 'check'], $this->criteria('apprenant-double', $this->records['delta']));
        // La liste porte le même verdict d'ensemble, sans détail.
        $this->search('?q=nationalite', 'apprenant-double')->assertInertia(fn (Assert $page) => $page->where('hasProfile', true)->where('opportunities.0.match', ['overall' => 'check', 'overallLabel' => 'Information à vérifier']));

        // Critère déclaré et contraire à la fiche : « non satisfait », avec l'explication des deux côtés.
        $this->profile('apprenant-double', ['level' => 'bachelor'])->assertOk();
        $this->assertSame(['mismatch', 'mismatch'], [$this->criteria('apprenant-double', $strict)['level'], $this->criteria('apprenant-double', $strict)['overall']]);
        $this->assertSame('match', $this->criteria('apprenant-double', $this->records['gamma'])['level']);
        $this->assertSame('check', $this->criteria('apprenant-double', $this->record('sans-niveau', ['title' => 'Fiche fictive sans niveau']))['level']);
        $level = collect($this->as('apprenant-double')->get('/opportunites/'.$strict->id)->viewData('page')['props']['match']['criteria'])->firstWhere('key', 'level');
        $this->assertSame(['Critère non satisfait', 'La fiche vise : Master. Vous avez déclaré : Licence.'], [$level['resultLabel'], $level['explanation']]);

        // Nationalité et pays de résidence ne se confondent jamais.
        $this->profile('apprenant-double', ['level' => 'master', 'nationality' => 'FR', 'residence_country' => 'ci'])->assertOk();
        $this->assertSame(['mismatch', 'mismatch'], [$this->criteria('apprenant-double', $strict)['nationality'], $this->criteria('apprenant-double', $strict)['residence']]);
        $this->profile('apprenant-double', ['level' => 'master', 'nationality' => 'CI', 'residence_country' => 'FR'])->assertOk();
        $this->assertSame(['match', 'match'], [$this->criteria('apprenant-double', $strict)['nationality'], $this->criteria('apprenant-double', $strict)['residence']]);
        $this->profile('apprenant-double', ['level' => 'master', 'nationality' => 'CI'])->assertOk();
        $this->assertSame(['match', 'check'], [$this->criteria('apprenant-double', $strict)['nationality'], $this->criteria('apprenant-double', $strict)['residence']]);

        // Domaines : intitulé commun (sans accents ni casse) = correspond ; aucun intitulé commun = à vérifier, pas « non satisfait ».
        $this->profile('apprenant-double', ['fields' => ['  PHYSIQUE ', 'Biologie']])->assertOk();
        $this->assertSame('match', $this->criteria('apprenant-double', $strict)['field']);
        $this->profile('apprenant-double', ['fields' => ['Chimie']])->assertOk();
        $this->assertSame('check', $this->criteria('apprenant-double', $strict)['field']);
        // Langues : aucune langue commune déclarée = non satisfait ; une langue commune = correspond.
        $this->profile('apprenant-double', ['languages' => ['EN']])->assertOk();
        $this->assertSame('mismatch', $this->criteria('apprenant-double', $strict)['language']);

        // Tous les critères publiés et déclarés concordent : « correspond à vos critères », toujours avec la mention indicative.
        $this->profile('apprenant-double', ['level' => 'master', 'fields' => ['Physique'], 'languages' => ['fr', 'en'], 'nationality' => 'SN', 'residence_country' => 'FR'])->assertOk();
        $this->assertSame(['overall' => 'match', 'level' => 'match', 'language' => 'match', 'field' => 'match', 'nationality' => 'match', 'residence' => 'match'], $this->criteria('apprenant-double', $strict));
        $full = $this->as('apprenant-double')->get('/opportunites/'.$strict->id)->viewData('page')['props'];
        $this->assertSame('Correspond à vos critères déclarés', $full['match']['overallLabel']);
        $this->assertStringContainsString('Ce n’est pas une décision d’éligibilité', $full['matchDisclaimer']);
        $saved = OpportunityProfile::sole();
        $this->assertSame([$this->user('apprenant-double')->id, 'master', ['Physique'], ['fr', 'en'], 'SN', 'FR'], [$saved->user_id, $saved->level, $saved->fields, $saved->languages, $saved->nationality, $saved->residence_country]);
        $this->as('apprenant-double')->get('/opportunites/profil')->assertOk()->assertInertia(fn (Assert $page) => $page->component('OpportunityProfile')->where('profile.nationality', 'SN')->where('profile.residenceCountry', 'FR')
            ->where('labels.nationality', 'Sénégal (SN)')->where('levels', fn ($levels) => ! collect($levels)->has('any') && collect($levels)->has('master')));

        // Les critères n'appartiennent qu'à leur auteur : les autres comptes n'en voient rien, et rien n'est journalisé.
        foreach (['polyvalent', 'admin-a', 'editeur', 'plateforme'] as $other) {
            $this->search('/'.$strict->id, $other)->assertInertia(fn (Assert $page) => $page->where('hasProfile', false)->where('match', null));
            $this->as($other)->get('/opportunites/profil')->assertOk()->assertInertia(fn (Assert $page) => $page->where('profile.version', 0)->where('profile.nationality', '')->where('profile.level', ''));
        }
        $this->assertSame(0, AuditLog::where('subject_type', 'opportunity_profiles')->count() + DomainEvent::where('type', 'like', '%profile%')->count());
        $this->assertStringNotContainsString('"SN"', json_encode([AuditLog::all()->toArray(), DomainEvent::all()->toArray()]));
        // Une fiche suspendue n'est jamais comparée. Tout effacer supprime toute comparaison.
        $this->search('/'.$this->records['suspendue']->id, 'apprenant-double')->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.availability', 'suspended')->where('match', null));
        $this->profile('apprenant-double', [])->assertOk();
        $this->search('/'.$strict->id, 'apprenant-double')->assertInertia(fn (Assert $page) => $page->where('hasProfile', false)->where('match', null));
        try {
            DB::table('opportunity_profiles')->update(['level' => 'any']);
            $this->fail('MySQL a accepté un niveau non déclarable.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }
    }

    public function test_r40_opening_the_external_application_link_records_nothing_and_transmits_nothing(): void
    {
        $this->catalogue();
        $alpha = $this->records['alpha'];
        $this->profile('apprenant-double', ['level' => 'master', 'nationality' => 'CI'])->assertOk();
        $tables = ['opportunities', 'opportunity_revisions', 'opportunity_reports', 'opportunity_report_events', 'opportunity_profiles', 'audit_logs', 'domain_events', 'user_notifications', 'tasks', 'jobs'];
        $counts = fn () => collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $before = [$counts(), Opportunity::max('updated_at'), OpportunityProfile::max('updated_at')];

        // La fiche montre tout ce qu'il faut avant de sortir : financement, frais, précision de l'échéance, organisateur, date de vérification, domaine de destination.
        foreach ([null, 'apprenant-double'] as $name) {
            $this->search('/'.$alpha->id, $name)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Opportunity')
                ->where('opportunity.funding', 'Financement complet')->where('opportunity.fees', 'Candidature gratuite')->where('opportunity.organizer', 'Fondation fictive Alpha')
                ->where('opportunity.deadline.kindLabel', 'Date et heure, avec fuseau')->where('opportunity.deadline.text', '10/12/2026 à 23:59 (Europe/Paris)')->where('opportunity.verifiedAt', '2026-11-16T08:00:00+00:00')
                // Le lien de candidature est l'adresse de l'organisateur elle-même : aucune adresse de Campus ne s'intercale, aucun paramètre n'y est ajouté.
                ->where('opportunity.applicationUrl', 'https://candidature.example.test/alpha?ref=fictif')->where('opportunity.applicationHost', 'candidature.example.test')
                ->where('opportunity.sourceUrl', 'https://example.test/alpha')->where('opportunity.sourceHost', 'example.test'));
        }
        // Aucun critère déclaré, aucune adresse électronique ni identifiant de compte ne figure dans les liens sortants ni ailleurs dans la page de la fiche.
        $content = $this->search('/'.$alpha->id, 'apprenant-double')->getContent();
        foreach (['apprenant-double@example.test', 'user_id', '"email"', 'residence_country'] as $private) {
            $this->assertStringNotContainsString($private, $content);
        }
        // Aucune route ne reçoit le clic : ni redirection, ni suivi, ni changement d'état d'une démarche.
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'opportunites'))->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())->sort()->values()->all();
        // Les routes de favori et de démarche (lot 17) sont des actions explicites de la personne ; aucune ne correspond à l'ouverture d'un lien.
        $this->assertSame(['DELETE opportunites/alertes/{search}', 'DELETE opportunites/{opportunity}/demarche', 'DELETE opportunites/{opportunity}/demarche/pieces/{item}', 'DELETE opportunites/{opportunity}/favori', 'GET opportunites',
            'GET opportunites/alertes', 'GET opportunites/alertes/{search}', 'GET opportunites/profil', 'GET opportunites/suivi', 'GET opportunites/{opportunity}', 'PATCH opportunites/alertes/{search}', 'PATCH opportunites/{opportunity}/demarche/pieces/{item}', 'POST opportunites/alertes', 'POST opportunites/{opportunity}/demarche/pieces', 'POST opportunites/{opportunity}/signalement',
            'PUT opportunites/profil', 'PUT opportunites/{opportunity}/demarche', 'PUT opportunites/{opportunity}/favori'], $routes);
        $this->assertEmpty(collect(app('router')->getRoutes())->filter(fn ($route) => preg_match('/candidat|clic|click|sortie|redirect|track/i', $route->uri().' '.$route->getName()))->all());
        foreach (['/candidature', '/sortie', '/clic'] as $suffix) {
            $this->as('apprenant-double')->post('/opportunites/'.$alpha->id.$suffix)->assertNotFound();
            $this->as('apprenant-double')->get('/opportunites/'.$alpha->id.$suffix)->assertNotFound();
        }
        // Consulter la liste et la fiche, connecté ou non, n'écrit rien : aucune démarche, aucun événement, aucune trace.
        $this->search('?q=physique&tri=pertinence', 'apprenant-double')->assertOk();
        $this->search('?q=physique')->assertOk();
        $this->assertSame($before, [$counts(), Opportunity::max('updated_at'), OpportunityProfile::max('updated_at')]);
        // Fiche archivée : plus de lien de candidature ; fiche sans lien : rien d'inventé.
        $this->search('/'.$this->records['beta']->id)->assertInertia(fn (Assert $page) => $page->where('opportunity.applicationUrl', null)->where('opportunity.applicationHost', null));
        $this->assertStringContainsString('referrerPolicy="no-referrer"', (string) file_get_contents(resource_path('js/pages/Opportunity.tsx')));
        $this->assertStringContainsString('rel="noopener noreferrer"', (string) file_get_contents(resource_path('js/pages/Opportunity.tsx')));
    }
}
