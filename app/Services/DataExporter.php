<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Export des données personnelles d'un compte, pour ce compte seulement.
 *
 * Chaque table est lue par la colonne qui désigne son propriétaire, et seules des colonnes nommées ici sont écrites : ni mot de passe,
 * ni jeton, ni empreinte technique, ni nom de fichier de stockage, ni adresse de notification du navigateur. Rien d'un autre compte,
 * rien d'un établissement au-delà de ce qui concerne la personne (son rattachement, ses groupes, ses propres résultats publiés).
 */
class DataExporter
{
    private const DISK = 'local';

    /**
     * Construit le document exporté.
     *
     * @return array<string, mixed>
     */
    public function document(User $user): array
    {
        $id = $user->id;
        $rows = fn (string $table, string $ownerColumn, array $columns) => DB::table($table)->where($ownerColumn, $id)->orderBy($columns[0])->get($columns)->map(fn ($row) => (array) $row)->all();
        $json = fn (array $list, array $keys) => array_map(fn (array $row) => [...$row, ...array_map(fn ($value) => is_string($value) ? json_decode($value, true) : $value, array_intersect_key($row, array_flip($keys)))], $list);
        $memberships = DB::table('memberships')->leftJoin('institutions', 'institutions.id', '=', 'memberships.institution_id')->leftJoin('academic_years', 'academic_years.id', '=', 'memberships.academic_year_id')
            ->where('memberships.user_id', $id)->orderBy('memberships.id')
            ->get(['memberships.id', 'memberships.scope', 'memberships.role', 'memberships.status', 'memberships.local_identifier', 'memberships.starts_at', 'memberships.ends_at', 'memberships.revoked_at', 'institutions.name as institution', 'academic_years.name as academic_year']);
        $groups = fn (string $table) => DB::table($table)->join('groups', 'groups.id', '=', $table.'.group_id')->where($table.'.user_id', $id)->orderBy($table.'.id')
            ->get(['groups.name as group', $table.'.status', $table.'.starts_at', $table.'.ends_at', $table.'.closed_at'])->map(fn ($row) => (array) $row)->all();

        return [
            'export' => [
                'generated_at' => now()->utc()->toIso8601String(), 'format' => 'campus-export-1', 'notice_version' => PrivacyNotice::current()['version'],
                'scope' => 'Données personnelles du compte demandeur uniquement. Les fichiers joints ne sont pas inclus : ils se téléchargent depuis Campus tant que le compte existe.',
            ],
            'account' => (array) DB::table('users')->where('id', $id)->first(['name', 'email', 'login_identifier', 'status', 'created_at', 'email_verified_at', 'activated_at', 'adult_declared_at', 'password_changed_at', 'last_seen_at']),
            'linked_accounts' => $rows('social_identities', 'user_id', ['provider', 'email', 'created_at']),
            'personal_space' => $json($rows('personal_spaces', 'owner_id', ['timezone', 'preferences', 'created_at']), ['preferences']),
            'memberships' => $memberships->map(fn ($row) => collect((array) $row)->except('id')->all())->all(),
            'enrollments' => $groups('enrollments'),
            'teaching_assignments' => $groups('teaching_assignments'),
            'sessions' => array_map(fn (array $row) => [...$row, 'last_activity' => date(DATE_ATOM, (int) $row['last_activity'])], $rows('sessions', 'user_id', ['last_activity', 'ip_address', 'user_agent'])),
            'personal_subjects' => $rows('personal_subjects', 'owner_id', ['code', 'name', 'grade_coefficient', 'archived_at', 'created_at']),
            'personal_periods' => $rows('personal_periods', 'owner_id', ['code', 'name', 'starts_on', 'ends_on', 'timezone', 'weight', 'archived_at']),
            'personal_courses' => DB::table('course_occurrences')->where('scope', 'personal')->where('owner_id', $id)->orderBy('starts_at')->get(['title', 'starts_at', 'ends_at', 'timezone', 'room', 'meeting_url', 'status'])->map(fn ($row) => (array) $row)->all(),
            'course_check_ins' => $rows('course_check_ins', 'owner_id', ['course_starts_at', 'course_title', 'status', 'summary', 'work_to_do', 'points_to_review', 'understanding', 'settled_at', 'updated_at']),
            'tasks' => DB::table('tasks')->where('scope', 'personal')->where('owner_id', $id)->orderBy('created_at')->get(['title', 'description', 'status', 'priority', 'due_at', 'deleted_at', 'created_at', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
            'task_steps' => DB::table('task_steps')->join('tasks', 'tasks.id', '=', 'task_steps.task_id')->where('task_steps.owner_id', $id)->orderBy('task_steps.id')->get(['tasks.title as task', 'task_steps.title', 'task_steps.done'])->map(fn ($row) => (array) $row)->all(),
            'homework_progress' => DB::table('task_progress')->join('tasks', 'tasks.id', '=', 'task_progress.task_id')->where('task_progress.owner_id', $id)->orderBy('task_progress.id')->get(['tasks.title as homework', 'task_progress.status', 'task_progress.personal_target_at', 'task_progress.updated_at'])->map(fn ($row) => (array) $row)->all(),
            'personal_grades' => DB::table('personal_grades')->join('personal_subjects', 'personal_subjects.id', '=', 'personal_grades.personal_subject_id')->where('personal_grades.owner_id', $id)->orderBy('personal_grades.assessed_on')
                ->get(['personal_subjects.name as subject', 'personal_grades.title', 'personal_grades.status', 'personal_grades.value', 'personal_grades.scale', 'personal_grades.coefficient', 'personal_grades.assessed_on'])->map(fn ($row) => (array) $row)->all(),
            // Résultats qu'un établissement a publiés pour cette personne : uniquement les siens, uniquement s'ils sont publiés.
            'published_results' => DB::table('assessment_results')->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')->where('assessment_results.user_id', $id)->where('assessments.status', 'published')->orderBy('assessments.assessed_on')
                ->get(['assessments.title', 'assessments.assessed_on', 'assessments.scale', 'assessments.coefficient', 'assessment_results.status', 'assessment_results.value'])->map(fn ($row) => (array) $row)->all(),
            'attachments' => DB::table('attachments')->where('owner_id', $id)->whereNull('deleted_at')->orderBy('created_at')->get(['display_name', 'mime', 'size_bytes', 'visibility', 'scan_status', 'created_at'])->map(fn ($row) => (array) $row)->all(),
            'notification_preferences' => $json($rows('notification_preferences', 'user_id', ['timezone', 'quiet_start', 'quiet_end', 'course_end_delay_minutes', 'task_day_before', 'task_same_day', 'task_reminder_time', 'digest_enabled', 'digest_time', 'categories', 'favorite_first_days', 'favorite_second_days']), ['categories']),
            'notifications' => $rows('user_notifications', 'user_id', ['created_at', 'category', 'title', 'body', 'status', 'delivered_at', 'read_at']),
            'notification_devices' => $rows('push_subscriptions', 'user_id', ['created_at', 'device_label', 'last_accepted_at', 'revoked_at']),
            'opportunity_profile' => $json($rows('opportunity_profiles', 'user_id', ['level', 'fields', 'languages', 'residence_country', 'nationality', 'updated_at']), ['fields', 'languages']),
            'opportunity_favorites' => DB::table('opportunity_favorites')->join('opportunities', 'opportunities.id', '=', 'opportunity_favorites.opportunity_id')->where('opportunity_favorites.user_id', $id)->orderBy('opportunity_favorites.created_at')->get(['opportunities.title', 'opportunity_favorites.created_at'])->map(fn ($row) => (array) $row)->all(),
            'opportunity_applications' => DB::table('opportunity_applications')->join('opportunities', 'opportunities.id', '=', 'opportunity_applications.opportunity_id')->where('opportunity_applications.user_id', $id)->orderBy('opportunity_applications.created_at')
                ->get(['opportunity_applications.id', 'opportunities.title', 'opportunity_applications.status', 'opportunity_applications.personal_deadline_on', 'opportunity_applications.note', 'opportunity_applications.reference', 'opportunity_applications.status_changed_at'])
                ->map(fn ($row) => [...collect((array) $row)->except('id')->all(), 'items' => DB::table('opportunity_application_items')->where('opportunity_application_id', $row->id)->orderBy('position')->get(['label', 'done'])->map(fn ($item) => (array) $item)->all()])->all(),
            'saved_searches' => $json($rows('saved_searches', 'user_id', ['created_at', 'name', 'filters', 'frequency', 'email', 'push', 'active']), ['filters']),
            'privacy' => [
                'acknowledgements' => $rows('privacy_acknowledgements', 'user_id', ['created_at', 'notice_version', 'notice_hash']),
                'requests' => $rows('privacy_requests', 'user_id', ['created_at', 'type', 'status', 'message', 'response', 'closed_at']),
            ],
            // Ses propres actions tracées : leur nature et leur date, sans les métadonnées qui pourraient concerner d'autres personnes.
            'audit_trail' => DB::table('audit_logs')->where('actor_type', 'user')->where('actor_id', $id)->orderBy('created_at')->limit(5000)->get(['created_at', 'action'])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /**
     * Génère l'export, l'enregistre sur le stockage privé et renvoie sa fiche. Le fichier n'est téléchargeable que par son propriétaire, connecté,
     * jusqu'à son expiration.
     */
    public function create(User $user, ?PrivacyRequest $request = null): DataExport
    {
        $content = json_encode($this->document($user), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $export = new DataExport([
            'user_id' => $user->id, 'privacy_request_id' => $request?->id, 'stored_name' => Str::random(40).'.json', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'expires_at' => now()->addDays(max(1, (int) config('campus.privacy.export_ttl_days'))), 'created_at' => now(),
        ]);
        Storage::disk(self::DISK)->put($this->path($export), $content);
        $export->save();
        AuditLog::record($user, 'privacy.export.created', null, null, null, ['size_bytes' => $export->size_bytes]);

        return $export;
    }

    /**
     * Fichier d'un export encore valide de ce compte, ou null (inconnu, d'un autre compte, expiré ou retiré).
     */
    public function readable(User $user, string $exportId): ?DataExport
    {
        $export = DataExport::query()->where('user_id', $user->id)->whereNull('file_removed_at')->where('expires_at', '>', now())->find($exportId);

        return $export && Storage::disk(self::DISK)->exists($this->path($export)) ? $export : null;
    }

    public function path(DataExport $export): string
    {
        return 'exports/'.$export->user_id.'/'.$export->stored_name;
    }

    /**
     * Retire les fichiers des exports expirés, puis leurs fiches après le délai de conservation des fiches. Borné par passage.
     *
     * @return array{files: int, rows: int}
     */
    public function purge(int $limit): array
    {
        $files = 0;
        foreach (DataExport::query()->whereNull('file_removed_at')->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get() as $export) {
            Storage::disk(self::DISK)->delete($this->path($export));
            $export->forceFill(['file_removed_at' => now()])->save();
            $files++;
        }
        $rows = DataExport::query()->whereNotNull('file_removed_at')->where('expires_at', '<', now()->subDays(max(1, (int) config('campus.retention.export_row_days'))))->limit($limit)->delete();

        return ['files' => $files, 'rows' => $rows];
    }

    /**
     * Retire tous les exports d'un compte (suppression du compte) : fichiers et fiches.
     */
    public function forget(User $user): int
    {
        $exports = DataExport::query()->where('user_id', $user->id)->get();
        foreach ($exports as $export) {
            Storage::disk(self::DISK)->delete($this->path($export));
        }

        return DataExport::query()->where('user_id', $user->id)->delete();
    }
}
