<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\Opportunity;
use App\Models\OpportunityApplication;
use App\Models\OpportunityApplicationItem;
use App\Models\OpportunityFavorite;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\OpportunityFollowUps;
use Carbon\CarbonImmutable;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OpportunityFollowUpTest extends TestCase
{
    use DatabaseTransactions;

    private const CONDITIONS = 'Conditions fictives publiées par l’organisme fictif : texte long de la source, à ne jamais recopier dans une démarche.';

    private const NOTE = 'Note privée fictive : demander une lettre à mon enseignant';

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

    private function payload(string $key, array $overrides = []): array
    {
        return array_replace(['category' => 'scholarship', 'title' => 'Bourse fictive '.$key, 'organizer' => 'Organisme fictif', 'campaign' => '2026', 'source_url' => 'https://example.test/'.$key,
            'application_url' => 'https://candidature.example.test/'.$key, 'summary' => 'Résumé fictif de la source.', 'conditions' => self::CONDITIONS, 'countries' => [], 'modality' => 'unspecified', 'levels' => [],
            'fields' => [], 'languages' => [], 'funding' => 'unspecified', 'fees' => 'unspecified', 'fees_detail' => null, 'deadline_kind' => 'unknown', 'deadline_local' => null, 'deadline_timezone' => null, 'deadline_date' => null], $overrides);
    }

    private function record(string $key, array $overrides = [], string $status = 'published'): Opportunity
    {
        $id = $this->editor()->postJson('/administration/catalogue', $this->payload($key, $overrides))->assertOk()->json('id');
        if ($status !== 'draft') {
            $this->transition($id, 'submit');
            $this->transition($id, 'verify', ['attest' => true]);
        }

        return Opportunity::findOrFail($id);
    }

    private function transition(string $id, string $action, array $extra = []): void
    {
        $this->editor()->postJson('/administration/catalogue/'.$id.'/transition', ['action' => $action, 'expected_version' => Opportunity::findOrFail($id)->lock_version, ...$extra])->assertOk();
    }

    private function follow(string $name, Opportunity $opportunity, array $data = []): TestResponse
    {
        $version = OpportunityApplication::where('user_id', $this->user($name)->id)->where('opportunity_id', $opportunity->id)->value('lock_version') ?? 0;

        return $this->as($name)->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => $version, 'status' => 'to_study', ...$data]);
    }

    private function suivi(string $name): array
    {
        return $this->as($name)->get('/opportunites/suivi')->assertOk()->viewData('page')['props'];
    }

    private function fiche(string $name, Opportunity $opportunity): array
    {
        return $this->as($name)->get('/opportunites/'.$opportunity->id)->assertOk()->viewData('page')['props'];
    }

    public function test_r39_the_same_favourite_added_from_two_devices_exists_once_and_removal_is_idempotent(): void
    {
        $opportunity = $this->record('favorite');
        $draft = $this->record('brouillon', [], 'draft');
        $url = '/opportunites/'.$opportunity->id.'/favori';

        // Deux appareils du même compte ajoutent le même favori : un seul existe.
        $this->as('apprenant-double')->putJson($url)->assertOk()->assertJson(['favorite' => true, 'replayed' => false]);
        $this->as('apprenant-double', 'A')->putJson($url)->assertOk()->assertJson(['favorite' => true, 'replayed' => true]);
        $this->as('apprenant-double')->put($url)->assertSessionHasNoErrors();
        $this->assertSame(1, OpportunityFavorite::count());
        // La base refuse elle-même un second favori : deux demandes réellement simultanées ne peuvent pas en créer deux.
        try {
            DB::table('opportunity_favorites')->insert(['user_id' => $this->user('apprenant-double')->id, 'opportunity_id' => $opportunity->id, 'created_at' => now()]);
            $this->fail('MySQL a accepté un favori en double.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
        // Les deux appareils voient le même état ; un autre compte a ses propres favoris.
        foreach ([null, 'A', 'B'] as $context) {
            $this->as('apprenant-double', $context)->get('/opportunites/suivi')->assertOk()->assertInertia(fn (Assert $page) => $page->component('OpportunityFollowUp')->has('favorites.open', 1)->where('favorites.open.0.id', $opportunity->id));
        }
        $this->assertTrue($this->fiche('apprenant-double', $opportunity)['favorite']);
        $this->assertFalse($this->fiche('polyvalent', $opportunity)['favorite']);
        $this->as('polyvalent')->putJson($url)->assertOk()->assertJson(['replayed' => false]);
        $this->assertSame(2, OpportunityFavorite::count());

        // Retrait depuis un appareil : visible sur l'autre. Retrait répété : aucune erreur, aucun effet sur les autres comptes.
        $this->as('apprenant-double', 'A')->deleteJson($url)->assertOk()->assertJson(['favorite' => false, 'replayed' => false]);
        $this->as('apprenant-double')->deleteJson($url)->assertOk()->assertJson(['favorite' => false, 'replayed' => true]);
        $this->as('apprenant-double')->delete($url)->assertSessionHasNoErrors();
        $this->assertSame([], $this->suivi('apprenant-double')['favorites']['open']);
        $this->assertSame([$this->user('polyvalent')->id], OpportunityFavorite::pluck('user_id')->all());
        // Retirer un favori qui n'a jamais existé, ou d'une fiche inconnue : même réponse, sans rien révéler.
        $this->as('independant')->deleteJson($url)->assertOk()->assertJson(['replayed' => true]);
        $this->as('independant')->deleteJson('/opportunites/'.Str::uuid().'/favori')->assertOk()->assertJson(['replayed' => true]);
        $this->as('independant')->deleteJson('/opportunites/'.$draft->id.'/favori')->assertOk()->assertJson(['replayed' => true]);
        // On n'ajoute en favori qu'une fiche publiée ; un visiteur est renvoyé à la connexion.
        $this->as('independant')->putJson('/opportunites/'.$draft->id.'/favori')->assertNotFound();
        $this->as('independant')->putJson('/opportunites/'.Str::uuid().'/favori')->assertNotFound();
        $this->freshDevice()->put($url)->assertRedirect('/connexion');
        $this->freshDevice()->delete($url)->assertRedirect('/connexion');
        $this->freshDevice()->get('/opportunites/suivi')->assertRedirect('/connexion');
        $this->assertSame(1, OpportunityFavorite::count());
    }

    public function test_r37_r38_favourites_are_sorted_open_closing_soon_expired_and_suspended_with_useful_information(): void
    {
        $far = $this->record('lointaine', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-30T12:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $soon = $this->record('proche', ['deadline_kind' => 'instant', 'deadline_local' => '2026-11-20T12:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $rolling = $this->record('continue', ['deadline_kind' => 'rolling']);
        $closing = $this->record('expirante', ['deadline_kind' => 'instant', 'deadline_local' => '2026-11-17T12:00', 'deadline_timezone' => 'Africa/Abidjan']);
        $suspect = $this->record('suspecte', ['deadline_kind' => 'date', 'deadline_date' => '2026-12-15']);
        foreach ([$far, $soon, $rolling, $closing, $suspect] as $opportunity) {
            $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        }
        $this->follow('polyvalent', $suspect)->assertOk();
        $this->follow('independant', $suspect, ['status' => 'abandoned'])->assertOk();
        $ids = fn (array $rows) => array_column($rows, 'id');

        // Bientôt clos : clôture connue dans les sept jours. Ouverts : le reste, y compris les fiches sans date connue.
        $lists = $this->suivi('apprenant-double')['favorites'];
        $this->assertSame([[$closing->id, $soon->id], [$suspect->id, $far->id, $rolling->id], [], []], [$ids($lists['soon']), $ids($lists['open']), $ids($lists['expired']), $ids($lists['suspended'])]);
        $this->assertSame(7, $this->suivi('apprenant-double')['closingSoonDays']);

        // R38 : la fiche suivie est suspendue. Elle passe dans « suspendus », réduite à son titre, avec l'avertissement ; l'événement est prêt pour le lot 18.
        $this->transition($suspect->id, 'suspend', ['note' => 'Motif interne fictif']);
        // R37 : la date d'une fiche suivie est prolongée. Le favori montre la nouvelle date et l'ancienne.
        $this->editor()->patchJson('/administration/catalogue/'.$soon->id, ['expected_version' => 3, 'attest' => true, ...$this->payload('proche', ['deadline_kind' => 'instant', 'deadline_local' => '2026-12-05T18:00', 'deadline_timezone' => 'Africa/Abidjan'])])->assertOk();
        // Une fiche suivie se clôt et est archivée.
        $this->at('2026-11-17 12:00');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();

        $lists = $this->suivi('apprenant-double')['favorites'];
        $this->assertSame([[], [$soon->id, $far->id, $rolling->id], [$closing->id], [$suspect->id]], [$ids($lists['soon']), $ids($lists['open']), $ids($lists['expired']), $ids($lists['suspended'])]);
        $this->assertSame(['id' => $suspect->id, 'title' => 'Bourse fictive suspecte', 'organizer' => 'Organisme fictif', 'campaign' => '2026'], $lists['suspended'][0]);
        $this->assertSame(['05/12/2026 à 18:00 (Africa/Abidjan)', '20/11/2026 à 12:00 (Africa/Abidjan)'], [$lists['open'][0]['deadline']['text'], $lists['open'][0]['deadline']['previous']['text']]);
        $this->assertSame('closed', $lists['expired'][0]['deadline']['state']);
        $this->as('apprenant-double')->get('/opportunites/suivi')->assertDontSee('Motif interne')->assertDontSee('example.test/suspecte');
        // La fiche suspendue reste consultable par celui qui la suit, sans contenu ; il peut retirer son favori mais plus en ajouter.
        $suspended = $this->fiche('apprenant-double', $suspect);
        $this->assertSame(['suspended', true, false, null], [$suspended['opportunity']['availability'], $suspended['favorite'], $suspended['canFavorite'], $suspended['opportunity']['sourceUrl']]);
        $this->as('independant')->putJson('/opportunites/'.$suspect->id.'/favori')->assertNotFound();
        $this->as('apprenant-double')->deleteJson('/opportunites/'.$suspect->id.'/favori')->assertOk()->assertJson(['replayed' => false]);
        // Un favori expiré reste un favori, lisible pour mémoire.
        $this->assertTrue($this->fiche('apprenant-double', $closing)['favorite']);

        // Préparation du lot 18 : les événements existent, avec la fiche et sa version, et la liste des comptes à prévenir est calculable.
        $this->assertSame([$suspect->id, $soon->id, $closing->id], [DomainEvent::where('type', 'opportunity.suspended')->sole()->payload['opportunity_id'],
            DomainEvent::where('type', 'opportunity.deadline_changed')->sole()->payload['opportunity_id'], DomainEvent::where('type', 'opportunity.archived')->sole()->payload['opportunity_id']]);
        $this->assertTrue(DomainEvent::where('type', 'opportunity.deadline_changed')->sole()->payload['extended']);
        $followers = app(OpportunityFollowUps::class);
        // Favori retiré : plus prévenu. Démarche en cours : prévenu. Démarche abandonnée : non.
        $this->assertSame([$this->user('polyvalent')->id], $followers->followers($suspect->id)->all());
        $this->assertSame([$this->user('apprenant-double')->id], $followers->followers($soon->id)->all());
        // Aucun événement ne désigne une personne ni un favori.
        $this->assertStringNotContainsString('user', json_encode(DomainEvent::where('type', 'like', 'opportunity.%')->get()->pluck('payload')->all()));
    }

    public function test_r40_an_application_changes_only_by_the_owners_explicit_choice(): void
    {
        $opportunity = $this->record('candidature');
        $draft = $this->record('brouillon', [], 'draft');

        // Commencer une démarche : sur une fiche publiée seulement, état initial choisi par la personne.
        $this->follow('apprenant-double', $draft)->assertNotFound();
        $this->as('apprenant-double')->putJson('/opportunites/'.Str::uuid().'/demarche', ['expected_version' => 0, 'status' => 'to_study'])->assertNotFound();
        foreach ([['status' => 'candidatee'], ['status' => null], ['personal_deadline_on' => '20/12/2026'], ['note' => str_repeat('x', 501)], ['expected_version' => -1]] as $invalid) {
            $this->follow('apprenant-double', $opportunity, $invalid)->assertStatus(422);
        }
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => 3, 'status' => 'sent'])->assertStatus(409);
        $this->assertSame(0, OpportunityApplication::count());
        $this->follow('apprenant-double', $opportunity, ['status' => 'preparing', 'personal_deadline_on' => '2026-12-01', 'note' => self::NOTE.' <script>alert(1)</script>'])->assertOk()->assertJson(['version' => 1, 'status' => 'preparing', 'replayed' => false]);
        $application = OpportunityApplication::sole();
        $this->assertSame([$this->user('apprenant-double')->id, $opportunity->id, 'preparing', '2026-12-01', self::NOTE.' alert(1)'], [$application->user_id, $application->opportunity_id, $application->status, $application->personal_deadline_on->toDateString(), $application->note]);

        // R40 : consulter la fiche, la liste, le suivi, autant de fois que l'on veut, et « cliquer » sur candidater ne change rien.
        $before = [$application->status, $application->lock_version, $application->updated_at->toIso8601String(), $application->status_changed_at->toIso8601String()];
        $this->at('2026-11-16 09:00');
        foreach (range(1, 3) as $visit) {
            $shown = $this->fiche('apprenant-double', $opportunity);
            // Le lien de candidature est l'adresse de l'organisateur telle quelle : aucun identifiant de compte, de favori ou de démarche n'y figure.
            $this->assertSame('https://candidature.example.test/candidature', $shown['opportunity']['applicationUrl']);
            $this->assertSame(['preparing', 1], [$shown['application']['status'], $shown['application']['version']]);
            $this->as('apprenant-double')->get('/opportunites')->assertOk();
            $this->suivi('apprenant-double');
        }
        foreach (['/candidater', '/candidature', '/clic', '/demarche/envoyee'] as $suffix) {
            $this->as('apprenant-double')->post('/opportunites/'.$opportunity->id.$suffix)->assertNotFound();
        }
        $this->as('apprenant-double')->get('/opportunites/'.$opportunity->id.'/demarche')->assertStatus(405);
        $application->refresh();
        $this->assertSame($before, [$application->status, $application->lock_version, $application->updated_at->toIso8601String(), $application->status_changed_at->toIso8601String()]);
        foreach ([$application->id, (string) $application->user_id.'-', 'apprenant-double'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $this->fiche('apprenant-double', $opportunity)['opportunity']['applicationUrl'].$this->fiche('apprenant-double', $opportunity)['opportunity']['sourceUrl']);
        }
        $this->assertEmpty(collect(app('router')->getRoutes())->filter(fn ($route) => preg_match('/candidat|clic|click|sortie|redirect|track/i', $route->uri().' '.$route->getName()))->all());

        // Changements manuels uniquement : les sept états se choisissent ; chaque choix est daté ; rien d'autre ne modifie l'état.
        foreach (['to_study', 'preparing', 'sent', 'waiting', 'accepted', 'rejected', 'abandoned'] as $index => $status) {
            $this->at('2026-11-16 '.(10 + $index).':00');
            $this->follow('apprenant-double', $opportunity, ['status' => $status])->assertOk()->assertJson(['status' => $status, 'version' => $index + 2]);
            $this->assertSame($status, $this->fiche('apprenant-double', $opportunity)['application']['status']);
        }
        $application->refresh();
        $this->assertSame(['abandoned', '2026-11-16 16:00:00', null, null], [$application->status, $application->status_changed_at->format('Y-m-d H:i:s'), $application->note, $application->personal_deadline_on]);
        // Version dépassée (second appareil resté sur une ancienne page) : conflit ; même contenu rejoué : sans effet.
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => 2, 'status' => 'sent'])->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/demarche', ['expected_version' => 7, 'status' => 'abandoned'])->assertOk()->assertJson(['version' => 8, 'replayed' => true]);
        $this->assertSame(['abandoned', 8], [$application->fresh()->status, $application->fresh()->lock_version]);
        try {
            DB::table('opportunity_applications')->where('id', $application->id)->update(['status' => 'envoyee']);
            $this->fail('MySQL a accepté un état inconnu.');
        } catch (QueryException $error) {
            $this->assertSame(3819, (int) $error->errorInfo[1]);
        }
        try {
            DB::table('opportunity_applications')->insert(['id' => (string) Str::uuid(), 'user_id' => $application->user_id, 'opportunity_id' => $opportunity->id, 'reference' => '{}', 'status_changed_at' => now()]);
            $this->fail('MySQL a accepté une seconde démarche pour la même fiche.');
        } catch (QueryException $error) {
            $this->assertSame(1062, (int) $error->errorInfo[1]);
        }
    }

    public function test_d17_01_note_checklist_and_deletion_stay_text_only_and_removable(): void
    {
        $opportunity = $this->record('pieces');
        $base = '/opportunites/'.$opportunity->id.'/demarche';
        // Pas de liste sans démarche.
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Relevé de notes'])->assertNotFound();
        $this->follow('apprenant-double', $opportunity, ['note' => self::NOTE, 'personal_deadline_on' => '2026-12-01'])->assertOk();

        // Liste de pièces : simples lignes de texte à cocher ; ajout rejouable ; vingt lignes au plus.
        $key = (string) Str::uuid();
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => $key, 'label' => ''])->assertStatus(422);
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => $key, 'label' => str_repeat('x', 161)])->assertStatus(422);
        $first = $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => $key, 'label' => 'Relevé de notes <b>fictif</b>'])->assertOk()->assertJson(['done' => false]);
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => $key, 'label' => 'Relevé de notes fictif'])->assertOk()->assertJson(['id' => $first->json('id')]);
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Lettre de motivation fictive'])->assertOk();
        $this->as('apprenant-double')->patchJson($base.'/pieces/'.$first->json('id'), ['done' => true])->assertOk()->assertJson(['done' => true]);
        $this->as('apprenant-double')->patchJson($base.'/pieces/'.$first->json('id'), ['done' => true])->assertOk();
        $this->as('apprenant-double')->patchJson($base.'/pieces/'.$first->json('id'), ['done' => 'peut-être'])->assertStatus(422);
        $shown = $this->fiche('apprenant-double', $opportunity)['application'];
        $this->assertSame([['Relevé de notes fictif', true], ['Lettre de motivation fictive', false]], array_map(fn ($item) => [$item['label'], $item['done']], $shown['items']));
        $this->assertSame([self::NOTE, '2026-12-01'], [$shown['note'], $shown['personalDeadlineOn']]);
        // Le limiteur de débit compte par compte et par minute : la minute suivante repart de zéro.
        $this->travel(2)->minutes();
        foreach (range(3, 20) as $index) {
            $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Pièce fictive n° '.$index])->assertOk();
        }
        $this->as('apprenant-double')->postJson($base.'/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Pièce fictive de trop'])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->assertSame(20, OpportunityApplicationItem::count());
        $this->as('apprenant-double')->deleteJson($base.'/pieces/'.$first->json('id'))->assertOk()->assertJson(['deleted' => true]);
        $this->as('apprenant-double')->deleteJson($base.'/pieces/'.$first->json('id'))->assertNotFound();

        // Aucun dépôt de fichier sur une démarche : aucune route, et la table des pièces jointes n'accepte que tâches et retours de cours.
        $this->assertEmpty(collect(app('router')->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'demarche') && preg_match('/pieces-jointes|fichier|file|upload/i', $route->uri()))->all());
        $this->as('apprenant-double')->post($base.'/pieces-jointes', ['client_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertFalse(collect(DB::select('SHOW COLUMNS FROM attachments'))->contains(fn ($column) => str_contains($column->Field, 'opportunity')));

        $this->travel(2)->minutes();
        // Note supprimée : elle disparaît de la base, pas seulement de l'écran. Le reste de la démarche est conservé.
        $this->follow('apprenant-double', $opportunity, ['note' => null, 'personal_deadline_on' => '2026-12-01'])->assertOk()->assertJson(['version' => 2]);
        $this->assertSame([null, '2026-12-01', 19], [OpportunityApplication::sole()->note, OpportunityApplication::sole()->personal_deadline_on->toDateString(), OpportunityApplicationItem::count()]);
        $this->assertStringNotContainsString('lettre à mon enseignant', json_encode([$this->fiche('apprenant-double', $opportunity), $this->suivi('apprenant-double'), DB::table('opportunity_applications')->get()], JSON_UNESCAPED_UNICODE));
        $this->follow('apprenant-double', $opportunity, ['note' => '   ', 'personal_deadline_on' => null])->assertOk();
        $this->assertSame([null, null], [OpportunityApplication::sole()->note, OpportunityApplication::sole()->personal_deadline_on]);

        // Suppression de la démarche : rejouable ; la liste part avec elle ; le favori éventuel reste.
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        $this->as('apprenant-double')->deleteJson($base)->assertOk()->assertJson(['deleted' => true, 'replayed' => false]);
        $this->as('apprenant-double')->deleteJson($base)->assertOk()->assertJson(['deleted' => true, 'replayed' => true]);
        $this->assertSame([0, 0, 1], [OpportunityApplication::count(), OpportunityApplicationItem::count(), OpportunityFavorite::count()]);
        $this->assertNull($this->fiche('apprenant-double', $opportunity)['application']);
    }

    public function test_r41_nobody_else_can_read_or_change_a_private_application_on_any_endpoint(): void
    {
        $opportunity = $this->record('privee');
        $this->follow('apprenant-double', $opportunity, ['status' => 'sent', 'note' => self::NOTE, 'personal_deadline_on' => '2026-12-01'])->assertOk();
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        $item = $this->as('apprenant-double')->postJson('/opportunites/'.$opportunity->id.'/demarche/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Pièce privée fictive'])->assertOk()->json('id');
        $application = OpportunityApplication::sole();
        $base = '/opportunites/'.$opportunity->id;
        $platform = $this->user('plateforme')->memberships()->firstOrFail()->id;
        $actors = ['autre apprenant' => fn () => $this->as('polyvalent', 'A'), 'enseignant' => fn () => $this->as('enseignant-a', 'A'), 'administration A' => fn () => $this->as('admin-a', 'A'), 'administration B' => fn () => $this->as('admin-b', 'B'),
            'administrateur plateforme' => fn () => $this->freshDevice()->actingAs($this->user('plateforme'))->withSession([ActiveContext::SESSION_KEY => $platform]), 'éditeur' => fn () => $this->editor(), 'tiers' => fn () => $this->as('independant')];

        foreach ($actors as $label => $actor) {
            $name = $actor()->get('/')->viewData('page')['props']['auth']['user']['name'];
            $user = User::where('name', $name)->firstOrFail();
            // Lecture : ni la fiche ni le suivi ne montrent la démarche, le favori, la note ou la liste d'autrui.
            $actor()->get($base)->assertOk()->assertDontSee('lettre à mon enseignant')->assertDontSee('Pièce privée fictive')->assertInertia(fn (Assert $page) => $page->where('application', null)->where('favorite', false));
            $actor()->get('/opportunites/suivi')->assertOk()->assertDontSee('lettre à mon enseignant')->assertInertia(fn (Assert $page) => $page->where('applications', [])->where('favorites.open', []));
            // Écriture : chaque route agit sur le suivi de l'appelant, jamais sur celui d'un autre.
            $actor()->putJson($base.'/demarche', ['expected_version' => 1, 'status' => 'abandoned', 'note' => 'Écrasement fictif'])->assertStatus(409);
            $actor()->deleteJson($base.'/demarche')->assertOk()->assertJson(['replayed' => true]);
            $actor()->postJson($base.'/demarche/pieces', ['client_key' => (string) Str::uuid(), 'label' => 'Intrusion fictive'])->assertNotFound();
            $actor()->patchJson($base.'/demarche/pieces/'.$item, ['done' => true])->assertNotFound();
            $actor()->deleteJson($base.'/demarche/pieces/'.$item)->assertNotFound();
            $actor()->deleteJson($base.'/favori')->assertOk()->assertJson(['replayed' => true]);
            $this->assertTrue(Gate::forUser($user)->denies('view', $application), $label);
            $this->assertTrue(Gate::forUser($user)->denies('update', $application), $label);
        }
        // L'équipe éditoriale ne voit ni qui suit une fiche ni combien : rien dans ses écrans.
        foreach (['/administration/catalogue', '/administration/catalogue/'.$opportunity->id, '/administration/catalogue/signalements'] as $url) {
            $content = $this->editor()->get($url)->assertOk()->getContent();
            foreach (['lettre à mon enseignant', 'Pièce privée fictive', 'Apprenant fictif A et B', 'favori', 'follower', 'demarche'] as $private) {
                $this->assertStringNotContainsString($private, $content, $url.' : '.$private);
            }
        }
        foreach (['/administration/etablissement', '/administration/plateforme'] as $url) {
            $actor = $url === '/administration/plateforme' ? $actors['administrateur plateforme'] : $actors['administration A'];
            $actor()->get($url)->assertOk()->assertDontSee('lettre à mon enseignant')->assertDontSee('Pièce privée fictive');
        }
        // Aucune route ne désigne une démarche ou un favori par son identifiant ; visiteur renvoyé à la connexion sur chaque route.
        $this->assertEmpty(collect(app('router')->getRoutes())->filter(fn ($route) => preg_match('/\{(application|favorite|favori|demarche|user)\}/', $route->uri()) && str_starts_with($route->uri(), 'opportunites'))->all());
        foreach ([['put', $base.'/favori'], ['delete', $base.'/favori'], ['put', $base.'/demarche'], ['delete', $base.'/demarche'], ['post', $base.'/demarche/pieces'], ['patch', $base.'/demarche/pieces/'.$item], ['delete', $base.'/demarche/pieces/'.$item], ['get', '/opportunites/suivi']] as [$method, $url]) {
            $this->freshDevice()->{$method}($url)->assertRedirect('/connexion');
        }
        // Rien n'a bougé chez le propriétaire, qui seul lit sa démarche ; rien n'est journalisé ni émis.
        $application->refresh();
        $this->assertSame(['sent', self::NOTE, 1, 1, 1, false], [$application->status, $application->note, $application->lock_version, OpportunityApplication::count(), OpportunityFavorite::count(), OpportunityApplicationItem::sole()->done]);
        $this->assertTrue(Gate::forUser($this->user('apprenant-double'))->allows('view', $application));
        $mine = $this->fiche('apprenant-double', $opportunity);
        $this->assertSame(['sent', self::NOTE, true], [$mine['application']['status'], $mine['application']['note'], $mine['favorite']]);
        $logs = json_encode([AuditLog::all()->toArray(), DomainEvent::all()->toArray()], JSON_UNESCAPED_UNICODE);
        foreach (['lettre à mon enseignant', 'Pièce privée fictive', 'opportunity_application', 'favorite'] as $private) {
            $this->assertStringNotContainsString($private, $logs);
        }
        // Compte suspendu : plus d'accès.
        DB::table('users')->where('id', $this->user('apprenant-double')->id)->update(['status' => 'suspended']);
        $this->as('apprenant-double')->get('/opportunites/suivi')->assertRedirect('/connexion');
    }

    public function test_d17_02_after_archiving_the_application_keeps_a_minimal_dated_reference_and_no_copy_of_the_source(): void
    {
        $opportunity = $this->record('archivee', ['deadline_kind' => 'instant', 'deadline_local' => '2026-11-18T12:00', 'deadline_timezone' => 'Europe/Paris']);
        $suspect = $this->record('suspendue', ['deadline_kind' => 'date', 'deadline_date' => '2026-12-15']);
        $this->follow('apprenant-double', $opportunity, ['status' => 'preparing', 'note' => self::NOTE])->assertOk();
        $this->follow('apprenant-double', $suspect)->assertOk();
        $this->as('apprenant-double')->putJson('/opportunites/'.$opportunity->id.'/favori')->assertOk();
        $reference = OpportunityApplication::where('opportunity_id', $opportunity->id)->sole()->reference;
        $this->assertEqualsCanonicalizing(['title', 'organizer', 'campaign', 'category', 'deadline', 'sourceHost', 'verifiedAt', 'capturedAt'], array_keys($reference));
        $this->assertSame(['Bourse fictive archivee', 'Organisme fictif', '2026', 'Bourse', '18/11/2026 à 12:00 (Europe/Paris)', 'example.test', '2026-11-16T08:00:00+00:00'],
            [$reference['title'], $reference['organizer'], $reference['campaign'], $reference['category'], $reference['deadline'], $reference['sourceHost'], $reference['capturedAt']]);

        // La fiche change (titre corrigé), puis se clôt : le résumé est figé à l'archivage, daté, avec le dernier état connu.
        $this->at('2026-11-17 09:00');
        $this->editor()->patchJson('/administration/catalogue/'.$opportunity->id, ['expected_version' => 3, 'attest' => true, ...$this->payload('archivee', ['title' => 'Bourse fictive archivée (titre corrigé)', 'deadline_kind' => 'instant', 'deadline_local' => '2026-11-18T12:00', 'deadline_timezone' => 'Europe/Paris'])])->assertOk();
        $this->transition($suspect->id, 'suspend', ['note' => 'Motif interne fictif']);
        $this->at('2026-11-18 11:00');
        $this->artisan('campus:opportunities:archive-closed')->expectsOutputToContain('1 fiche(s) archivée(s).')->assertSuccessful();
        $application = OpportunityApplication::where('opportunity_id', $opportunity->id)->sole();
        $this->assertSame(['Bourse fictive archivée (titre corrigé)', '18/11/2026 à 12:00 (Europe/Paris)', '2026-11-18T11:00:00+00:00', 'preparing', self::NOTE, 1],
            [$application->reference['title'], $application->reference['deadline'], $application->reference['capturedAt'], $application->status, $application->note, $application->lock_version]);
        // Résumé minimal : ni conditions, ni résumé, ni adresse complète de la source.
        $stored = json_encode(DB::table('opportunity_applications')->get(), JSON_UNESCAPED_UNICODE);
        foreach (['Conditions fictives', 'à ne jamais recopier', 'Résumé fictif de la source', 'https://example.test/archivee', 'candidature.example.test', 'Motif interne'] as $copied) {
            $this->assertStringNotContainsString($copied, $stored);
        }
        $this->assertEqualsCanonicalizing(['title', 'organizer', 'campaign', 'category', 'deadline', 'sourceHost', 'verifiedAt', 'capturedAt'], array_keys($application->reference));

        // Après archivage : la démarche reste lisible et modifiable par son auteur ; le favori passe dans « expirés ».
        $props = $this->suivi('apprenant-double');
        $archived = collect($props['applications'])->firstWhere('opportunityId', $opportunity->id);
        $this->assertSame(['archived', 'Bourse fictive archivée (titre corrigé)', 'preparing', self::NOTE], [$archived['opportunityStatus'], $archived['reference']['title'], $archived['status'], $archived['note']]);
        $this->assertSame([$opportunity->id], array_column($props['favorites']['expired'], 'id'));
        $this->follow('apprenant-double', $opportunity, ['status' => 'sent', 'note' => self::NOTE])->assertOk()->assertJson(['version' => 2, 'status' => 'sent']);
        $this->assertSame('2026-11-18T11:00:00+00:00', $application->fresh()->reference['capturedAt']);
        $page = $this->fiche('apprenant-double', $opportunity);
        $this->assertSame(['archived', null, 'sent', false], [$page['opportunity']['availability'], $page['opportunity']['applicationUrl'], $page['application']['status'], $page['canFavorite']]);
        // Fiche suspendue : la démarche existante reste accessible à son auteur, avec son résumé figé à la suspension ; on n'en commence pas de nouvelle.
        $suspended = collect($props['applications'])->firstWhere('opportunityId', $suspect->id);
        $this->assertSame(['suspended', 'Bourse fictive suspendue', '15/12/2026 (heure non communiquée)', '2026-11-17T09:00:00+00:00'], [$suspended['opportunityStatus'], $suspended['reference']['title'], $suspended['reference']['deadline'], $suspended['reference']['capturedAt']]);
        $this->follow('apprenant-double', $suspect, ['status' => 'abandoned'])->assertOk();
        $this->follow('polyvalent', $suspect)->assertNotFound();
        $this->follow('polyvalent', $opportunity)->assertNotFound();
        $this->assertSame('suspended', $this->fiche('apprenant-double', $suspect)['application']['opportunityStatus']);
    }
}
