<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\Membership;
use App\Models\PersonalSubject;
use App\Models\PrivacyRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\ActiveContext;
use App\Services\Attachments;
use App\Services\ErasureJournal;
use App\Support\MinorsPathway;
use App\Support\PrivacyNotice;
use Database\Seeders\CampusDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot 22 : notices, droits des personnes, effacement, conservation et réapplication après restauration. Données fictives uniquement.
 */
class PrivacyTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Mot-de-passe-fictif-22';

    private const PRIVATE_TEXT = 'Texte privé fictif du lot 22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(8, 0));
        Storage::fake('local');
        $this->seed(CampusDemoSeeder::class);
        User::query()->update(['email_verified_at' => now()]);
        config(['campus.identity.privileged_without_mfa' => true]);
        foreach (['independant', 'apprenant-double', 'admin-a'] as $name) {
            $this->user($name)->forceFill(['password' => self::PASSWORD])->save();
        }
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function member(string $name, string $letter = 'A'): Membership
    {
        return $this->user($name)->memberships()->whereHas('institution', fn ($query) => $query->where('code', 'CAMPUS-FICTIF-'.$letter))->firstOrFail();
    }

    private function as(string $name, ?string $letter = null, bool $confirmed = false): static
    {
        return $this->freshDevice()->actingAs($this->user($name))->withSession([
            ...($letter ? [ActiveContext::SESSION_KEY => $this->member($name, $letter)->id] : []), ...($confirmed ? ['auth.password_confirmed_at' => now()->unix()] : []),
        ]);
    }

    private function platform(): static
    {
        $membership = $this->user('plateforme')->memberships()->where('scope', 'platform')->firstOrFail();

        return $this->freshDevice()->actingAs($this->user('plateforme'))->withSession([ActiveContext::SESSION_KEY => $membership->id]);
    }

    /** Donne au compte un espace individuel rempli, par les routes réelles des écrans. */
    private function fill(string $name): Task
    {
        $subject = PersonalSubject::factory()->create(['owner_id' => $this->user($name)->id, 'name' => 'Matière fictive privée']);
        $key = (string) Str::uuid();
        $this->as($name)->post('/taches', ['client_key' => $key, 'title' => 'Tâche '.self::PRIVATE_TEXT, 'description' => self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        $task = Task::where('client_key', $key)->firstOrFail();
        $this->as($name)->post('/notes', ['client_key' => (string) Str::uuid(), 'title' => 'Note '.self::PRIVATE_TEXT, 'personal_subject_id' => $subject->id, 'status' => 'graded', 'value' => '14', 'scale' => '20', 'coefficient' => '1', 'assessed_on' => '2026-11-10'])->assertSessionHasNoErrors();
        $course = ['request_key' => (string) Str::uuid(), 'title' => 'Cours '.self::PRIVATE_TEXT, 'kind' => 'once', 'valid_from' => '2026-11-16', 'valid_until' => '2026-11-16', 'start_time' => '06:00', 'end_time' => '07:00',
            'timezone' => 'Africa/Abidjan', 'weekdays' => [1], 'non_working_days' => [], 'excluded_dates' => [], 'active_weeks' => [], 'holidays' => [], 'group_ids' => []];
        $this->as($name)->post('/planning/series', $course)->assertSessionHasNoErrors();
        $occurrence = DB::table('course_occurrences')->where('owner_id', $this->user($name)->id)->value('id');
        $this->as($name)->put('/planning/occurrences/'.$occurrence.'/retour', ['action' => 'save', 'expected_version' => 0, 'summary' => 'Retour '.self::PRIVATE_TEXT])->assertSessionHasNoErrors();
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        $this->as($name)->post('/taches/'.$task->id.'/pieces-jointes', ['client_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('photo-fictive.png', (string) ob_get_clean())])->assertSessionHasNoErrors();
        $this->as($name)->putJson('/opportunites/profil', ['expected_version' => 0, 'level' => 'master', 'fields' => ['Physique'], 'languages' => [], 'residence_country' => null, 'nationality' => null])->assertOk();

        return $task;
    }

    public function test_the_notice_is_public_versioned_and_says_what_is_still_to_be_completed(): void
    {
        $this->freshDevice()->get('/confidentialite')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Privacy')
            ->where('notice.version', '2026-11-22')->where('current', '2026-11-22')->has('history', 1)->where('minorsOpen', false)
            ->where('notice.toComplete.0', 'Identité et coordonnées du responsable du traitement')->has('notice.toComplete', 8)->where('durations.audit_months', 12)->where('durations.export_days', 7));
        $notice = PrivacyNotice::current();
        $text = json_encode($notice['sections'], JSON_UNESCAPED_UNICODE);
        // Rien d'inventé : responsable, contact et hébergement sont marqués à compléter ; aucune affirmation de conformité.
        foreach (['Responsable du traitement : À compléter avant ouverture.', 'Contact pour vos droits : À compléter avant ouverture.', 'hors de votre pays : À compléter avant ouverture.'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        foreach (['conforme', 'RGPD', 'certifié', 'instantanément effacé'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
        $this->assertStringContainsString('pas instantanément', $text);
        $this->assertSame(64, strlen($notice['hash']));

        // Une fois fournies, les coordonnées s'affichent et sortent de la liste ; l'empreinte du texte ne change pas, les durées suivent la configuration.
        config(['campus.privacy.controller' => 'Organisme fictif de test', 'campus.privacy.contact' => 'droits@example.test', 'campus.privacy.hosting_region' => 'Région fictive', 'campus.retention.audit_months' => 6]);
        $filled = PrivacyNotice::current();
        $this->assertSame([5, $notice['hash']], [count($filled['toComplete']), $filled['hash']]);
        $this->assertStringContainsString('Responsable du traitement : Organisme fictif de test.', json_encode($filled['sections'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Journal des actions sensibles : 6 mois.', json_encode($filled['sections'], JSON_UNESCAPED_UNICODE));
        $this->freshDevice()->get('/confidentialite?version=1999-01-01')->assertNotFound();
        $this->freshDevice()->get('/confidentialite/parametres')->assertRedirect('/connexion');
    }

    public function test_reading_the_notice_leaves_a_dated_proof_bound_to_the_version_shown(): void
    {
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.privacyNoticePending', true));
        $this->freshDevice()->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.privacyNoticePending', false));
        $this->as('independant')->post('/confidentialite/notice', ['version' => '2025-01-01'])->assertSessionHasErrors('version');
        $this->assertSame(0, DB::table('privacy_acknowledgements')->count());

        $this->as('independant')->post('/confidentialite/notice', ['version' => '2026-11-22'])->assertSessionHasNoErrors();
        $this->as('independant')->post('/confidentialite/notice', ['version' => '2026-11-22'])->assertSessionHasNoErrors();
        $proof = DB::table('privacy_acknowledgements')->sole();
        $this->assertSame([$this->user('independant')->id, '2026-11-22', PrivacyNotice::current()['hash'], '2026-11-16 08:00:00'], [$proof->user_id, $proof->notice_version, $proof->notice_hash, $proof->created_at]);
        $this->as('independant')->get('/confidentialite/parametres')->assertOk()->assertInertia(fn (Assert $page) => $page->component('PrivacySettings')->where('notice.acknowledgedAt', '2026-11-16 08:00:00'));
        $this->as('independant')->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.privacyNoticePending', false));
        // La preuve est propre à chaque compte.
        $this->as('apprenant-double')->get('/')->assertInertia(fn (Assert $page) => $page->where('campus.privacyNoticePending', true));
    }

    public function test_r51_an_export_holds_only_the_requester_s_own_data_in_a_private_file_that_expires(): void
    {
        $this->fill('independant');
        $this->fill('apprenant-double');
        // Identité revérifiée : sans nouvelle saisie du mot de passe, pas d'export.
        $this->as('independant')->post('/confidentialite/exports')->assertRedirect('/confirmation-mot-de-passe');
        $this->freshDevice()->post('/confidentialite/exports')->assertRedirect('/connexion');
        $this->assertSame(0, DataExport::count());

        $this->as('independant', null, true)->post('/confidentialite/exports')->assertRedirect('/confidentialite/parametres')->assertSessionHas('status');
        $export = DataExport::sole();
        $owner = $this->user('independant');
        $this->assertSame([$owner->id, '2026-11-23 08:00:00', 0], [$export->user_id, $export->expires_at->format('Y-m-d H:i:s'), $export->downloads]);
        Storage::disk('local')->assertExists('exports/'.$owner->id.'/'.$export->stored_name);
        $request = PrivacyRequest::sole();
        $this->assertSame(['access', 'completed', '2026-11-16 08:00:00'], [$request->type, $request->status, $request->identity_verified_at->format('Y-m-d H:i:s')]);

        $content = Storage::disk('local')->get('exports/'.$owner->id.'/'.$export->stored_name);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['independant@example.test', 'Indépendant fictif'], [$data['account']['email'], $data['account']['name']]);
        $this->assertSame(['Tâche '.self::PRIVATE_TEXT], array_column($data['tasks'], 'title'));
        $this->assertSame(['Retour '.self::PRIVATE_TEXT], array_column($data['course_check_ins'], 'summary'));
        $this->assertSame([['Matière fictive privée', '14.000']], array_map(fn (array $grade) => [$grade['subject'], $grade['value']], $data['personal_grades']));
        $this->assertSame(['photo-fictive.png'], array_column($data['attachments'], 'display_name'));
        $this->assertSame(['Physique'], $data['opportunity_profile'][0]['fields']);
        $this->assertSame([], $data['memberships']);
        // Ni secret, ni empreinte technique, ni autre compte, ni établissement entier.
        foreach (['password"', 'remember_token', 'token_hash', 'activation_secret', 'stored_name', 'sha256', 'client_key', 'endpoint', 'payload', 'apprenant-double', 'Apprenant fictif', 'admin-a', 'Enseignant fictif'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content, $forbidden);
        }
        $this->assertStringNotContainsString($owner->password, $content);

        // Un compte rattaché reçoit son rattachement et ses groupes, pas la liste des membres ni le planning de l'établissement.
        $this->as('apprenant-double', null, true)->post('/confidentialite/exports')->assertSessionHasNoErrors();
        $member = json_decode(Storage::disk('local')->get('exports/'.$this->user('apprenant-double')->id.'/'.DataExport::where('user_id', $this->user('apprenant-double')->id)->sole()->stored_name), true);
        $this->assertEqualsCanonicalizing(['Établissement fictif A', 'Établissement fictif B'], array_column($member['memberships'], 'institution'));
        $this->assertNotEmpty($member['enrollments']);
        $this->assertStringNotContainsString('independant', json_encode($member));

        // Lien privé : le propriétaire connecté seulement, sans mise en cache.
        $url = '/confidentialite/exports/'.$export->id;
        $this->as('independant')->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'application/json');
        $this->assertSame(1, $export->fresh()->downloads);
        $this->as('apprenant-double')->get($url)->assertNotFound();
        $this->platform()->get($url)->assertNotFound();
        $this->freshDevice()->get($url)->assertRedirect('/connexion');
        $this->as('independant')->get('/confidentialite/exports/'.Str::uuid())->assertNotFound();

        // Lien expiré après sept jours : introuvable, puis fichier retiré par la purge, fiche marquée expirée.
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->as('independant')->get($url)->assertNotFound();
        $this->artisan('campus:retention:run')->assertSuccessful();
        Storage::disk('local')->assertMissing('exports/'.$owner->id.'/'.$export->stored_name);
        $this->assertNotNull($export->fresh()->file_removed_at);
        $this->as('independant')->get('/confidentialite/parametres')->assertInertia(fn (Assert $page) => $page->where('exports.0.available', false));

        // Trois exports au plus par vingt-quatre heures.
        foreach (range(1, 3) as $attempt) {
            $this->as('independant', null, true)->post('/confidentialite/exports')->assertSessionHasNoErrors();
        }
        $this->as('independant', null, true)->post('/confidentialite/exports')->assertSessionHasErrors('export');
        $this->assertSame(4, DataExport::where('user_id', $owner->id)->count());
    }

    public function test_rectification_and_objection_requests_are_tracked_and_handled_without_any_access_to_private_data(): void
    {
        $this->fill('independant');
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'erasure', 'message' => 'Demande fictive trop directe'])->assertSessionHasErrors('type');
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'rectification', 'message' => 'court'])->assertSessionHasErrors('message');
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'rectification', 'message' => 'Mon identifiant fictif est mal orthographié.'])->assertSessionHasNoErrors();
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'objection', 'message' => 'Je m’oppose à un traitement fictif.'])->assertSessionHasNoErrors();
        $rectification = PrivacyRequest::where('type', 'rectification')->sole();
        $objection = PrivacyRequest::where('type', 'objection')->sole();
        $this->as('independant')->get('/confidentialite/parametres')->assertInertia(fn (Assert $page) => $page->has('requests', 2)->where('requests.0.status', 'received')->where('requests.0.cancellable', true));

        // Suivi propre au demandeur ; annulation par lui seul.
        $this->as('apprenant-double')->get('/confidentialite/parametres')->assertInertia(fn (Assert $page) => $page->has('requests', 0));
        $this->as('apprenant-double')->post('/confidentialite/demandes/'.$objection->id.'/annulation')->assertNotFound();
        $this->as('independant')->post('/confidentialite/demandes/'.$objection->id.'/annulation')->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', true], [$objection->fresh()->status, $objection->fresh()->closed_at !== null]);

        // Traitement : administration de la plateforme seulement.
        $handle = '/administration/plateforme/demandes/'.$rectification->id.'/traitement';
        $this->as('independant')->get('/administration/plateforme/demandes')->assertForbidden();
        $this->as('admin-a', 'A')->get('/administration/plateforme/demandes')->assertForbidden();
        $this->as('admin-a', 'A')->post($handle, ['status' => 'completed', 'response' => 'Tentative fictive'])->assertForbidden();
        $page = $this->platform()->get('/administration/plateforme/demandes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/PrivacyRequests')->has('requests', 2)
            ->where('requests.0.requester', 'Indépendant fictif')->where('requests.0.message', 'Mon identifiant fictif est mal orthographié.')->where('requests.0.open', true)->where('inactiveAccounts', 0));
        // L'administration lit la demande, jamais l'espace individuel du demandeur.
        $this->assertStringNotContainsString(self::PRIVATE_TEXT, $page->getContent());
        $this->platform()->post($handle, ['status' => 'completed'])->assertSessionHasErrors('response');
        $this->platform()->post($handle, ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->assertSame(['in_progress', null], [$rectification->fresh()->status, $rectification->fresh()->closed_at]);
        $this->platform()->post($handle, ['status' => 'completed', 'response' => 'Identifiant fictif corrigé.'])->assertSessionHasNoErrors();
        $this->platform()->post($handle, ['status' => 'refused', 'response' => 'Seconde décision fictive'])->assertNotFound();
        $this->as('independant')->get('/confidentialite/parametres')->assertInertia(fn (Assert $page) => $page->where('requests.1.status', 'completed')->where('requests.1.response', 'Identifiant fictif corrigé.')->where('requests.1.cancellable', false));
        // Les demandes automatiques ne se traitent pas à la main ; cinq demandes ouvertes au plus.
        foreach (range(1, 5) as $index) {
            $this->as('independant')->post('/confidentialite/demandes', ['type' => 'objection', 'message' => 'Demande fictive numéro '.$index.' en cours.'])->assertSessionHasNoErrors();
        }
        $this->as('independant')->post('/confidentialite/demandes', ['type' => 'objection', 'message' => 'Sixième demande fictive en cours.'])->assertSessionHasErrors('message');
        $this->assertStringNotContainsString('orthographié', json_encode(AuditLog::query()->where('action', 'like', 'privacy.request.%')->get()));
    }

    public function test_r51_deleting_an_account_erases_the_private_space_revokes_every_access_and_keeps_only_a_contentless_trace(): void
    {
        $this->fill('apprenant-double');
        $this->fill('independant');
        $user = $this->user('apprenant-double');
        $id = $user->id;
        $this->as('apprenant-double', null, true)->post('/confidentialite/exports')->assertSessionHasNoErrors();
        $exportPath = 'exports/'.$id.'/'.DataExport::sole()->stored_name;
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $id, 'ip_address' => '192.0.2.10', 'user_agent' => 'Navigateur fictif', 'payload' => '', 'last_activity' => time()]);
        $this->assertGreaterThan(0, app(Attachments::class)->usage($user));
        $attachment = DB::table('attachments')->where('owner_id', $id)->first();

        // Écran des conséquences : mot de passe confirmé, export rappelé, établissements nommés.
        $this->as('apprenant-double')->get('/confidentialite/suppression')->assertRedirect('/confirmation-mot-de-passe');
        $this->as('apprenant-double', null, true)->get('/confidentialite/suppression')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AccountDeletion')
            ->where('blocker', null)->where('hasRecentExport', true)->where('backupDays', 30)->where('confirmationWord', 'SUPPRIMER')->has('memberships', 2));
        // Réauthentification dans la demande elle-même, et mot de confirmation.
        $this->as('apprenant-double', null, true)->delete('/confidentialite/compte', ['password' => 'mauvais', 'confirmation' => 'SUPPRIMER'])->assertSessionHasErrors('password');
        $this->as('apprenant-double', null, true)->delete('/confidentialite/compte', ['password' => self::PASSWORD, 'confirmation' => 'supprimer'])->assertSessionHasErrors('confirmation');
        $this->assertSame(['active', 'apprenant-double@example.test'], [$user->fresh()->status, $user->fresh()->email]);
        $this->assertSame([], app(ErasureJournal::class)->read()['entries']);

        $this->as('apprenant-double', null, true)->delete('/confidentialite/compte', ['password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER'])->assertRedirect('/confidentialite')->assertSessionHas('status');
        $this->assertGuest();
        $erased = User::findOrFail($id);
        $this->assertSame(['Compte supprimé', null, null, 'erased', null, null], [$erased->name, $erased->email, $erased->login_identifier, $erased->status, $erased->remember_token, $erased->adult_declared_at]);
        $this->assertNotNull($erased->erased_at);
        // Espace individuel effacé, table par table ; l'autre compte garde tout.
        foreach (['personal_spaces' => 'owner_id', 'tasks' => 'owner_id', 'personal_grades' => 'owner_id', 'personal_subjects' => 'owner_id', 'course_check_ins' => 'owner_id', 'course_occurrences' => 'owner_id', 'course_series' => 'owner_id',
            'attachments' => 'owner_id', 'opportunity_profiles' => 'user_id', 'user_notifications' => 'user_id', 'notification_preferences' => 'user_id', 'sessions' => 'user_id', 'data_exports' => 'user_id', 'sync_operations' => 'user_id', 'privacy_acknowledgements' => 'user_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, $id)->count(), $table);
        }
        $this->assertSame(1, DB::table('tasks')->where('owner_id', $this->user('independant')->id)->count());
        $this->assertSame(1, DB::table('course_check_ins')->where('owner_id', $this->user('independant')->id)->count());
        // Fichiers retirés du stockage ; compteur d'espace cohérent.
        Storage::disk('local')->assertMissing($exportPath);
        $this->assertSame(0, app(Attachments::class)->usage($erased));
        $this->assertSame([], collect(Storage::disk('local')->allFiles())->filter(fn (string $path) => str_contains($path, $attachment->stored_name))->all());
        // Accès révoqués : appartenances fermées, connexion impossible avec l'ancien mot de passe.
        $this->assertSame(['closed', 'closed'], Membership::where('user_id', $id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, DB::table('enrollments')->where('user_id', $id)->where('status', 'active')->count());
        $this->freshDevice()->post('/connexion', ['login' => 'apprenant-double@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors();
        $this->assertGuest();
        // Données de l'établissement : l'historique de rattachement reste, détaché de l'identité de connexion.
        $this->assertSame(2, Membership::where('user_id', $id)->count());
        $this->assertGreaterThan(0, DB::table('enrollments')->where('user_id', $id)->count());

        // Preuve du traitement : la demande reste, sans texte ; l'audit ne porte que des nombres ; le journal que des identifiants.
        $request = PrivacyRequest::where('user_id', $id)->where('type', 'erasure')->sole();
        $this->assertSame(['completed', null, true], [$request->status, $request->message, $request->identity_verified_at !== null]);
        $audit = AuditLog::where('action', 'privacy.account.erased')->sole();
        $this->assertSame([1, 1, 1, 2], [$audit->metadata['tasks'], $audit->metadata['check_ins'], $audit->metadata['attachments'], $audit->metadata['memberships_closed']]);
        $journal = app(ErasureJournal::class)->read();
        $this->assertSame([0, ['membership.revoked', 'membership.revoked', 'user.erased']], [$journal['rejected'], array_column($journal['entries'], 'kind')]);
        $raw = (string) file_get_contents(app(ErasureJournal::class)->path()).json_encode($audit->metadata).json_encode(AuditLog::where('created_at', '>=', now()->subMinute())->get());
        foreach ([self::PRIVATE_TEXT, 'apprenant-double', 'Apprenant fictif', 'photo-fictive'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, $forbidden);
        }
        // L'administration de la plateforme ne voit plus d'identité sur la demande.
        $this->platform()->get('/administration/plateforme/demandes')->assertInertia(fn (Assert $page) => $page->where('requests.0.requester', 'Compte supprimé')->where('requests.0.contact', null));

        // Une administration unique ne peut pas disparaître : la suppression est refusée avec le motif.
        $this->as('admin-a', null, true)->get('/confidentialite/suppression')->assertInertia(fn (Assert $page) => $page->where('blocker', 'Vous êtes la seule administration d’un établissement : une autre personne doit d’abord être habilitée, ou l’établissement archivé par la plateforme.'));
        $this->as('admin-a', null, true)->delete('/confidentialite/compte', ['password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER'])->assertSessionHasErrors('password');
        $this->assertSame('active', $this->user('admin-a')->status);
    }

    public function test_r52_preparation_after_a_restored_backup_erasures_and_revocations_are_applied_again(): void
    {
        $this->fill('apprenant-double');
        $user = $this->user('apprenant-double');
        $id = $user->id;
        $before = ['name' => $user->name, 'email' => $user->email, 'password' => $user->password];
        // Retrait d'une appartenance par son titulaire, puis suppression d'un autre compte.
        $left = $this->member('polyvalent', 'A');
        $this->as('polyvalent', null, true)->post('/profil/appartenances/'.$left->id.'/depart')->assertSessionHasNoErrors();
        $this->as('apprenant-double', null, true)->delete('/confidentialite/compte', ['password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER'])->assertSessionHasNoErrors();
        $journal = app(ErasureJournal::class);
        $this->assertSame(['membership.revoked', 'membership.revoked', 'membership.revoked', 'user.erased'], array_column($journal->read()['entries'], 'kind'));

        // Restauration simulée d'une sauvegarde antérieure : l'identité, les appartenances et une session réapparaissent dans la base.
        DB::table('users')->where('id', $id)->update([...$before, 'status' => 'active', 'erased_at' => null]);
        DB::table('memberships')->whereIn('id', [...Membership::where('user_id', $id)->pluck('id'), $left->id])->update(['status' => 'active', 'revoked_at' => null]);
        DB::table('personal_spaces')->insert(['owner_id' => $id, 'timezone' => 'Africa/Abidjan', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tasks')->insert(['id' => (string) Str::uuid(), 'scope' => 'personal', 'owner_id' => $id, 'title' => 'Tâche restaurée '.self::PRIVATE_TEXT, 'client_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $id, 'ip_address' => '192.0.2.10', 'user_agent' => 'Navigateur fictif', 'payload' => '', 'last_activity' => time()]);
        // Ligne ajoutée à la main dans le journal : sans signature valide, elle est ignorée.
        file_put_contents($journal->path(), json_encode(['id' => (string) Str::uuid(), 'kind' => 'user.erased', 'user_id' => $this->user('independant')->id, 'membership_id' => null, 'at' => now()->toIso8601String(), 'signature' => str_repeat('0', 64)])."\n", FILE_APPEND);

        $this->artisan('campus:privacy:reapply-journal')->expectsOutput('entries : 4')->expectsOutput('rejected : 1')->expectsOutput('accounts_erased : 1')->expectsOutput('memberships_closed : 3')->assertSuccessful();
        $again = User::findOrFail($id);
        $this->assertSame(['Compte supprimé', null, 'erased'], [$again->name, $again->email, $again->status]);
        $this->assertSame([0, 0, 0], [DB::table('tasks')->where('owner_id', $id)->count(), DB::table('personal_spaces')->where('owner_id', $id)->count(), DB::table('sessions')->count()]);
        $this->assertSame(['closed'], Membership::where('user_id', $id)->pluck('status')->unique()->values()->all());
        $this->assertSame('closed', $left->fresh()->status);
        // Le compte visé par la ligne falsifiée n'a pas été touché ; la réapplication n'ajoute rien au journal.
        $this->assertSame('active', $this->user('independant')->status);
        $this->assertSame(4, count($journal->read()['entries']));
        // Rejouer la commande ne refait rien.
        $this->artisan('campus:privacy:reapply-journal')->expectsOutput('accounts_erased : 0')->expectsOutput('memberships_closed : 0')->assertSuccessful();

        // Conservation du journal lui-même : jamais moins que la durée des sauvegardes plus trente jours ; au-delà, les entrées anciennes sont retirées.
        config(['campus.privacy.journal_retention_days' => 1]);
        $this->travel(59)->days();
        $this->assertSame(0, $journal->prune());
        $this->travel(2)->days();
        // Les quatre entrées et la ligne falsifiée, toutes anciennes, sont retirées.
        $this->assertSame(5, $journal->prune());
        $this->assertSame(['entries' => [], 'rejected' => 0], $journal->read());
    }

    public function test_retention_policies_are_configurable_bounded_and_never_delete_an_account_for_inactivity(): void
    {
        // Invitation créée puis expirée ; notification ancienne avec son envoi ; ligne d'audit ancienne.
        $this->as('admin-a', 'A', true)->post('/administration/invitations', ['role' => 'learner'])->assertSessionHasNoErrors();
        $this->as('admin-a', 'A', true)->post('/administration/invitations', ['role' => 'learner'])->assertSessionHasNoErrors();
        $owner = $this->user('independant')->id;
        foreach ([1, 2] as $index) {
            $notification = DB::table('user_notifications')->insertGetId(['user_id' => $owner, 'category' => 'tasks', 'type' => 'task.due', 'title' => 'Rappel fictif '.$index, 'url' => '/taches', 'dedup_key' => 'fictif-'.$index,
                'scheduled_for' => now(), 'expires_at' => now()->addDay(), 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('notification_deliveries')->insert(['user_notification_id' => $notification, 'channel' => 'email', 'target_key' => 'email', 'status' => 'accepted', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        AuditLog::record(null, 'fictif.ancien');
        $this->assertSame([2, 2], [DB::table('invitations')->count(), DB::table('user_notifications')->count()]);

        // Avant les délais : rien n'est purgé.
        $this->travel(40)->days();
        $this->artisan('campus:retention:run')->expectsOutput('invitations : 0')->expectsOutput('notifications : 0')->expectsOutput('audit_logs : 0')->assertSuccessful();
        // 14 jours de validité + 30 jours : les invitations partent ; purge bornée à une ligne par passage.
        $this->travel(5)->days();
        $this->artisan('campus:retention:run', ['--limit' => 1])->expectsOutput('invitations : 1')->assertSuccessful();
        $this->artisan('campus:retention:run', ['--limit' => 1])->expectsOutput('invitations : 1')->assertSuccessful();
        $this->assertSame(0, DB::table('invitations')->count());
        // 90 jours : notifications et traces d'envoi.
        $this->travel(46)->days();
        $this->artisan('campus:retention:run', ['--limit' => 1])->expectsOutput('notification_deliveries : 1')->expectsOutput('notifications : 1')->assertSuccessful();
        $this->artisan('campus:retention:run')->expectsOutput('notifications : 1')->assertSuccessful();
        $this->assertSame([0, 0], [DB::table('user_notifications')->count(), DB::table('notification_deliveries')->count()]);
        // La purge laisse une trace faite de nombres seulement.
        $trace = AuditLog::where('action', 'retention.purged')->orderByDesc('id')->firstOrFail();
        $this->assertEquals(['notification_deliveries' => 1, 'notifications' => 1], $trace->metadata);
        $this->assertStringNotContainsString('Rappel fictif', json_encode(AuditLog::where('action', 'retention.purged')->get()));

        // Durée paramétrable : audit ramené à trois mois, la ligne ancienne part ; à douze mois elle serait restée.
        $this->assertSame(1, AuditLog::where('action', 'fictif.ancien')->count());
        $this->travel(5)->days();
        $this->artisan('campus:retention:run')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('action', 'fictif.ancien')->count());
        config(['campus.retention.audit_months' => 3]);
        $this->artisan('campus:retention:run')->assertSuccessful();
        $this->assertSame(0, AuditLog::where('action', 'fictif.ancien')->count());

        // Inactivité : 24 mois sans activité donne un nombre à examiner, jamais une suppression.
        $this->as('independant')->get('/taches')->assertOk();
        $this->assertNotNull($this->user('independant')->last_seen_at);
        $this->travel(25)->months();
        $this->artisan('campus:retention:run')->expectsOutput('inactive_accounts_to_review : '.User::where('status', 'active')->count())->assertSuccessful();
        $this->assertSame(['active', 'independant@example.test'], [$this->user('independant')->status, $this->user('independant')->email]);
        $this->as('independant')->get('/taches')->assertOk();
        $this->platform()->get('/administration/plateforme/demandes')->assertInertia(fn (Assert $page) => $page->where('inactiveAccounts', User::where('status', 'active')->count() - 2)->where('durations.audit_months', 3)->where('durations.journal_days', 180));
    }

    public function test_the_minors_pathway_stays_closed_until_a_validated_framework_is_referenced(): void
    {
        $payload = ['name' => 'Personne fictive', 'email' => 'nouvelle-personne@example.test', 'password' => self::PASSWORD.'-A1', 'password_confirmation' => self::PASSWORD.'-A1'];
        // Fermé par défaut : sans déclaration de majorité, pas de compte.
        $this->assertFalse(MinorsPathway::isOpen());
        $this->freshDevice()->post('/inscription', $payload)->assertSessionHasErrors('adult_declaration');
        // Une simple activation ne suffit pas : il faut aussi la référence du cadre validé.
        config(['campus.minors.enabled' => true]);
        $this->assertFalse(MinorsPathway::isOpen());
        $this->freshDevice()->post('/inscription', $payload)->assertSessionHasErrors('adult_declaration');
        config(['campus.minors.framework_reference' => '   ']);
        $this->assertFalse(MinorsPathway::isOpen());
        config(['campus.minors.enabled' => false, 'campus.minors.framework_reference' => 'Référence fictive de test']);
        $this->assertFalse(MinorsPathway::isOpen());
        $this->assertSame(0, User::where('email', 'nouvelle-personne@example.test')->count());
        $this->freshDevice()->get('/confidentialite')->assertInertia(fn (Assert $page) => $page->where('minorsOpen', false));
        // Le contrôle technique : les deux conditions réunies ouvrent le parcours. Aucun âge ni règle n'est codé ici.
        config(['campus.minors.enabled' => true]);
        $this->assertTrue(MinorsPathway::isOpen());
        $this->assertStringContainsString('n’est pas ouverte', json_encode(PrivacyNotice::current()['sections'], JSON_UNESCAPED_UNICODE));
    }
}
