<?php

namespace Tests\Feature;

use App\Jobs\RunNotificationCycle;
use App\Models\DomainEvent;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Opportunity;
use App\Models\OpportunityFavorite;
use App\Models\SavedSearch;
use App\Models\SavedSearchMatch;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\CampusReminder;
use App\Services\ActiveContext;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPlanner;
use Carbon\CarbonImmutable;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OpportunityAlertTest extends TestCase
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
        Notification::fake();
        Http::preventStrayRequests();
        DB::table('notification_cursors')->delete();
        DomainEvent::query()->update(['processed_at' => now()]);
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function as(string $name): static
    {
        return $this->freshDevice()->actingAs($this->user($name));
    }

    private function editor(): static
    {
        $membership = $this->user('editeur')->memberships()->where('scope', 'platform')->where('role', 'catalogue_editor')->firstOrFail();

        return $this->freshDevice()->actingAs($this->user('editeur'))->withSession([ActiveContext::SESSION_KEY => $membership->id]);
    }

    private function payload(string $key, array $overrides = []): array
    {
        return array_replace(['category' => 'scholarship', 'title' => 'Bourse fictive de physique '.$key, 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/'.$key,
            'application_url' => null, 'summary' => null, 'conditions' => self::CONDITIONS, 'countries' => ['FR'], 'modality' => 'unspecified', 'levels' => [], 'fields' => [], 'languages' => [],
            'funding' => 'unspecified', 'fees' => 'unspecified', 'fees_detail' => null, 'deadline_kind' => 'unknown', 'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null], $overrides);
    }

    private function record(string $key, array $overrides = [], string $status = 'published'): Opportunity
    {
        $id = $this->editor()->postJson('/administration/catalogue', $this->payload($key, $overrides))->assertOk()->json('id');
        if ($status !== 'draft') {
            $this->transition($id, 'submit');
            $this->transition($id, 'verify', ['attest' => true]);
        }
        if ($status === 'suspended') {
            $this->transition($id, 'suspend', ['note' => 'Motif interne fictif']);
        }

        return Opportunity::findOrFail($id);
    }

    private function transition(string $id, string $action, array $extra = []): void
    {
        $this->editor()->postJson('/administration/catalogue/'.$id.'/transition', ['action' => $action, 'expected_version' => Opportunity::findOrFail($id)->lock_version, ...$extra])->assertOk();
    }

    private function extend(Opportunity $opportunity, string $key, array $deadline): void
    {
        $this->editor()->patchJson('/administration/catalogue/'.$opportunity->id, ['expected_version' => $opportunity->fresh()->lock_version, 'attest' => true, ...$this->payload($key, $deadline)])->assertOk();
    }

    private function save(string $name, array $filters, array $extra = []): TestResponse
    {
        return $this->as($name)->postJson('/opportunites/alertes', ['frequency' => 'daily', 'email' => false, 'push' => false, 'filters' => $filters, ...$extra]);
    }

    private function cycle(): array
    {
        return (new RunNotificationCycle)->handle(app(NotificationPlanner::class), app(NotificationDispatcher::class));
    }

    private function notes(string $name, ?string $type = null): Collection
    {
        return UserNotification::where('user_id', $this->user($name)->id)->when($type, fn ($query) => $query->where('type', $type))->orderBy('id')->get();
    }

    private function preferences(string $name, array $attributes): void
    {
        NotificationPreference::updateOrCreate(['user_id' => $this->user($name)->id], [...$attributes, 'lock_version' => 1]);
    }

    private function announced(SavedSearch $search): array
    {
        return SavedSearchMatch::where('saved_search_id', $search->id)->orderBy('id')->get()->map(fn (SavedSearchMatch $match) => [Opportunity::find($match->opportunity_id)->title, $match->kind])->all();
    }

    public function test_r43_a_saved_search_announces_each_new_record_once_and_important_updates_as_updates(): void
    {
        $existing = $this->record('ancienne');
        // Enregistrement : filtres validés comme ceux du catalogue ; pas d'alerte sur tout le catalogue ; mêmes filtres = même recherche.
        $this->freshDevice()->post('/opportunites/alertes', ['frequency' => 'daily', 'email' => false, 'push' => false, 'filters' => ['q' => 'physique']])->assertRedirect('/connexion');
        $this->save('apprenant-double', [])->assertStatus(422);
        $this->save('apprenant-double', ['cloture' => 'toutes', 'tri' => 'pertinence'])->assertStatus(422)->assertJsonValidationErrors('filters');
        $this->save('apprenant-double', ['q' => '!!!'])->assertStatus(422)->assertJsonValidationErrors('filters');
        foreach ([['categorie' => 'loterie'], ['pays' => 'FRA'], ['niveau' => 'genie'], ['q' => str_repeat('a', 101)]] as $invalid) {
            $this->save('apprenant-double', $invalid)->assertStatus(422);
        }
        $this->save('apprenant-double', ['q' => 'physique'], ['frequency' => 'horaire'])->assertStatus(422);
        $this->assertSame(0, SavedSearch::count());
        $id = $this->save('apprenant-double', ['q' => 'Physique', 'categorie' => 'scholarship', 'pays' => 'fr', 'cloture' => 'toutes', 'page' => 3], ['name' => 'Bourses de physique <b>en France</b>'])->assertOk()->assertJson(['replayed' => false])->json('id');
        $this->save('apprenant-double', ['pays' => 'FR', 'categorie' => 'scholarship', 'q' => 'physique'])->assertOk()->assertJson(['id' => $id, 'replayed' => true]);
        $search = SavedSearch::sole();
        $this->assertEquals(['q' => 'physique', 'categorie' => 'scholarship', 'pays' => 'FR'], $search->filters);
        $this->assertSame(['Bourses de physique en France', 'daily', true, '2026-11-16 08:00:00', $this->user('apprenant-double')->id], [$search->name, $search->frequency, $search->active, $search->baseline_at->format('Y-m-d H:i:s'), $search->user_id]);
        $this->save('polyvalent', ['categorie' => 'internship'])->assertOk();

        // Les fiches déjà au catalogue ne sont pas annoncées : pas de rafale à l'enregistrement.
        $this->cycle();
        $this->assertSame([0, 0], [SavedSearchMatch::count(), UserNotification::where('type', 'search.digest')->count()]);

        // Une heure plus tard : une fiche correspondante est publiée, ainsi qu'une fiche hors critères, un brouillon et une fiche aussitôt suspendue.
        $this->at('2026-11-16 09:00');
        $new = $this->record('nouvelle', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $this->record('stage', ['category' => 'internship', 'title' => 'Stage fictif de chimie', 'countries' => ['CI']]);
        $this->record('brouillon', [], 'draft');
        $suspended = $this->record('suspendue', [], 'suspended');
        // La recherche quotidienne a déjà tourné aujourd'hui : rien avant le lendemain.
        $this->cycle();
        $this->assertSame(0, UserNotification::where('type', 'search.digest')->count());
        $this->at('2026-11-17 08:00');
        $this->cycle();
        // Une synthèse, une seule fiche : la nouvelle. Ni l'ancienne, ni le brouillon, ni la fiche suspendue.
        $digest = $this->notes('apprenant-double', 'search.digest')->sole();
        $this->assertSame([['Bourse fictive de physique nouvelle', 'new']], $this->announced($search));
        $this->assertSame(['delivered', 'opportunity', 'Du nouveau pour une de vos recherches enregistrées', '« Bourses de physique en France » : 1 nouvelle(s) fiche(s), 0 mise(s) à jour.', '/opportunites/alertes/'.$search->id],
            [$digest->status, $digest->category, $digest->title, $digest->body, $digest->url]);
        // L'autre compte a sa propre synthèse, pour sa propre recherche : le stage.
        $this->assertSame([['Stage fictif de chimie', 'new']], $this->announced(SavedSearch::where('user_id', $this->user('polyvalent')->id)->sole()));
        $this->assertSame(1, $this->notes('polyvalent', 'search.digest')->count());

        // R43 : la tâche planifiée rejouée, dans la minute ou le lendemain sans nouveauté, ne produit ni seconde annonce ni seconde synthèse.
        $this->cycle();
        $this->cycle();
        $this->at('2026-11-18 08:00');
        $this->cycle();
        $this->cycle();
        $this->assertSame([2, 2, '2026-11-18 08:00:00', '2026-11-17 08:00:00'], [SavedSearchMatch::count(), UserNotification::where('type', 'search.digest')->count(),
            $search->fresh()->last_run_at->format('Y-m-d H:i:s'), $search->fresh()->last_digest_at->format('Y-m-d H:i:s')]);
        foreach ([['saved_search_matches', ['saved_search_id' => $search->id, 'opportunity_id' => $new->id, 'revision_key' => 'new', 'kind' => 'new', 'digest_key' => 'x', 'created_at' => now()]],
            ['saved_searches', ['user_id' => $search->user_id, 'name' => 'x', 'filters' => '{}', 'filters_hash' => $search->filters_hash, 'baseline_at' => now()]]] as [$table, $row]) {
            try {
                DB::table($table)->insert($row);
                $this->fail('MySQL a accepté un doublon dans '.$table);
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }
        }

        // Mise à jour importante (date limite prolongée) et nouvelle fiche : une seule synthèse, qui distingue « nouvelle » et « mise à jour ».
        $this->extend($new, 'nouvelle', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-20T23:59', 'deadline_timezone' => 'Europe/Paris']);
        // Modification sans importance pour une alerte (résumé corrigé) sur l'ancienne fiche : non annoncée.
        $this->editor()->patchJson('/administration/catalogue/'.$existing->id, ['expected_version' => 3, 'attest' => true, ...$this->payload('ancienne', ['summary' => 'Résumé fictif corrigé.'])])->assertOk();
        $second = $this->record('seconde');
        $this->at('2026-11-19 08:00');
        $this->cycle();
        $this->cycle();
        $this->assertSame([['Bourse fictive de physique nouvelle', 'new'], ['Bourse fictive de physique nouvelle', 'updated'], ['Bourse fictive de physique seconde', 'new']], $this->announced($search));
        $digests = $this->notes('apprenant-double', 'search.digest');
        $this->assertSame([2, '« Bourses de physique en France » : 1 nouvelle(s) fiche(s), 1 mise(s) à jour.'], [$digests->count(), $digests->last()->body]);
        $this->assertSame('Date limite modifiée. Ancienne date : 10/12/2026 à 23:59 (Europe/Paris)', SavedSearchMatch::where('kind', 'updated')->sole()->detail);

        // Page de l'alerte : état, explication par les critères, lien vers la source ; une fiche suspendue depuis n'y est plus proposée.
        $this->transition($second->id, 'suspend', ['note' => 'Motif interne fictif']);
        $this->as('apprenant-double')->get('/opportunites/alertes/'.$search->id)->assertOk()->assertDontSee('Motif interne')->assertInertia(fn (Assert $page) => $page->component('OpportunityAlert')
            ->where('search.criteria', ['Mots : physique', 'Catégorie : Bourse', 'Pays : France (FR)'])->where('search.active', true)->has('announced', 3)
            ->where('announced.0.title', 'Bourse fictive de physique seconde')->where('announced.0.availability', 'suspended')->where('announced.0.sourceUrl', null)->where('announced.0.deadline', null)
            ->where('announced.1.kindLabel', 'Mise à jour')->where('announced.1.sourceUrl', 'https://example.test/nouvelle')->where('announced.1.sourceHost', 'example.test')->where('announced.1.deadline', '20/12/2026 à 23:59 (Europe/Paris)')
            ->where('announced.2.kindLabel', 'Nouvelle fiche'));
        $this->as('apprenant-double')->get('/opportunites/alertes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('OpportunityAlerts')->has('searches', 1)->where('searches.0.announced', 3)
            ->where('searches.0.lastDigestAt', '2026-11-19T08:00:00+00:00')->where('limits', ['searches' => 10, 'items' => 20]));
        // La fiche suspendue dès le départ n'a jamais été annoncée ; reprise après vérification, elle l'est une fois, comme nouvelle fiche.
        $this->assertNotContains('Bourse fictive de physique suspendue', array_column($this->announced($search), 0));
        $this->transition($suspended->id, 'resume', ['attest' => true]);
        $this->at('2026-11-20 08:00');
        $this->cycle();
        $this->cycle();
        $this->assertSame(['Bourse fictive de physique suspendue', 'new'], collect($this->announced($search))->last());
        $this->assertSame(3, $this->notes('apprenant-double', 'search.digest')->count());
    }

    public function test_r42_unsubscribing_stops_alerts_at_once_and_keeps_favourites(): void
    {
        $search = SavedSearch::findOrFail($this->save('apprenant-double', ['q' => 'physique'], ['name' => 'Physique fictive'])->assertOk()->json('id'));
        $weekly = SavedSearch::findOrFail($this->save('apprenant-double', ['categorie' => 'scholarship'], ['frequency' => 'weekly'])->assertOk()->json('id'));
        $this->at('2026-11-16 09:00');
        $first = $this->record('premiere', ['deadline_kind' => 'rolling']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$first->id.'/favori')->assertOk();
        $this->at('2026-11-17 08:00');
        // Synthèse programmée mais pas encore remise au moment du désabonnement.
        app(NotificationPlanner::class)->run();
        $pending = $this->notes('apprenant-double', 'search.digest')->first();
        $this->assertSame('scheduled', $pending->status);

        // Chaque route est réservée à l'auteur : un autre compte ne voit ni ne modifie la recherche.
        $patch = ['expected_version' => 1, 'name' => 'Physique fictive', 'frequency' => 'daily', 'email' => false, 'push' => false, 'active' => false];
        foreach (['polyvalent', 'admin-a', 'editeur', 'plateforme'] as $other) {
            $this->as($other)->get('/opportunites/alertes/'.$search->id)->assertNotFound();
            $this->as($other)->patchJson('/opportunites/alertes/'.$search->id, $patch)->assertNotFound();
            $this->as($other)->deleteJson('/opportunites/alertes/'.$search->id)->assertOk()->assertJson(['replayed' => true]);
            $this->as($other)->get('/opportunites/alertes')->assertOk()->assertDontSee('Physique fictive')->assertInertia(fn (Assert $page) => $page->where('searches', []));
        }
        foreach ([['get', '/opportunites/alertes'], ['get', '/opportunites/alertes/'.$search->id], ['patch', '/opportunites/alertes/'.$search->id], ['delete', '/opportunites/alertes/'.$search->id]] as [$method, $url]) {
            $this->freshDevice()->{$method}($url)->assertRedirect('/connexion');
        }
        $this->assertSame([2, true], [SavedSearch::count(), $search->fresh()->active]);

        // R42 : désabonnement. Effectif tout de suite : la synthèse en attente est retirée, rien ne sera remis ni envoyé.
        $this->as('apprenant-double')->patchJson('/opportunites/alertes/'.$search->id, [...$patch, 'expected_version' => 9])->assertStatus(409);
        $this->as('apprenant-double')->patchJson('/opportunites/alertes/'.$search->id, $patch)->assertOk()->assertJson(['active' => false, 'version' => 2]);
        $this->as('apprenant-double')->patchJson('/opportunites/alertes/'.$search->id, $patch)->assertOk()->assertJson(['active' => false, 'version' => 2]);
        $this->assertSame(['cancelled', 'unsubscribed'], [$pending->fresh()->status, $pending->fresh()->status_reason]);
        $this->cycle();
        $this->record('pendant-arret');
        $this->at('2026-11-18 08:00');
        $this->cycle();
        $this->at('2026-11-19 08:00');
        $this->cycle();
        // Aucune synthèse pour la recherche arrêtée ; l'état de l'alerte est visible sur sa page.
        $this->assertSame(0, UserNotification::where('user_id', $this->user('apprenant-double')->id)->where('type', 'search.digest')->where('url', '/opportunites/alertes/'.$search->id)->whereIn('status', ['scheduled', 'delivered'])->count());
        $this->assertSame(1, SavedSearchMatch::where('saved_search_id', $search->id)->count());
        $this->as('apprenant-double')->get('/opportunites/alertes/'.$search->id)->assertInertia(fn (Assert $page) => $page->where('search.active', false));
        // Les favoris, eux, sont intacts.
        $this->assertSame(1, OpportunityFavorite::where('user_id', $this->user('apprenant-double')->id)->count());
        $this->as('apprenant-double')->get('/opportunites/suivi')->assertInertia(fn (Assert $page) => $page->has('favorites.open', 1)->where('favorites.open.0.id', $first->id));

        // Reprise : seules les fiches publiées après la reprise sont annoncées ; celle publiée pendant l'arrêt ne l'est pas.
        $this->as('apprenant-double')->patchJson('/opportunites/alertes/'.$search->id, [...$patch, 'expected_version' => 2, 'active' => true])->assertOk()->assertJson(['active' => true]);
        $this->at('2026-11-19 09:00');
        $this->record('apres-reprise');
        $this->at('2026-11-20 09:00');
        $this->cycle();
        $titles = SavedSearchMatch::where('saved_search_id', $search->id)->orderBy('id')->get()->map(fn ($match) => Opportunity::find($match->opportunity_id)->title)->all();
        $this->assertSame(['Bourse fictive de physique premiere', 'Bourse fictive de physique apres-reprise'], $titles);

        // Recherche hebdomadaire : une synthèse par semaine au plus, regroupant les nouveautés de la semaine.
        $this->assertSame(1, UserNotification::where('url', '/opportunites/alertes/'.$weekly->id)->count());
        $this->at('2026-11-23 08:59');
        $this->cycle();
        $this->assertSame(1, UserNotification::where('url', '/opportunites/alertes/'.$weekly->id)->count());
        $this->at('2026-11-24 08:00');
        $this->cycle();
        $this->assertSame([2, 3], [UserNotification::where('url', '/opportunites/alertes/'.$weekly->id)->count(), SavedSearchMatch::where('saved_search_id', $weekly->id)->count()]);

        // Suppression : la recherche, ses annonces et ses synthèses partent ; favoris conservés ; rejouable.
        $this->as('apprenant-double')->deleteJson('/opportunites/alertes/'.$weekly->id)->assertOk()->assertJson(['replayed' => false]);
        $this->as('apprenant-double')->deleteJson('/opportunites/alertes/'.$weekly->id)->assertOk()->assertJson(['replayed' => true]);
        $this->assertSame([1, 0, 0, 1], [SavedSearch::count(), SavedSearchMatch::where('saved_search_id', $weekly->id)->count(),
            UserNotification::where('url', '/opportunites/alertes/'.$weekly->id)->whereIn('status', ['scheduled', 'delivered'])->count(), OpportunityFavorite::count()]);
        // Dix recherches au plus par compte.
        foreach (range(1, 9) as $index) {
            $this->save('apprenant-double', ['domaine' => 'Domaine fictif '.$index])->assertOk();
        }
        $this->travel(2)->minutes();
        $this->save('apprenant-double', ['domaine' => 'Domaine fictif de trop'])->assertStatus(422)->assertJsonValidationErrors('filters');
        $this->assertSame(10, SavedSearch::where('user_id', $this->user('apprenant-double')->id)->count());
    }

    public function test_a18_01_digests_are_capped_follow_channel_choices_and_are_withdrawn_when_nothing_is_published_anymore(): void
    {
        config(['campus.notifications.email_enabled' => true]);
        $this->preferences('apprenant-double', ['categories' => ['opportunity' => ['enabled' => true, 'email' => true, 'push' => false]], 'quiet_start' => '07:00:00', 'quiet_end' => '09:00:00']);
        $this->preferences('polyvalent', ['categories' => ['opportunity' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $this->preferences('independant', ['categories' => ['opportunity' => ['enabled' => false, 'email' => false, 'push' => false]]]);
        $withMail = SavedSearch::findOrFail($this->save('apprenant-double', ['q' => 'physique'], ['email' => true])->json('id'));
        $this->save('polyvalent', ['q' => 'physique'], ['email' => false])->assertOk();
        $this->save('independant', ['q' => 'physique'], ['email' => true])->assertOk();
        $editor = $this->user('editeur')->id;
        $this->at('2026-11-16 09:00');
        // Vingt-cinq fiches publiées d'un coup : la synthèse en regroupe vingt, les cinq autres attendent la synthèse suivante.
        foreach (range(1, 25) as $index) {
            Opportunity::create(['category' => 'training', 'title' => sprintf('Atelier fictif de physique n° %02d', $index), 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/atelier-'.$index,
                'conditions' => self::CONDITIONS, 'countries' => [], 'levels' => [], 'fields' => [], 'languages' => [], 'status' => 'published', 'verified_at' => now(), 'verified_by' => $editor, 'published_at' => now(),
                'normalized_title' => sprintf('atelier fictif de physique n %02d', $index), 'normalized_organizer' => 'organisme fictif', 'source_hash' => hash('sha256', 'atelier-'.$index), 'created_by' => $editor]);
        }
        $this->at('2026-11-17 08:00');
        $this->cycle();
        $this->assertSame(20, SavedSearchMatch::where('saved_search_id', $withMail->id)->count());
        $this->assertSame('« physique » : 20 nouvelle(s) fiche(s), 0 mise(s) à jour.', str_replace('Mots : ', '', $this->notes('apprenant-double', 'search.digest')->sole()->body));
        // Canaux : courriel seulement si la recherche le demande ET si la catégorie l'autorise ; plage silencieuse respectée ; catégorie désactivée : rien du tout.
        $delivery = NotificationDelivery::sole();
        $this->assertSame([$this->user('apprenant-double')->id, 'email', 'queued', '2026-11-17 09:00:00'], [$delivery->notification->user_id, $delivery->channel, $delivery->status, $delivery->next_attempt_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['delivered', 'cancelled'], [$this->notes('polyvalent', 'search.digest')->sole()->status, $this->notes('independant', 'search.digest')->sole()->status]);
        Notification::assertNothingSent();
        $this->at('2026-11-17 09:00');
        $this->cycle();
        Notification::assertSentToTimes($this->user('apprenant-double'), CampusReminder::class, 1);
        // Le courriel ne porte ni le nom de la recherche ni ses critères ni un titre de fiche.
        Notification::assertSentTo($this->user('apprenant-double'), CampusReminder::class, fn (CampusReminder $mail) => $mail->title === 'Du nouveau pour une de vos recherches enregistrées'
            && str_ends_with($mail->url, '/opportunites/alertes/'.$withMail->id) && ! str_contains(json_encode($mail->toMail($this->user('apprenant-double'))->toArray(), JSON_UNESCAPED_UNICODE), 'physique'));
        Notification::assertNotSentTo($this->user('polyvalent'), CampusReminder::class);
        // Le lendemain : les cinq fiches restantes, et elles seules.
        $this->at('2026-11-18 08:00');
        $this->cycle();
        $this->assertSame([25, 2], [SavedSearchMatch::where('saved_search_id', $withMail->id)->count(), $this->notes('apprenant-double', 'search.digest')->count()]);
        $this->assertStringContainsString('5 nouvelle(s) fiche(s)', $this->notes('apprenant-double', 'search.digest')->last()->body);
        $this->at('2026-11-19 08:00');
        $this->cycle();
        $this->assertSame(2, $this->notes('apprenant-double', 'search.digest')->count());

        // Synthèse programmée puis toutes ses fiches suspendues avant la remise : elle est retirée, rien n'est présenté comme une offre.
        $this->preferences('admin-a', []);
        $late = SavedSearch::findOrFail($this->save('admin-a', ['q' => 'tardive'])->json('id'));
        $this->at('2026-11-19 09:00');
        $record = $this->record('tardive', ['title' => 'Bourse fictive tardive']);
        $this->at('2026-11-20 08:00');
        app(NotificationPlanner::class)->run();
        $this->assertSame('scheduled', $this->notes('admin-a', 'search.digest')->sole()->status);
        $this->transition($record->id, 'suspend', ['note' => 'Motif interne fictif']);
        app(NotificationDispatcher::class)->release();
        $this->assertSame(['cancelled', 'unsubscribed'], array_values($this->notes('admin-a', 'search.digest')->sole()->only(['status', 'status_reason'])));
        $this->assertSame(1, SavedSearchMatch::where('saved_search_id', $late->id)->count());
        // Compte suspendu : ses recherches ne produisent plus rien.
        $before = $this->notes('polyvalent', 'search.digest')->count();
        DB::table('users')->where('id', $this->user('polyvalent')->id)->update(['status' => 'suspended']);
        $this->at('2026-11-20 09:00');
        $this->record('apres-suspension-compte');
        $this->at('2026-11-21 09:00');
        $this->cycle();
        $this->assertSame([2, 2, 25], [$before, $this->notes('polyvalent', 'search.digest')->count(), SavedSearchMatch::where('saved_search_id', SavedSearch::where('user_id', $this->user('polyvalent')->id)->value('id'))->count()]);
    }

    public function test_r37_favourite_reminders_fire_only_on_precise_deadlines_and_are_rescheduled_after_an_extension(): void
    {
        $exact = $this->record('exacte', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $dated = $this->record('datee', ['deadline_kind' => 'date', 'deadline_date' => '2026-12-15']);
        $rolling = $this->record('continue', ['deadline_kind' => 'rolling']);
        $unknown = $this->record('inconnue', ['deadline_kind' => 'unknown']);
        foreach ([$exact, $dated, $rolling, $unknown] as $opportunity) {
            $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        }
        // Démarche en cours, rappel réglé à 3 jours seulement ; favori d'un compte à Tokyo ; favori d'un compte qui a coupé la catégorie ; démarche abandonnée.
        $this->as('polyvalent')->putJson('/opportunites/'.$exact->id.'/demarche', ['expected_version' => 0, 'status' => 'preparing'])->assertOk();
        $this->preferences('polyvalent', ['favorite_first_days' => 3, 'favorite_second_days' => 0]);
        $this->as('editeur')->putJson('/opportunites/'.$dated->id.'/favori')->assertOk();
        $this->preferences('editeur', ['timezone' => 'Asia/Tokyo']);
        $this->as('independant')->putJson('/opportunites/'.$exact->id.'/favori')->assertOk();
        $this->preferences('independant', ['categories' => ['opportunity' => ['enabled' => false, 'email' => false, 'push' => false]]]);
        $this->as('admin-a')->putJson('/opportunites/'.$exact->id.'/demarche', ['expected_version' => 0, 'status' => 'abandoned'])->assertOk();
        DomainEvent::query()->update(['processed_at' => now()]);
        $due = fn (string $name) => $this->notes($name, 'opportunity.deadline');

        // Échéance exacte (10 déc. 22 h 59 UTC) : rappel sept jours avant, à l'instant près. Pas une minute plus tôt.
        $this->at('2026-12-03 22:58');
        $this->cycle();
        $this->assertSame(0, UserNotification::where('type', 'opportunity.deadline')->count());
        $this->at('2026-12-03 22:59');
        $this->cycle();
        $this->cycle();
        $first = $due('apprenant-double')->sole();
        $this->assertSame(['delivered', $exact->id, '2026-12-03 22:59:00', '2026-12-10 22:59:00', '/opportunites/'.$exact->id, 'Une opportunité que vous suivez arrive à échéance'],
            [$first->status, $first->subject_uuid, $first->scheduled_for->format('Y-m-d H:i:s'), $first->anchor_at->format('Y-m-d H:i:s'), $first->url, $first->title]);
        $this->assertStringContainsString('10/12/2026 à 23:59 (Europe/Paris)', $first->body);
        $this->assertSame([0, 0, 0], [$due('polyvalent')->count(), $due('independant')->count(), $due('admin-a')->count()]);

        // Prolongation avant le second rappel : le rappel remis est retiré, les suiveurs sont prévenus du changement, et les rappels sont recalculés sur la nouvelle date.
        $this->at('2026-12-04 10:00');
        $this->extend($exact, 'exacte', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-20T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $this->assertSame(['cancelled', 'deadline_changed'], [$first->fresh()->status, $first->fresh()->status_reason]);
        $this->cycle();
        $this->cycle();
        foreach (['apprenant-double', 'polyvalent'] as $follower) {
            $updated = $this->notes($follower, 'opportunity.updated')->sole();
            $this->assertSame(['delivered', 'La date limite d’une opportunité que vous suivez a changé'], [$updated->status, $updated->title]);
            $this->assertStringContainsString('nouvelle date : 20/12/2026 à 23:59 (Europe/Paris). Ancienne date : 10/12/2026 à 23:59 (Europe/Paris)', $updated->body);
        }
        $this->assertSame([0, 0, ['cancelled', 'preference']], [$this->notes('independant', 'opportunity.updated')->whereIn('status', ['scheduled', 'delivered'])->count(), $this->notes('admin-a', 'opportunity.updated')->count(),
            array_values($this->notes('independant', 'opportunity.updated')->sole()->only(['status', 'status_reason']))]);
        // Aux anciennes dates de rappel (J-3 et J-1 de l'ancienne échéance) : plus rien.
        foreach (['2026-12-07 22:59', '2026-12-07 23:00', '2026-12-08 08:00', '2026-12-09 22:59', '2026-12-10 22:59'] as $old) {
            $this->at($old);
            $this->cycle();
        }
        $this->assertSame(0, UserNotification::where('type', 'opportunity.deadline')->where('subject_uuid', $exact->id)->whereIn('status', ['scheduled', 'delivered'])->count());
        $this->assertSame('published', $exact->fresh()->status);
        // Nouveaux rappels : J-7 (13 déc.), J-3 pour la démarche (17 déc.), J-1 (19 déc.), chacun une seule fois.
        $this->at('2026-12-13 22:59');
        $this->cycle();
        $this->cycle();
        $this->at('2026-12-13 23:00');
        $this->cycle();
        $this->at('2026-12-14 08:00');
        $this->cycle();
        $this->cycle();
        $this->at('2026-12-17 22:59');
        $this->cycle();
        $this->at('2026-12-19 22:59');
        $this->cycle();
        $this->cycle();
        $live = fn (string $name) => $due($name)->where('subject_uuid', $exact->id)->where('status', 'delivered')->map(fn ($n) => [$n->scheduled_for->format('m-d H:i'), $n->context['days']])->values()->all();
        $this->assertSame([['12-13 22:59', 7], ['12-19 22:59', 1]], $live('apprenant-double'));
        $this->assertSame([['12-17 22:59', 3]], $live('polyvalent'));
        $this->assertSame([[], []], [$live('independant'), $live('admin-a')]);

        // Date seule (15 déc., heure non communiquée) : rappel à l'heure choisie par la personne dans son fuseau, jamais à une heure de clôture supposée.
        $day = $due('apprenant-double')->where('subject_uuid', $dated->id)->map(fn ($n) => $n->scheduled_for->format('m-d H:i'))->values()->all();
        $tokyo = $due('editeur')->where('subject_uuid', $dated->id)->map(fn ($n) => $n->scheduled_for->format('m-d H:i'))->values()->all();
        $this->assertSame([['12-08 08:00', '12-14 08:00'], ['12-07 23:00', '12-13 23:00']], [$day, $tokyo]);
        $this->assertStringContainsString('15/12/2026 (heure non communiquée)', $due('apprenant-double')->where('subject_uuid', $dated->id)->first()->body);
        // Clôture continue et date non communiquée : aucun rappel, jamais.
        $this->assertSame(0, UserNotification::where('type', 'opportunity.deadline')->whereIn('subject_uuid', [$rolling->id, $unknown->id])->count());

        // Recontrôle avant remise : favori retiré ou fiche suspendue entre la programmation et la remise.
        $other = $this->record('recontrole', ['deadline_kind' => 'instant', 'deadline_local' => '2027-01-20T12:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $third = $this->record('suspendue-avant', ['deadline_kind' => 'instant', 'deadline_local' => '2027-01-20T12:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$other->id.'/favori')->assertOk();
        $this->as('apprenant-double')->putJson('/opportunites/'.$third->id.'/favori')->assertOk();
        DomainEvent::query()->update(['processed_at' => now()]);
        $this->at('2027-01-13 12:00');
        app(NotificationPlanner::class)->run();
        $this->as('apprenant-double')->deleteJson('/opportunites/'.$other->id.'/favori')->assertOk();
        Opportunity::whereKey($third->id)->update(['status' => 'suspended', 'suspended_at' => now()]);
        app(NotificationDispatcher::class)->release();
        $this->assertSame(['not_followed', 'obsolete'], [$due('apprenant-double')->where('subject_uuid', $other->id)->first()->status_reason, $due('apprenant-double')->where('subject_uuid', $third->id)->first()->status_reason]);
        // Réglages : 0 à 30 jours, validés ; refusés au-delà, y compris par MySQL.
        $settings = ['expected_version' => 1, 'timezone' => 'Africa/Abidjan', 'quiet_start' => null, 'quiet_end' => null, 'course_end_delay' => 5, 'task_day_before' => true, 'task_same_day' => true,
            'task_reminder_time' => '08:00', 'digest_enabled' => false, 'digest_time' => '07:00', 'categories' => []];
        $this->as('polyvalent')->putJson('/notifications/preferences', [...$settings, 'favorite_first_days' => 31])->assertStatus(422);
        $this->as('polyvalent')->putJson('/notifications/preferences', [...$settings, 'favorite_first_days' => 10, 'favorite_second_days' => 2])->assertOk();
        $this->assertSame([10, 2], [(int) NotificationPreference::where('user_id', $this->user('polyvalent')->id)->value('favorite_first_days'), (int) NotificationPreference::where('user_id', $this->user('polyvalent')->id)->value('favorite_second_days')]);
        $this->as('polyvalent')->get('/notifications/preferences')->assertInertia(fn (Assert $page) => $page->where('preference.favoriteFirstDays', 10)->where('preference.favoriteSecondDays', 2));
        try {
            DB::table('notification_preferences')->where('user_id', $this->user('polyvalent')->id)->update(['favorite_first_days' => 31]);
            $this->fail('MySQL a accepté un rappel à plus de trente jours.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }
    }

    public function test_r38_a_suspension_informs_followers_without_ever_presenting_the_record_as_an_open_offer(): void
    {
        config(['campus.notifications.email_enabled' => true]);
        $opportunity = $this->record('suspecte', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-10T23:59', 'deadline_timezone' => 'Europe/Paris']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        $this->preferences('apprenant-double', ['categories' => ['opportunity' => ['enabled' => true, 'email' => true, 'push' => false]]]);
        $this->as('polyvalent')->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => 0, 'status' => 'sent'])->assertOk();
        $this->as('admin-a')->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => 0, 'status' => 'rejected'])->assertOk();
        $search = SavedSearch::findOrFail($this->save('independant', ['q' => 'physique'])->json('id'));
        DomainEvent::query()->update(['processed_at' => now()]);

        $this->at('2026-11-16 10:00');
        $this->transition($opportunity->id, 'suspend', ['note' => 'Motif interne fictif : source douteuse']);
        $this->cycle();
        $this->cycle();
        // Détenteur du favori et auteur d'une démarche en cours : prévenus une fois. Démarche close, simple abonné à une recherche, éditeur : non.
        foreach (['apprenant-double', 'polyvalent'] as $follower) {
            $notice = $this->notes($follower, 'opportunity.suspended')->sole();
            $this->assertSame(['delivered', 'opportunity', 'Une opportunité que vous suivez a été suspendue', '/opportunites/suivi', $opportunity->id], [$notice->status, $notice->category, $notice->title, $notice->url, $notice->subject_uuid]);
            $this->assertStringContainsString('Ne candidatez pas à partir d’un ancien lien', $notice->body);
        }
        $this->assertSame([0, 0, 0], [$this->notes('admin-a')->count(), $this->notes('independant')->count(), $this->notes('editeur')->count()]);
        // Le message ne contient ni le motif interne ni un lien vers la source ; il renvoie au suivi personnel, pas à une page d'offre.
        $all = json_encode(UserNotification::all()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['Motif interne', 'source douteuse', 'example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all);
        }
        Notification::assertSentToTimes($this->user('apprenant-double'), CampusReminder::class, 1);
        Notification::assertSentTo($this->user('apprenant-double'), CampusReminder::class, fn (CampusReminder $mail) => $mail->title === 'Une opportunité que vous suivez a été suspendue' && str_ends_with($mail->url, '/opportunites/suivi'));
        // La page visée montre la fiche comme suspendue, sans contenu ; la fiche n'est dans aucune liste ouverte ni dans aucune synthèse.
        $this->as('apprenant-double')->get('/opportunites/suivi')->assertOk()->assertInertia(fn (Assert $page) => $page->has('favorites.suspended', 1)->where('favorites.open', [])->missing('favorites.suspended.0.deadline'));
        $this->as('apprenant-double')->get('/opportunites')->assertInertia(fn (Assert $page) => $page->where('opportunities', []));
        $this->at('2026-11-17 10:00');
        $this->cycle();
        $this->assertSame([0, 0], [SavedSearchMatch::where('saved_search_id', $search->id)->count(), UserNotification::where('type', 'search.digest')->count()]);
        // Aucun rappel d'échéance pour une fiche suspendue, même à J-7.
        $this->at('2026-12-03 22:59');
        $this->cycle();
        $this->assertSame(0, UserNotification::where('type', 'opportunity.deadline')->count());

        // Reprise après vérification : les suiveurs sont prévenus ; la fiche redevient une offre normale, avec ses rappels.
        $this->at('2026-12-05 10:00');
        $this->transition($opportunity->id, 'resume', ['attest' => true]);
        $this->cycle();
        $this->cycle();
        $this->assertSame([1, 1, 0], [$this->notes('apprenant-double', 'opportunity.resumed')->count(), $this->notes('polyvalent', 'opportunity.resumed')->count(), $this->notes('admin-a')->count()]);
        $this->assertSame('/opportunites/'.$opportunity->id, $this->notes('apprenant-double', 'opportunity.resumed')->sole()->url);
        $this->at('2026-12-09 22:59');
        $this->cycle();
        $this->assertSame(1, $this->notes('apprenant-double', 'opportunity.deadline')->where('status', 'delivered')->count());

        // Suspension annoncée trop tard (panne de plus de 48 h) : l'alerte n'est plus créée ; suspension puis reprise avant la remise : alerte annulée.
        $late = $this->record('tardive', ['deadline_kind' => 'rolling']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$late->id.'/favori')->assertOk();
        $this->transition($late->id, 'suspend', ['note' => 'Motif interne fictif']);
        $this->at('2026-12-12 23:00');
        $this->cycle();
        $this->assertSame(0, UserNotification::where('type', 'opportunity.suspended')->where('subject_uuid', $late->id)->count());
        $quick = $this->record('breve', ['deadline_kind' => 'rolling']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$quick->id.'/favori')->assertOk();
        $this->transition($quick->id, 'suspend', ['note' => 'Motif interne fictif']);
        app(NotificationPlanner::class)->run();
        $this->transition($quick->id, 'resume', ['attest' => true]);
        app(NotificationDispatcher::class)->release();
        $this->assertSame(['cancelled', 'obsolete'], array_values(UserNotification::where('type', 'opportunity.suspended')->where('subject_uuid', $quick->id)->sole()->only(['status', 'status_reason'])));
    }
}
