<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Suppression d'un compte à la demande de son titulaire.
 *
 * Effacé : tout l'espace individuel (planning personnel, tâches, notes personnelles, retours après cours, pièces jointes privées,
 * suivi d'opportunités, notifications, préférences, appareils, exports) et l'identité de connexion.
 * Conservé chez l'établissement, sous sa responsabilité et détaché de l'identité de connexion : l'historique de rattachement et ce que
 * l'établissement a lui-même produit ou publié (résultats, devoirs, planning, décisions). Cette conservation doit être justifiée par
 * l'établissement ; l'application ne décide pas de sa durée.
 * Révoqué : sessions, jetons de réinitialisation, appartenances actives.
 */
class AccountEraser
{
    public function __construct(private ErasureJournal $journal, private DataExporter $exports) {}

    /**
     * Motif qui empêche la suppression maintenant, ou null. Un établissement ou la plateforme ne doit pas rester sans administration.
     */
    public function blocker(User $user): ?string
    {
        foreach ($user->memberships()->effective()->whereIn('role', ['institution_admin', 'platform_admin'])->get() as $membership) {
            $others = Membership::query()->effective()->where('role', $membership->role)->where('user_id', '!=', $user->id)
                ->when($membership->role === 'institution_admin', fn ($query) => $query->where('institution_id', $membership->institution_id)->where('academic_year_id', $membership->academic_year_id))
                ->exists();
            if (! $others) {
                return $membership->role === 'platform_admin'
                    ? 'Vous êtes la seule administration de la plateforme : une autre personne doit d’abord être habilitée.'
                    : 'Vous êtes la seule administration d’un établissement : une autre personne doit d’abord être habilitée, ou l’établissement archivé par la plateforme.';
            }
        }

        return null;
    }

    /**
     * Efface le compte. `$replay` : réapplication après restauration d'une sauvegarde — même effacement, sans nouvelle entrée de journal
     * et sans contrôle d'administration unique (la décision a déjà été prise et tracée).
     *
     * @return array<string, int> Nombre de lignes effacées par catégorie ; aucun contenu.
     */
    public function erase(User $user, bool $replay = false): array
    {
        if (! $replay && ($blocker = $this->blocker($user)) !== null) {
            throw ValidationException::withMessages(['password' => $blocker]);
        }
        $files = [];
        $counts = DB::transaction(function () use ($user, $replay, &$files): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $id = $locked->id;
            $email = $locked->email;
            $counts = [];
            $delete = function (string $label, $query) use (&$counts): void {
                $counts[$label] = ($counts[$label] ?? 0) + $query->delete();
            };

            // Appartenances actives : fermées comme un retrait, et tracées pour pouvoir être refermées après une restauration.
            foreach (Membership::query()->where('user_id', $id)->where('status', '!=', 'closed')->lockForUpdate()->get() as $membership) {
                $membership->enrollments()->where('status', 'active')->get()->each->close();
                $membership->teachingAssignments()->where('status', 'active')->get()->each->close();
                $membership->revoke();
                $counts['memberships_closed'] = ($counts['memberships_closed'] ?? 0) + 1;
                if (! $replay) {
                    $membershipId = $membership->id;
                    // Écrit seulement une fois l'effacement réellement validé : une entrée sans effacement en provoquerait un à tort plus tard.
                    DB::afterCommit(fn () => $this->journal->record('membership.revoked', $id, $membershipId));
                }
            }

            // Pièces jointes personnelles : lignes effacées ici, fichiers retirés du stockage après validation de la transaction.
            $attachments = Attachment::query()->where('owner_id', $id)->where('visibility', 'personal')->get();
            $files = $attachments->filter(fn (Attachment $attachment) => $attachment->file_removed_at === null)->map(fn (Attachment $attachment) => $attachment->path())->all();
            $delete('attachments', DB::table('attachment_reports')->whereIn('attachment_id', $attachments->pluck('id')));
            $counts['attachments'] = DB::table('attachments')->whereIn('id', $attachments->pluck('id'))->delete();

            $notifications = DB::table('user_notifications')->where('user_id', $id)->pluck('id');
            $delete('notifications', DB::table('notification_deliveries')->whereIn('user_notification_id', $notifications));
            $counts['notifications'] = DB::table('user_notifications')->where('user_id', $id)->delete();
            $delete('devices', DB::table('push_subscriptions')->where('user_id', $id));
            $delete('preferences', DB::table('notification_preferences')->where('user_id', $id));

            $searches = DB::table('saved_searches')->where('user_id', $id)->pluck('id');
            $delete('opportunities', DB::table('saved_search_matches')->whereIn('saved_search_id', $searches));
            $delete('opportunities', DB::table('saved_searches')->where('user_id', $id));
            $applications = DB::table('opportunity_applications')->where('user_id', $id)->pluck('id');
            $delete('opportunities', DB::table('opportunity_application_items')->whereIn('opportunity_application_id', $applications));
            $delete('opportunities', DB::table('opportunity_applications')->where('user_id', $id));
            $delete('opportunities', DB::table('opportunity_favorites')->where('user_id', $id));
            $delete('opportunities', DB::table('opportunity_profiles')->where('user_id', $id));
            $delete('sync', DB::table('sync_operations')->where('user_id', $id));
            $delete('second_factor', DB::table('second_factors')->where('user_id', $id));
            $delete('linked_accounts', DB::table('social_identities')->where('user_id', $id));

            // Étapes et avancement personnels (y compris sur les devoirs d'un groupe), puis tâches personnelles.
            $delete('tasks', DB::table('task_steps')->where('owner_id', $id));
            $delete('tasks', DB::table('task_progress')->where('owner_id', $id));
            $counts['tasks'] = DB::table('tasks')->where('scope', 'personal')->where('owner_id', $id)->delete();
            $delete('grades', DB::table('personal_grades')->where('owner_id', $id));

            $checkIns = DB::table('course_check_ins')->where('owner_id', $id)->pluck('id');
            $delete('check_ins', DB::table('course_check_in_events')->whereIn('course_check_in_id', $checkIns));
            $counts['check_ins'] = DB::table('course_check_ins')->where('owner_id', $id)->delete();

            $occurrences = DB::table('course_occurrences')->where('scope', 'personal')->where('owner_id', $id)->pluck('id');
            $delete('courses', DB::table('course_occurrence_revisions')->whereIn('course_occurrence_id', $occurrences));
            $counts['courses'] = DB::table('course_occurrences')->where('scope', 'personal')->where('owner_id', $id)->delete();
            $series = DB::table('course_series')->where('scope', 'personal')->where('owner_id', $id)->pluck('id');
            $delete('courses', DB::table('course_series_revisions')->whereIn('course_series_id', $series));
            $delete('courses', DB::table('course_series')->where('scope', 'personal')->where('owner_id', $id));

            $delete('references', DB::table('personal_subjects')->where('owner_id', $id));
            DB::table('personal_periods')->where('owner_id', $id)->update(['parent_period_id' => null]);
            $delete('references', DB::table('personal_periods')->where('owner_id', $id));
            $delete('references', DB::table('academic_period_types')->where('owner_id', $id));

            // Exports : fichiers retirés. Demandes : le suivi (type, dates, état) reste comme preuve de traitement, sans leur texte.
            $counts['exports'] = $this->exports->forget($locked);
            $delete('privacy', DB::table('privacy_acknowledgements')->where('user_id', $id));
            DB::table('privacy_requests')->where('user_id', $id)->update(['message' => null, 'response' => null]);

            $delete('space', DB::table('personal_spaces')->where('owner_id', $id));
            $counts['sessions'] = DB::table('sessions')->where('user_id', $id)->delete();
            if ($email !== null) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }
            // Identité de connexion effacée : la ligne reste seulement pour que l'historique de l'établissement garde sa cohérence.
            $locked->forceFill([
                'name' => 'Compte supprimé', 'email' => null, 'login_identifier' => null, 'password' => Hash::make(Str::random(64)), 'remember_token' => null,
                'email_verified_at' => null, 'activation_secret_hash' => null, 'activation_expires_at' => null, 'activated_at' => null, 'adult_declared_at' => null,
                'password_changed_at' => null, 'last_seen_at' => null, 'status' => 'erased', 'erased_at' => now(),
            ])->save();
            if (! $replay) {
                DB::afterCommit(fn () => $this->journal->record('user.erased', $id));
            }
            // Trace de l'effacement : des nombres, jamais une copie de ce qui a été effacé.
            AuditLog::record(null, $replay ? 'privacy.account.erasure_reapplied' : 'privacy.account.erased', $locked, null, null, array_filter($counts));

            return $counts;
        }, 3);
        Storage::disk('local')->delete($files);

        return $counts;
    }
}
