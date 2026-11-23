<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\OpportunityReportEvent;
use App\Models\OpportunityRevision;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ActiveContext;
use App\Services\NotificationStore;
use Carbon\CarbonImmutable;
use Database\Seeders\CampusDemoSeeder;
use Database\Seeders\OpportunityDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use DatabaseTransactions;

    private const CONDITIONS = 'Conditions fictives publiées par l’organisme fictif : être inscrit dans un établissement.';

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

    /** Éditeur du catalogue, dans son contexte plateforme. */
    private function editor(string $name = 'editeur'): static
    {
        $membership = $this->user($name)->memberships()->where('scope', 'platform')->where('role', 'catalogue_editor')->firstOrFail();

        return $this->freshDevice()->actingAs($this->user($name))->withSession([ActiveContext::SESSION_KEY => $membership->id]);
    }

    private function secondEditor(): void
    {
        Membership::create(['user_id' => $this->user('independant')->id, 'scope' => 'platform', 'role' => 'catalogue_editor', 'institution_id' => null, 'academic_year_id' => null,
            'status' => 'active', 'starts_at' => '2026-09-01 00:00:00']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['category' => 'scholarship', 'title' => 'Bourse fictive de mobilité', 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/bourse-fictive',
            'application_url' => null, 'summary' => 'Résumé fictif.', 'conditions' => self::CONDITIONS, 'countries' => ['fr', 'CI'], 'modality' => 'onsite', 'levels' => ['master'], 'fields' => ['Sciences fictives'],
            'languages' => ['FR'], 'funding' => 'full', 'fees' => 'free', 'fees_detail' => null, 'deadline_kind' => 'unknown', 'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null], $overrides);
    }

    private function draft(array $overrides = []): Opportunity
    {
        $response = $this->editor()->postJson('/administration/catalogue', $this->payload($overrides))->assertOk();

        return Opportunity::findOrFail($response->json('id'));
    }

    private function act(Opportunity $opportunity, string $action, array $extra = [], string $editor = 'editeur'): TestResponse
    {
        return $this->editor($editor)->postJson('/administration/catalogue/'.$opportunity->id.'/transition', ['action' => $action, 'expected_version' => $opportunity->fresh()->lock_version, ...$extra]);
    }

    private function published(array $overrides = []): Opportunity
    {
        $opportunity = $this->draft($overrides);
        $this->act($opportunity, 'submit')->assertOk();
        $this->act($opportunity, 'verify', ['attest' => true])->assertOk()->assertJson(['status' => 'published']);

        return $opportunity->fresh();
    }

    private function save(Opportunity $opportunity, array $overrides, array $extra = []): TestResponse
    {
        $current = $opportunity->fresh();
        $deadline = match ($current->deadline_kind) {
            'instant' => ['deadline_kind' => 'instant', 'deadline_local' => $current->deadline_at->setTimezone($current->deadline_timezone)->format('Y-m-d\TH:i'), 'deadline_timezone' => $current->deadline_timezone],
            'date' => ['deadline_kind' => 'date', 'deadline_date' => $current->deadline_date->toDateString()],
            default => ['deadline_kind' => $current->deadline_kind],
        };

        return $this->editor()->patchJson('/administration/catalogue/'.$current->id, ['expected_version' => $current->lock_version, ...$this->payload(['title' => $current->title, 'campaign' => $current->campaign,
            'source_url' => $current->source_url, 'conditions' => $current->conditions, ...$deadline, ...$overrides]), ...$extra]);
    }

    private function page(Opportunity $opportunity, ?string $name = null): TestResponse
    {
        return ($name ? $this->as($name) : $this->freshDevice())->get('/opportunites/'.$opportunity->id);
    }

    private function listed(string $query = ''): array
    {
        return collect($this->freshDevice()->get('/opportunites'.$query)->assertOk()->viewData('page')['props']['opportunities'])->pluck('id')->all();
    }

    public function test_r31_publication_is_blocked_without_source_organizer_conditions_and_an_explicit_verification(): void
    {
        // Saisie : source et organisateur obligatoires dès le brouillon ; adresses HTTP(S) seulement.
        foreach ([['source_url' => ''], ['organizer' => ''], ['organizer' => '   '], ['title' => '<b></b>'], ['campaign' => ''], ['category' => 'loterie'], ['funding' => 'garanti'],
            ['source_url' => 'javascript:alert(1)'], ['source_url' => 'data:text/html,fictif'], ['source_url' => 'ftp://example.test/x'], ['source_url' => '//example.test/x'], ['source_url' => 'example.test/x'],
            ['source_url' => 'https://utilisateur:secret@example.test/x'], ['source_url' => 'https://example.test/a b'], ['source_url' => 'https://example.test/"><script>'],
            ['application_url' => 'javascript:void(0)'], ['countries' => ['France']], ['levels' => ['genie']], ['languages' => ['français']]] as $invalid) {
            $this->editor()->postJson('/administration/catalogue', $this->payload($invalid))->assertStatus(422);
        }
        $this->assertSame(0, Opportunity::count());

        // Brouillon sans conditions : enregistrable, envoyable en vérification, mais non publiable.
        $opportunity = $this->draft(['conditions' => null, 'title' => 'Bourse <script>alert("x")</script>fictive <b>de mobilité</b>', 'summary' => "Résumé <img src=x onerror=alert(1)> fictif.\n\n\n\nSuite."]);
        $this->assertSame(['Bourse alert("x")fictive de mobilité', "Résumé fictif.\n\nSuite.", 'draft', ['FR', 'CI'], ['fr'], null], [$opportunity->title, $opportunity->summary, $opportunity->status, $opportunity->countries, $opportunity->languages, $opportunity->verified_at]);
        $this->act($opportunity, 'verify', ['attest' => true])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->act($opportunity, 'submit')->assertOk()->assertJson(['status' => 'review']);
        $this->act($opportunity, 'verify', ['attest' => true])->assertStatus(422)->assertJsonValidationErrors('conditions');
        $this->save($opportunity, ['conditions' => 'Trop court'])->assertOk();
        $this->act($opportunity, 'verify', ['attest' => true])->assertStatus(422)->assertJsonValidationErrors('conditions');
        $this->save($opportunity, ['conditions' => self::CONDITIONS])->assertOk();
        // Conditions présentes mais sans acte explicite de vérification : toujours bloqué.
        $this->act($opportunity, 'verify')->assertStatus(422)->assertJsonValidationErrors('attest');
        $this->act($opportunity, 'verify', ['attest' => false])->assertStatus(422)->assertJsonValidationErrors('attest');
        $this->assertSame(['review', null, null], [$opportunity->fresh()->status, $opportunity->fresh()->verified_at, $opportunity->fresh()->published_at]);
        // MySQL refuse lui aussi une fiche publiée sans vérification.
        try {
            DB::table('opportunities')->where('id', $opportunity->id)->update(['status' => 'published']);
            $this->fail('MySQL a accepté une publication sans vérification.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }

        $this->at('2026-11-16 09:30');
        $this->act($opportunity, 'verify', ['attest' => true])->assertOk()->assertJson(['status' => 'published']);
        $opportunity->refresh();
        $this->assertSame(['published', '2026-11-16 09:30:00', $this->user('editeur')->id, '2026-11-16 09:30:00'], [$opportunity->status, $opportunity->verified_at->format('Y-m-d H:i:s'), $opportunity->verified_by, $opportunity->published_at->format('Y-m-d H:i:s')]);
        // Même personne pour rédiger et vérifier : les deux actes sont tracés séparément, avec leur auteur.
        $revisions = OpportunityRevision::where('opportunity_id', $opportunity->id)->orderBy('version')->get();
        $this->assertSame(['created', 'submitted', 'updated', 'updated', 'verified'], $revisions->pluck('action')->all());
        $this->assertSame([$this->user('editeur')->id], $revisions->pluck('actor_id')->unique()->all());
        $this->assertSame(['published', self::CONDITIONS], [$revisions->last()->snapshot['status'], $revisions->last()->snapshot['conditions']]);
        $this->assertSame(1, AuditLog::where('action', 'opportunity.verified')->where('subject_uuid', $opportunity->id)->count());
        try {
            $revisions->first()->update(['action' => 'verified']);
            $this->fail('Une révision a été modifiée.');
        } catch (LogicException) {
            $this->assertSame('created', $revisions->first()->fresh()->action);
        }

        // Page publique : source et date de vérification affichées, sans donnée interne.
        $this->page($opportunity)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Opportunity')->where('opportunity.title', 'Bourse alert("x")fictive de mobilité')
            ->where('opportunity.sourceHost', 'example.test')->where('opportunity.verifiedAt', '2026-11-16T09:30:00+00:00')->where('opportunity.funding', 'Financement complet')
            ->where('canReport', false)->where('myReport', null)->missing('opportunity.verifiedBy')->missing('opportunity.status'));
        $this->page($opportunity)->assertDontSee('editeur@example.test')->assertDontSee('Éditeur fictif');
    }

    public function test_visitors_only_see_published_records_and_only_the_editor_reaches_the_administration(): void
    {
        $draft = $this->draft(['title' => 'Brouillon fictif confidentiel']);
        $review = $this->draft(['title' => 'Fiche fictive à vérifier', 'source_url' => 'https://example.test/a-verifier']);
        $this->act($review, 'submit')->assertOk();
        $published = $this->published(['title' => 'Fiche fictive publiée', 'source_url' => 'https://example.test/publiee']);
        $suspended = $this->published(['title' => 'Fiche fictive suspendue', 'source_url' => 'https://example.test/suspendue']);
        $this->act($suspended, 'suspend')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->act($suspended, 'suspend', ['note' => 'Motif interne fictif de suspension'])->assertOk();
        $archived = $this->published(['title' => 'Fiche fictive archivée', 'source_url' => 'https://example.test/archivee']);
        $this->act($archived, 'archive')->assertOk();

        $this->assertSame([$published->id], $this->listed());
        // « Toutes » ajoute les fiches closes et archivées après publication ; jamais un brouillon, une fiche à vérifier ou une fiche suspendue.
        $this->assertEqualsCanonicalizing([$published->id, $archived->id], $this->listed('?cloture=toutes'));
        $this->assertSame([$archived->id], $this->listed('?cloture=closes'));
        foreach ([null, 'apprenant-double', 'admin-a', 'plateforme', 'enseignant-a'] as $name) {
            $this->page($published, $name)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.availability', 'published'));
            foreach ([$draft, $review] as $hidden) {
                $this->page($hidden, $name)->assertNotFound();
            }
            // Fiche suspendue (lot 16) : identifiée comme telle, réduite à son titre ; ni source, ni lien, ni contenu, ni motif interne.
            $this->page($suspended, $name)->assertOk()->assertDontSee('example.test/suspendue')->assertDontSee('Motif interne')->assertInertia(fn (Assert $page) => $page
                ->where('opportunity.availability', 'suspended')->where('opportunity.title', 'Fiche fictive suspendue')->where('opportunity.sourceUrl', null)->where('opportunity.applicationUrl', null)
                ->where('opportunity.conditions', null)->where('opportunity.deadline', null)->where('canReport', false)->where('match', null));
            // Fiche archivée après publication : lisible pour mémoire, identifiée, sans lien de candidature.
            $this->page($archived, $name)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.availability', 'archived')->where('opportunity.applicationUrl', null)->where('canReport', false));
            ($name ? $this->as($name) : $this->freshDevice())->get('/opportunites')->assertOk()->assertDontSee('confidentiel')->assertDontSee('à vérifier')->assertDontSee('suspendue')->assertDontSee('Motif interne');
        }
        // L'éditeur lui-même ne voit pas un brouillon par l'adresse publique : il passe par l'administration.
        $this->editor()->get('/opportunites/'.$draft->id)->assertNotFound();
        $this->editor()->get('/administration/catalogue/'.$draft->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/CatalogueEdit')->where('opportunity.status', 'draft')->has('revisions', 1));
        $this->editor()->get('/administration/catalogue')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/Catalogue')->has('opportunities', 5));
        $this->editor()->get('/administration/catalogue?statut=suspended')->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.id', $suspended->id));

        // Administration : visiteur renvoyé à la connexion ; tout autre rôle refusé ; l'éditeur hors de son contexte ou sans second facteur aussi.
        $admin = '/administration/catalogue';
        $this->freshDevice()->get($admin)->assertRedirect('/connexion');
        $this->freshDevice()->post($admin, $this->payload())->assertRedirect('/connexion');
        $platform = $this->user('plateforme')->memberships()->firstOrFail()->id;
        $actors = ['apprenant' => fn () => $this->as('apprenant-double', 'A'), 'admin' => fn () => $this->as('admin-a', 'A'), 'enseignant' => fn () => $this->as('enseignant-a', 'A'),
            'plateforme' => fn () => $this->freshDevice()->actingAs($this->user('plateforme'))->withSession([ActiveContext::SESSION_KEY => $platform]), 'éditeur hors contexte' => fn () => $this->as('editeur')];
        foreach ($actors as $label => $actor) {
            $actor()->get($admin)->assertForbidden();
            $actor()->get($admin.'/'.$draft->id)->assertForbidden();
            $actor()->get($admin.'/signalements')->assertForbidden();
            $actor()->postJson($admin, $this->payload(['title' => 'Intrusion fictive '.$label]))->assertForbidden();
            $actor()->patchJson($admin.'/'.$draft->id, ['expected_version' => 1, ...$this->payload(['title' => 'Écrasement fictif'])])->assertForbidden();
            $actor()->postJson($admin.'/'.$review->id.'/transition', ['action' => 'verify', 'expected_version' => 2, 'attest' => true])->assertForbidden();
            $actor()->postJson($admin.'/'.$published->id.'/edition', ['campaign' => '2099'])->assertForbidden();
        }
        config(['campus.identity.privileged_without_mfa' => false]);
        $this->editor()->get($admin)->assertForbidden();
        $this->editor()->postJson($admin, $this->payload(['title' => 'Sans second facteur fictif']))->assertForbidden();
        $this->page($published)->assertOk();
        config(['campus.identity.privileged_without_mfa' => true]);
        $this->assertSame([5, 'Brouillon fictif confidentiel', 'review'], [Opportunity::count(), $draft->fresh()->title, $review->fresh()->status]);

        // Reprise après suspension : nouvel acte de vérification exigé ; une fiche archivée ne se modifie plus.
        $this->act($suspended, 'resume')->assertStatus(422)->assertJsonValidationErrors('attest');
        $this->act($suspended, 'resume', ['attest' => true])->assertOk()->assertJson(['status' => 'published']);
        $this->page($suspended)->assertOk();
        $this->save($archived, ['summary' => 'Modification fictive'])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->act($archived, 'submit')->assertStatus(422);
        // Chaque changement d'état public a émis un seul événement.
        $this->assertSame([1, 1, 1], [DomainEvent::where('type', 'opportunity.suspended')->count(), DomainEvent::where('type', 'opportunity.resumed')->count(), DomainEvent::where('type', 'opportunity.archived')->count()]);
    }

    public function test_r34_a_date_without_time_a_rolling_deadline_and_an_unknown_date_keep_their_original_precision(): void
    {
        // Une date et heure exige son fuseau ; aucune valeur par défaut, pas même Abidjan.
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59']))->assertStatus(422)->assertJsonValidationErrors('deadline_timezone');
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'instant', 'deadline_timezone' => 'Europe/Paris']))->assertStatus(422)->assertJsonValidationErrors('deadline_local');
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Lune/Base']))->assertStatus(422);
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'date']))->assertStatus(422)->assertJsonValidationErrors('deadline_date');
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'date', 'deadline_date' => '10/12/2026']))->assertStatus(422);
        $this->editor()->postJson('/administration/catalogue', $this->payload(['deadline_kind' => 'bientot']))->assertStatus(422);

        $dated = $this->published(['title' => 'Concours fictif à date seule', 'source_url' => 'https://example.test/date', 'deadline_kind' => 'date', 'deadline_date' => '2026-12-10',
            // Une heure envoyée avec une date seule est ignorée : aucune heure n'est inventée ni conservée.
            'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Africa/Abidjan']);
        $rolling = $this->published(['title' => 'Stage fictif à clôture continue', 'source_url' => 'https://example.test/continu', 'deadline_kind' => 'rolling']);
        $unknown = $this->published(['title' => 'Formation fictive sans date', 'source_url' => 'https://example.test/sans-date', 'deadline_kind' => 'unknown']);
        $this->assertSame(['date', '2026-12-10', null, null, '2026-12-09 10:00:00', '2026-12-11 12:00:00'], [$dated->deadline_kind, $dated->deadline_date->toDateString(), $dated->deadline_at, $dated->deadline_timezone,
            $dated->last_day_starts_at->format('Y-m-d H:i:s'), $dated->closes_at->format('Y-m-d H:i:s')]);
        $this->assertSame([['rolling', null, null], ['unknown', null, null]], [[$rolling->deadline_kind, $rolling->deadline_date, $rolling->closes_at], [$unknown->deadline_kind, $unknown->deadline_date, $unknown->closes_at]]);

        $deadline = fn (Opportunity $opportunity) => $this->page($opportunity)->assertOk()->viewData('page')['props']['opportunity']['deadline'];
        $shown = $deadline($dated);
        $this->assertSame(['date', '10/12/2026 (heure non communiquée)', '2026-12-10', null, null, 'open'], [$shown['kind'], $shown['text'], $shown['date'], $shown['instantUtc'], $shown['timezone'], $shown['state']]);
        $this->assertStringContainsString('ne présume aucune heure de clôture', $shown['rule']);
        $this->assertStringContainsString('pas une heure limite garantie', $shown['rule']);
        $this->assertSame(['rolling', 'Clôture continue : pas de date limite annoncée', null, null, 'rolling'], [$deadline($rolling)['kind'], $deadline($rolling)['text'], $deadline($rolling)['rule'], $deadline($rolling)['closesAt'], $deadline($rolling)['state']]);
        $this->assertSame(['unknown', 'Date limite non communiquée', 'unknown'], [$deadline($unknown)['kind'], $deadline($unknown)['text'], $deadline($unknown)['state']]);
        // La précision d'origine revient telle quelle dans le formulaire de l'éditeur.
        $this->editor()->get('/administration/catalogue/'.$dated->id)->assertInertia(fn (Assert $page) => $page->where('opportunity.deadline.kind', 'date')->where('opportunity.deadline.date', '2026-12-10')->where('opportunity.deadline.localInput', null));

        // Règle technique affichée : « dernier jour possible » dès que ce jour commence quelque part (UTC+14), close quand il finit partout (UTC−12).
        foreach (['2026-12-09 09:59' => 'open', '2026-12-09 10:00' => 'last_day', '2026-12-11 11:59' => 'last_day', '2026-12-11 12:00' => 'closed'] as $time => $state) {
            $this->at($time);
            $this->assertSame($state, $deadline($dated)['state'], $time);
        }
        // Listes ouvertes : la fiche datée en sort à sa clôture technique ; les fiches sans date connue restent, et ne sont jamais archivées d'office.
        $this->at('2026-12-11 11:59');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('0 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame([$dated->id, $unknown->id, $rolling->id], $this->listed());
        $this->at('2026-12-11 12:00');
        $this->assertEqualsCanonicalizing([$rolling->id, $unknown->id], $this->listed());
        $this->assertContains($dated->id, $this->listed('?cloture=toutes'));
        $this->assertSame([$dated->id], $this->listed('?cloture=closes'));
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $this->at('2030-01-01 00:00');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('0 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame(['archived', 'published', 'published'], [$dated->fresh()->status, $rolling->fresh()->status, $unknown->fresh()->status]);
        $archive = OpportunityRevision::where('opportunity_id', $dated->id)->orderByDesc('version')->first();
        $this->assertSame(['auto_archived', null], [$archive->action, $archive->actor_id]);
        foreach ([['deadline_kind' => 'instant'], ['deadline_kind' => 'date'], ['deadline_kind' => 'rolling', 'deadline_date' => '2026-12-10']] as $invalid) {
            try {
                DB::table('opportunities')->where('id', $rolling->id)->update($invalid);
                $this->fail('MySQL a accepté '.json_encode($invalid));
            } catch (QueryException $error) {
                $this->assertSame(3819, (int) $error->errorInfo[1]);
            }
        }
    }

    public function test_r35_a_deadline_in_another_timezone_is_converted_consistently_and_archived_at_the_right_instant(): void
    {
        // 10 décembre 23 h 59 à Paris (hiver, UTC+1) = 22 h 59 UTC. À New York (UTC−5) = 4 h 59 UTC le lendemain. À Abidjan = 23 h 59 UTC.
        $paris = $this->published(['title' => 'Bourse fictive heure de Paris', 'source_url' => 'https://example.test/paris', 'deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $newYork = $this->published(['title' => 'Bourse fictive heure de New York', 'source_url' => 'https://example.test/new-york', 'deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'America/New_York']);
        $abidjan = $this->published(['title' => 'Bourse fictive heure d’Abidjan', 'source_url' => 'https://example.test/abidjan', 'deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Africa/Abidjan']);
        // Heure d'été : 30 juin 23 h 59 à Paris (UTC+2) = 21 h 59 UTC.
        $summer = $this->published(['title' => 'Bourse fictive heure d’été', 'source_url' => 'https://example.test/ete', 'deadline_kind' => 'instant', 'deadline_local' => '2027-06-30T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $this->assertSame(['2026-12-10 22:59:00', '2026-12-11 04:59:00', '2026-12-10 23:59:00', '2027-06-30 21:59:00'],
            [$paris->deadline_at->format('Y-m-d H:i:s'), $newYork->deadline_at->format('Y-m-d H:i:s'), $abidjan->deadline_at->format('Y-m-d H:i:s'), $summer->deadline_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['Europe/Paris', null, null, '2026-12-10 22:59:00'], [$paris->deadline_timezone, $paris->deadline_date, $paris->last_day_starts_at, $paris->closes_at->format('Y-m-d H:i:s')]);

        $shown = $this->page($paris)->viewData('page')['props']['opportunity']['deadline'];
        // L'heure affichée reste celle du fuseau de l'organisateur ; l'instant exact est fourni pour la conversion côté lecteur.
        $this->assertSame(['instant', '10/12/2026 à 23:59 (Europe/Paris)', '2026-12-10T22:59:00+00:00', 'Europe/Paris', null, null, 'open'],
            [$shown['kind'], $shown['text'], $shown['instantUtc'], $shown['timezone'], $shown['date'], $shown['rule'], $shown['state']]);
        $this->assertSame('10/12/2026 à 23:59 (America/New_York)', $this->page($newYork)->viewData('page')['props']['opportunity']['deadline']['text']);
        $this->editor()->get('/administration/catalogue/'.$paris->id)->assertInertia(fn (Assert $page) => $page->where('opportunity.deadline.localInput', '2026-12-10T23:59')->where('opportunity.deadline.timezone', 'Europe/Paris'));
        // Tri de la liste ouverte : par instant réel de clôture, pas par heure affichée.
        $this->assertSame([$paris->id, $abidjan->id, $newYork->id, $summer->id], $this->listed());

        // Une minute avant l'instant de Paris : ouverte, non archivée. À l'instant : close et archivée ; les autres fuseaux suivent chacun le leur.
        $this->at('2026-12-10 22:58');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('0 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame('open', $this->page($paris)->viewData('page')['props']['opportunity']['deadline']['state']);
        $this->at('2026-12-10 22:59');
        $this->assertSame('closed', $this->page($paris)->viewData('page')['props']['opportunity']['deadline']['state']);
        $this->assertSame([$abidjan->id, $newYork->id, $summer->id], $this->listed());
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame(['archived', 'published', 'published'], [$paris->fresh()->status, $abidjan->fresh()->status, $newYork->fresh()->status]);
        // Archivée à sa clôture : elle reste consultable par son adresse, identifiée comme close.
        $this->page($paris)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.availability', 'archived')->where('opportunity.deadline.state', 'closed'));
        $this->at('2026-12-11 00:00');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame(['archived', 'published'], [$abidjan->fresh()->status, $newYork->fresh()->status]);
        $this->at('2026-12-11 04:59');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame(['archived', 'published', 3], [$newYork->fresh()->status, $summer->fresh()->status, DomainEvent::where('type', 'opportunity.archived')->count()]);
    }

    public function test_r36_a_new_edition_is_a_separate_campaign_and_duplicates_are_refused(): void
    {
        $first = $this->published(['deadline_kind' => 'date', 'deadline_date' => '2026-12-10']);
        $history = OpportunityRevision::where('opportunity_id', $first->id)->count();

        // Doublons : même titre (normalisé), même organisateur, même campagne — toujours refusé, quelle que soit la casse ou les accents.
        $this->editor()->postJson('/administration/catalogue', $this->payload(['source_url' => 'https://example.test/autre']))->assertStatus(422)->assertJsonValidationErrors('title');
        $this->editor()->postJson('/administration/catalogue', $this->payload(['title' => '  BOURSE  fictive de Mobilite ', 'organizer' => 'organisme FICTIF', 'source_url' => 'https://example.test/autre']))->assertStatus(422)->assertJsonValidationErrors('title');
        // Même source et même campagne sous un autre titre : refusé sauf confirmation explicite de l'éditeur.
        foreach (['https://example.test/bourse-fictive', 'https://www.EXAMPLE.test/bourse-fictive/'] as $source) {
            $this->editor()->postJson('/administration/catalogue', $this->payload(['title' => 'Autre bourse fictive', 'source_url' => $source]))->assertStatus(422)->assertJsonValidationErrors('source_url');
        }
        $this->editor()->postJson('/administration/catalogue', $this->payload(['title' => 'Autre bourse fictive', 'confirm_same_source' => true]))->assertOk();
        try {
            DB::table('opportunities')->where('title', 'Autre bourse fictive')->update(['normalized_title' => $first->normalized_title]);
            $this->fail('MySQL a accepté un doublon.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }

        // Nouvelle édition : fiche distincte en brouillon, sans date ni vérification recopiées ; l'ancienne et son historique restent intacts.
        $this->editor()->postJson('/administration/catalogue/'.$first->id.'/edition', ['campaign' => '2026'])->assertStatus(422)->assertJsonValidationErrors('campaign');
        $response = $this->editor()->postJson('/administration/catalogue/'.$first->id.'/edition', ['campaign' => '2027'])->assertOk()->assertJson(['status' => 'draft', 'version' => 1]);
        $edition = Opportunity::findOrFail($response->json('id'));
        $this->assertNotSame($first->id, $edition->id);
        $this->assertSame(['2027', $first->id, 'draft', 'unknown', null, null, null, $first->title, $first->source_url, self::CONDITIONS], [$edition->campaign, $edition->previous_edition_id, $edition->status, $edition->deadline_kind,
            $edition->deadline_date, $edition->verified_at, $edition->published_at, $edition->title, $edition->source_url, $edition->conditions]);
        $this->editor()->postJson('/administration/catalogue/'.$first->id.'/edition', ['campaign' => '2027'])->assertStatus(422)->assertJsonValidationErrors('title');
        $first->refresh();
        $this->assertSame(['published', '2026', '2026-12-10', $history], [$first->status, $first->campaign, $first->deadline_date->toDateString(), OpportunityRevision::where('opportunity_id', $first->id)->count()]);
        $this->assertSame(['created', 'Nouvelle édition de la campagne 2026'], array_values(OpportunityRevision::where('opportunity_id', $edition->id)->sole()->only(['action', 'note'])));
        $this->editor()->get('/administration/catalogue/'.$first->id)->assertInertia(fn (Assert $page) => $page->where('opportunity.nextEditions.0.id', $edition->id)->where('opportunity.nextEditions.0.campaign', '2027'));
        $this->editor()->get('/administration/catalogue/'.$edition->id)->assertInertia(fn (Assert $page) => $page->where('opportunity.previousEditionId', $first->id));

        // Le public ne voit que l'édition publiée ; la nouvelle n'apparaît qu'après sa propre vérification, et les deux coexistent.
        $this->assertSame([$first->id], $this->listed());
        $this->page($edition)->assertNotFound();
        $this->act($edition, 'submit')->assertOk();
        $this->save($edition, ['deadline_kind' => 'date', 'deadline_date' => '2027-12-10'])->assertOk();
        $this->act($edition, 'verify', ['attest' => true])->assertOk();
        $this->assertSame([$first->id, $edition->id], $this->listed());
        $this->assertSame(['2026', '2027'], [$this->page($first)->viewData('page')['props']['opportunity']['campaign'], $this->page($edition)->viewData('page')['props']['opportunity']['campaign']]);
    }

    public function test_r37_an_extended_deadline_keeps_the_old_date_and_invalidates_scheduled_reminders_once(): void
    {
        $opportunity = $this->published(['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        // Rappel d'échéance déjà programmé pour un détenteur de favori (lot 18), calé sur l'ancienne date.
        app(NotificationStore::class)->create($this->user('apprenant-double')->id, 'opportunity', 'opportunity.deadline', 'Une opportunité suivie arrive à échéance', null, '/opportunites/'.$opportunity->id,
            'essai-fictif-echeance', CarbonImmutable::parse('2026-12-09 22:59', 'UTC'), CarbonImmutable::parse('2026-12-10 22:59', 'UTC'), $opportunity->id, CarbonImmutable::parse('2026-12-10 22:59', 'UTC'));
        $reminder = UserNotification::where('dedup_key', 'essai-fictif-echeance')->sole();
        $change = ['deadline_kind' => 'instant', 'deadline_local' => '2027-01-15T18:00', 'deadline_timezone' => 'Europe/Paris'];

        // Modifier une fiche publiée exige un nouvel acte de vérification.
        $this->save($opportunity, $change)->assertStatus(422)->assertJsonValidationErrors('attest');
        $this->assertSame(['2026-12-10 22:59:00', 'scheduled'], [$opportunity->fresh()->deadline_at->format('Y-m-d H:i:s'), $reminder->fresh()->status]);
        $this->at('2026-11-20 10:00');
        $this->save($opportunity, $change, ['attest' => true])->assertOk()->assertJson(['version' => 4, 'status' => 'published']);
        $opportunity->refresh();
        $this->assertSame(['2027-01-15 17:00:00', '2027-01-15 17:00:00', '2026-11-20 10:00:00'], [$opportunity->deadline_at->format('Y-m-d H:i:s'), $opportunity->closes_at->format('Y-m-d H:i:s'), $opportunity->verified_at->format('Y-m-d H:i:s')]);
        // Ancienne et nouvelle dates conservées et affichées.
        $this->assertSame(['instant', '10/12/2026 à 23:59 (Europe/Paris)', '2026-11-20T10:00:00+00:00'], [$opportunity->previous_deadline['kind'], $opportunity->previous_deadline['text'], $opportunity->previous_deadline['changedAt']]);
        $shown = $this->page($opportunity)->viewData('page')['props']['opportunity']['deadline'];
        $this->assertSame(['15/01/2027 à 18:00 (Europe/Paris)', '10/12/2026 à 23:59 (Europe/Paris)'], [$shown['text'], $shown['previous']['text']]);
        $revision = OpportunityRevision::where('opportunity_id', $opportunity->id)->orderByDesc('version')->first();
        $this->assertSame(['deadline_changed', 'Ancienne date : 10/12/2026 à 23:59 (Europe/Paris)', '2027-01-15 17:00:00'], [$revision->action, $revision->note, $revision->snapshot['deadline_at']]);
        $this->assertSame('2026-12-10 22:59:00', OpportunityRevision::where('opportunity_id', $opportunity->id)->where('version', 3)->sole()->snapshot['deadline_at']);
        // Le rappel calé sur l'ancienne date est invalidé ; un seul événement signale le changement.
        $this->assertSame(['cancelled', 'deadline_changed'], [$reminder->fresh()->status, $reminder->fresh()->status_reason]);
        $event = DomainEvent::where('type', 'opportunity.deadline_changed')->sole();
        $this->assertEquals(['opportunity_id' => $opportunity->id, 'version' => 4, 'extended' => true], $event->payload);
        $this->assertSame('opportunity-deadline_changed:'.$opportunity->id.':v4', $event->dedup_key);

        // Rejeu de la même modification (double clic, synchronisation) : ni nouvelle révision, ni second événement.
        $this->editor()->patchJson('/administration/catalogue/'.$opportunity->id, ['expected_version' => 3, 'attest' => true, ...$this->payload($change)])->assertOk()->assertJson(['version' => 4]);
        $this->save($opportunity, $change, ['attest' => true])->assertOk()->assertJson(['version' => 4]);
        $this->assertSame([1, 4], [DomainEvent::where('type', 'opportunity.deadline_changed')->count(), OpportunityRevision::where('opportunity_id', $opportunity->id)->count()]);
        // Version dépassée avec un autre contenu : conflit.
        $this->editor()->patchJson('/administration/catalogue/'.$opportunity->id, ['expected_version' => 3, 'attest' => true, ...$this->payload(['deadline_kind' => 'rolling'])])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        // Une modification sans changement de date ne touche ni l'ancienne date ni les rappels.
        $this->save($opportunity, ['summary' => 'Résumé fictif corrigé.'], ['attest' => true])->assertOk()->assertJson(['version' => 5]);
        $this->assertSame([1, '10/12/2026 à 23:59 (Europe/Paris)', 'updated'], [DomainEvent::where('type', 'opportunity.deadline_changed')->count(), $opportunity->fresh()->previous_deadline['text'],
            OpportunityRevision::where('opportunity_id', $opportunity->id)->orderByDesc('version')->first()->action]);
        // La fiche prolongée n'est pas archivée à l'ancienne date.
        $this->at('2026-12-10 23:30');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('0 fiche(s) archivée(s).')->assertSuccessful();
        $this->assertSame('published', $opportunity->fresh()->status);
    }

    public function test_r38_a_reported_record_goes_through_the_editorial_queue_before_any_suspension(): void
    {
        $this->secondEditor();
        $opportunity = $this->published();
        $other = $this->published(['title' => 'Stage fictif signalé à tort', 'source_url' => 'https://example.test/stage']);
        $draft = $this->draft(['title' => 'Brouillon fictif', 'source_url' => 'https://example.test/brouillon']);
        app(NotificationStore::class)->create($this->user('polyvalent')->id, 'opportunity', 'opportunity.deadline', 'Une opportunité suivie arrive à échéance', null, '/opportunites/'.$opportunity->id,
            'essai-fictif-suspension', now()->addDay()->toImmutable(), now()->addDays(2)->toImmutable(), $opportunity->id);
        $url = '/opportunites/'.$opportunity->id.'/signalement';

        // Signaler exige un compte connecté ; motif parmi la liste ; « autre » exige une précision ; taille bornée.
        $this->freshDevice()->post($url, ['reason' => 'authenticity'])->assertRedirect('/connexion');
        $this->as('apprenant-double')->post($url, ['reason' => 'arnaque'])->assertSessionHasErrors('reason');
        $this->as('apprenant-double')->post($url, ['reason' => 'other'])->assertSessionHasErrors('message');
        $this->as('apprenant-double')->post($url, ['reason' => 'authenticity', 'message' => str_repeat('x', 501)])->assertSessionHasErrors('message');
        $this->as('apprenant-double')->post('/opportunites/'.$draft->id.'/signalement', ['reason' => 'authenticity'])->assertNotFound();
        $this->as('apprenant-double')->post($url, ['reason' => 'authenticity', 'message' => 'Le site <b>fictif</b> demande un paiement avant candidature.'])->assertSessionHasNoErrors();
        // Second envoi par la même personne : un seul signalement ouvert.
        $this->as('apprenant-double')->post($url, ['reason' => 'broken_link'])->assertSessionHasNoErrors();
        $report = OpportunityReport::sole();
        $this->assertSame(['authenticity', 'Le site fictif demande un paiement avant candidature.', 'new', $this->user('apprenant-double')->id, null], [$report->reason, $report->message, $report->status, $report->reporter_id, $report->assignee_id]);
        $this->as('polyvalent')->post('/opportunites/'.$other->id.'/signalement', ['reason' => 'closed'])->assertSessionHasNoErrors();
        // Un signalement isolé ne dépublie rien.
        $this->page($opportunity)->assertOk();
        $this->assertSame(['published', 0], [$opportunity->fresh()->status, DomainEvent::where('type', 'opportunity.suspended')->count()]);
        // Le signalant voit seulement l'état de son signalement ; les autres visiteurs ne voient rien.
        $this->page($opportunity, 'apprenant-double')->assertInertia(fn (Assert $page) => $page->where('canReport', true)->where('myReport', ['status' => 'new', 'statusLabel' => 'Nouveau', 'decisionLabel' => null]));
        $this->page($opportunity, 'polyvalent')->assertInertia(fn (Assert $page) => $page->where('myReport', null));
        $this->page($opportunity)->assertDontSee('paiement avant candidature');

        // File éditoriale : réservée aux éditeurs ; l'identité du signalant n'y figure pas.
        $queue = '/administration/catalogue/signalements';
        foreach ([fn () => $this->as('apprenant-double', 'A'), fn () => $this->as('admin-a', 'A'), fn () => $this->as('editeur')] as $actor) {
            $actor()->get($queue)->assertForbidden();
            $actor()->postJson($queue.'/'.$report->id, ['action' => 'decide', 'expected_version' => 1, 'decision' => 'suspended'])->assertForbidden();
        }
        $page = $this->editor()->get($queue)->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/CatalogueReports')->has('reports', 2)
            ->where('reports.0.reason', 'Doute sur l’authenticité')->where('reports.0.status', 'new')->where('reports.0.assignee', null)->where('reports.0.mine', false)
            ->where('reports.0.history.0.actor', 'Signalant')->missing('reports.0.reporter_id')->missing('reports.0.reporter'));
        foreach (['Apprenant fictif A et B', 'apprenant-double@example.test', '"reporter'] as $identity) {
            $this->assertStringNotContainsString($identity, $page->getContent());
        }
        $this->editor()->get('/administration/catalogue')->assertInertia(fn (Assert $page) => $page->where('openReports', 2));

        // Traitement complet : prise en charge (responsable), commentaire interne, décision, historique.
        $handle = fn (string $editor, array $data) => $this->editor($editor)->postJson($queue.'/'.$report->id, ['expected_version' => $report->fresh()->lock_version, ...$data]);
        $handle('editeur', ['action' => 'comment'])->assertStatus(422)->assertJsonValidationErrors('comment');
        $handle('editeur', ['action' => 'decide'])->assertStatus(422)->assertJsonValidationErrors('decision');
        $handle('editeur', ['action' => 'decide', 'decision' => 'supprimer'])->assertStatus(422);
        $handle('editeur', ['action' => 'assign'])->assertOk()->assertJson(['status' => 'in_progress', 'version' => 2]);
        $this->editor()->postJson($queue.'/'.$report->id, ['action' => 'assign', 'expected_version' => 1])->assertStatus(409);
        $handle('editeur', ['action' => 'comment', 'comment' => 'Commentaire interne fictif : source officielle introuvable ce jour.'])->assertOk()->assertJson(['version' => 3]);
        $this->assertSame(['in_progress', $this->user('editeur')->id, 'published'], [$report->fresh()->status, $report->fresh()->assignee_id, $opportunity->fresh()->status]);
        $handle('independant', ['action' => 'decide', 'decision' => 'suspended', 'comment' => 'Décision interne fictive : suspension par précaution.'])->assertOk()->assertJson(['status' => 'resolved', 'decision' => 'suspended']);
        $handle('editeur', ['action' => 'decide', 'decision' => 'no_action'])->assertStatus(422)->assertJsonValidationErrors('reference');

        // Suspension : la fiche quitte le catalogue ouvert, ses rappels sont invalidés, un événement informe les détenteurs de favoris.
        $opportunity->refresh();
        $this->assertSame(['suspended', '2026-11-16 08:00:00'], [$opportunity->status, $opportunity->suspended_at->format('Y-m-d H:i:s')]);
        $this->page($opportunity)->assertOk()->assertInertia(fn (Assert $page) => $page->where('opportunity.availability', 'suspended')->where('opportunity.sourceUrl', null)->where('opportunity.conditions', null));
        // Le signalant retrouve l'issue de son signalement sur la fiche suspendue, sans commentaire interne.
        $this->page($opportunity, 'apprenant-double')->assertOk()->assertDontSee('Décision interne')->assertInertia(fn (Assert $page) => $page->where('canReport', false)
            ->where('myReport', ['status' => 'resolved', 'statusLabel' => 'Traité', 'decisionLabel' => 'Fiche suspendue']));
        $this->assertSame([$other->id], $this->listed('?cloture=toutes'));
        $this->assertSame(['cancelled', 'suspended'], array_values(UserNotification::where('dedup_key', 'essai-fictif-suspension')->sole()->only(['status', 'status_reason'])));
        $event = DomainEvent::where('type', 'opportunity.suspended')->sole();
        $this->assertEquals(['opportunity_id' => $opportunity->id, 'version' => $opportunity->lock_version], $event->payload);
        $suspension = OpportunityRevision::where('opportunity_id', $opportunity->id)->orderByDesc('version')->first();
        $this->assertSame(['suspended', $this->user('independant')->id, 'Suspension décidée après signalement n° '.$report->id], [$suspension->action, $suspension->actor_id, $suspension->note]);

        // Preuve minimale du traitement : historique complet et non modifiable, journal sans motif ni commentaire.
        $report->refresh();
        $this->assertSame(['resolved', 'suspended', $this->user('independant')->id, $this->user('editeur')->id, null], [$report->status, $report->decision, $report->decided_by, $report->assignee_id, $report->open_slot]);
        $events = OpportunityReportEvent::where('opportunity_report_id', $report->id)->orderBy('id')->get();
        $this->assertSame(['created', 'assigned', 'commented', 'decided'], $events->pluck('action')->all());
        $this->assertSame(['new', 'in_progress', 'in_progress', 'resolved'], $events->pluck('to_status')->all());
        $this->assertSame([$this->user('apprenant-double')->id, $this->user('editeur')->id, $this->user('editeur')->id, $this->user('independant')->id], $events->pluck('actor_id')->all());
        try {
            $events->last()->delete();
            $this->fail('Une étape de traitement a été supprimée.');
        } catch (LogicException) {
            $this->assertSame(4, OpportunityReportEvent::where('opportunity_report_id', $report->id)->count());
        }
        $audit = AuditLog::where('action', 'opportunity.report.decided')->sole();
        $this->assertEquals(['opportunity_id' => $opportunity->id, 'decision' => 'suspended'], $audit->metadata);
        $this->assertStringNotContainsString('interne', json_encode(AuditLog::all()->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('paiement', json_encode([AuditLog::all()->toArray(), DomainEvent::all()->toArray()], JSON_UNESCAPED_UNICODE));
        $this->editor()->get($queue.'?statut=resolved')->assertInertia(fn (Assert $page) => $page->has('reports', 1)->where('reports.0.decision', 'Fiche suspendue')->where('reports.0.assignee', 'Éditeur fictif catalogue')
            ->where('reports.0.history.2.comment', 'Commentaire interne fictif : source officielle introuvable ce jour.')->has('reports.0.history', 4));

        // Séparation signalant / éditeur : un éditeur ne traite pas son propre signalement ; un collègue le peut.
        $this->editor('independant')->post('/opportunites/'.$other->id.'/signalement', ['reason' => 'wrong_date'])->assertSessionHasNoErrors();
        $own = OpportunityReport::where('reporter_id', $this->user('independant')->id)->sole();
        foreach ([['action' => 'assign'], ['action' => 'comment', 'comment' => 'Commentaire fictif'], ['action' => 'decide', 'decision' => 'archived']] as $data) {
            $this->editor('independant')->postJson($queue.'/'.$own->id, ['expected_version' => 1, ...$data])->assertForbidden();
        }
        $this->assertSame(['new', 'published'], [$own->fresh()->status, $other->fresh()->status]);
        $this->editor('independant')->get($queue)->assertInertia(fn (Assert $page) => $page->where('reports', fn ($reports) => collect($reports)->firstWhere('id', $own->id)['mine'] === true));

        // Classement sans suite et correction : la fiche reste publiée ; le signalant voit l'issue, jamais le commentaire interne ni le nom de l'éditeur.
        $wrong = OpportunityReport::where('reporter_id', $this->user('polyvalent')->id)->sole();
        $this->editor()->postJson($queue.'/'.$wrong->id, ['action' => 'decide', 'expected_version' => 1, 'decision' => 'no_action', 'comment' => 'Commentaire interne fictif : offre toujours ouverte.'])->assertOk()->assertJson(['status' => 'rejected']);
        $this->editor()->postJson($queue.'/'.$own->id, ['action' => 'decide', 'expected_version' => 1, 'decision' => 'corrected', 'comment' => 'Commentaire interne fictif : date corrigée.'])->assertOk()->assertJson(['status' => 'resolved']);
        $this->assertSame('published', $other->fresh()->status);
        $seen = $this->page($other, 'polyvalent')->assertOk()->assertInertia(fn (Assert $page) => $page->where('myReport', ['status' => 'rejected', 'statusLabel' => 'Classé sans suite', 'decisionLabel' => 'Aucune action nécessaire']));
        foreach (['Commentaire interne', 'offre toujours ouverte', 'Éditeur fictif catalogue', 'editeur@example.test'] as $internal) {
            $this->assertStringNotContainsString($internal, $seen->getContent());
        }
        // Après traitement, la même personne peut signaler de nouveau ; au-delà de cinq signalements par jour, refus.
        $this->as('polyvalent')->post('/opportunites/'.$other->id.'/signalement', ['reason' => 'broken_link'])->assertSessionHasNoErrors();
        $this->assertSame(2, OpportunityReport::where('reporter_id', $this->user('polyvalent')->id)->count());
        foreach (range(1, 3) as $index) {
            $extra = $this->published(['title' => 'Fiche fictive n° '.$index, 'source_url' => 'https://example.test/fiche-'.$index]);
            $this->as('polyvalent')->post('/opportunites/'.$extra->id.'/signalement', ['reason' => 'closed'])->assertSessionHasNoErrors();
        }
        $sixth = $this->published(['title' => 'Fiche fictive de trop', 'source_url' => 'https://example.test/fiche-de-trop']);
        $this->as('polyvalent')->postJson('/opportunites/'.$sixth->id.'/signalement', ['reason' => 'closed'])->assertStatus(429)->assertJson(['code' => 'RATE_LIMITED']);
        $this->assertSame(5, OpportunityReport::where('reporter_id', $this->user('polyvalent')->id)->count());
        foreach ([['status' => 'new', 'open_slot' => null], ['status' => 'resolved', 'open_slot' => null, 'decision' => null]] as $invalid) {
            try {
                DB::table('opportunity_reports')->where('id', $own->id)->update($invalid);
                $this->fail('MySQL a accepté '.json_encode($invalid));
            } catch (QueryException $error) {
                $this->assertSame(3819, (int) $error->errorInfo[1]);
            }
        }
    }

    public function test_c15_01_development_fixtures_are_explicitly_fictitious(): void
    {
        $this->seed(OpportunityDemoSeeder::class);
        $this->seed(OpportunityDemoSeeder::class);
        $all = Opportunity::all();
        $this->assertSame(4, $all->count());
        foreach ($all as $opportunity) {
            $this->assertStringStartsWith('[FICTIF] ', $opportunity->title);
            $this->assertSame(['example.test', 'Organisme fictif de démonstration', 'Démonstration'], [parse_url($opportunity->source_url, PHP_URL_HOST), $opportunity->organizer, $opportunity->campaign]);
            $this->assertStringContainsString('n’existe pas', $opportunity->summary);
        }
        // Trois fiches publiées, un brouillon invisible du public.
        $this->freshDevice()->get('/opportunites')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Opportunities')->has('opportunities', 3)->where('filters.cloture', 'ouvertes')->where('pagination.count', 3)
            ->where('opportunities', fn ($list) => collect($list)->every(fn ($item) => str_starts_with($item['title'], '[FICTIF] ') && ! str_contains($item['title'], 'brouillon'))));
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.navigation.3.href', '/opportunites')->where('campus.navigation.3.pending', false));
    }
}
