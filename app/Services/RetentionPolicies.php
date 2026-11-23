<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Politiques de conservation, toutes paramétrables (config/campus.php, « retention » et « privacy »). Chaque purge est bornée par passage,
 * ne journalise que des nombres, et ne supprime jamais un compte : l'inactivité ne donne lieu qu'à un signalement pour examen.
 *
 * Les autres durées ont déjà leur traitement : corbeille des tâches (campus:purge-tasks), fichiers bruts d'import
 * (campus:purge-import-files), pièces jointes (campus:attachments:maintain), accusés de synchronisation (campus:sync:prune).
 */
class RetentionPolicies
{
    public function __construct(private DataExporter $exports, private ErasureJournal $journal) {}

    /**
     * Durées en vigueur, pour l'affichage et la documentation.
     *
     * @return array<string, int>
     */
    public function durations(): array
    {
        return [
            'invitation_days' => max(1, (int) config('campus.retention.invitation_days')),
            'import_raw_days' => max(1, (int) config('campus.imports.raw_retention_days')),
            'notification_days' => max(1, (int) config('campus.retention.notification_days')),
            'audit_months' => max(1, (int) config('campus.retention.audit_months')),
            'task_trash_days' => max(1, (int) config('campus.tasks.trash_days')),
            'inactivity_months' => max(1, (int) config('campus.retention.inactivity_months')),
            'backup_days' => max(1, (int) config('campus.retention.backup_days')),
            'offline_days' => max(1, (int) config('campus.offline.ttl_days')),
            'export_days' => max(1, (int) config('campus.privacy.export_ttl_days')),
            'request_months' => max(1, (int) config('campus.retention.request_months')),
            'journal_days' => max((int) config('campus.privacy.journal_retention_days'), (int) config('campus.retention.backup_days') + 30),
        ];
    }

    /**
     * Applique les purges, au plus `$limit` lignes par catégorie. Renvoie des nombres seulement.
     *
     * @return array<string, int>
     */
    public function run(int $limit): array
    {
        $limit = max(1, $limit);
        $durations = $this->durations();
        $counts = [];

        // Invitations expirées ou traitées depuis plus longtemps que le délai : elles portent une adresse, parfois un matricule.
        $expiry = now()->subDays($durations['invitation_days']);
        $counts['invitations'] = DB::table('invitations')->where(fn ($query) => $query
            ->where(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<', $expiry))
            ->orWhere(fn ($closed) => $closed->where('status', '!=', 'pending')->where('updated_at', '<', $expiry)))
            ->orderBy('id')->limit($limit)->delete();

        // Notifications et traces de leurs envois.
        $old = DB::table('user_notifications')->where('created_at', '<', now()->subDays($durations['notification_days']))->orderBy('id')->limit($limit)->pluck('id');
        $counts['notification_deliveries'] = DB::table('notification_deliveries')->whereIn('user_notification_id', $old)->delete();
        $counts['notifications'] = DB::table('user_notifications')->whereIn('id', $old)->delete();

        // Journal d'audit : supprimé par la base, pas par le modèle, qui interdit toute suppression applicative.
        $counts['audit_logs'] = DB::table('audit_logs')->where('created_at', '<', now()->subMonths($durations['audit_months']))->orderBy('id')->limit($limit)->delete();

        // Exports expirés, puis demandes closes depuis longtemps.
        $exports = $this->exports->purge($limit);
        $counts['export_files'] = $exports['files'];
        $counts['export_rows'] = $exports['rows'];
        $closed = DB::table('privacy_requests')->whereNotNull('closed_at')->where('closed_at', '<', now()->subMonths($durations['request_months']))->orderBy('created_at')->limit($limit)->pluck('id');
        DB::table('data_exports')->whereIn('privacy_request_id', $closed)->update(['privacy_request_id' => null]);
        $counts['privacy_requests'] = DB::table('privacy_requests')->whereIn('id', $closed)->delete();

        $counts['journal_entries'] = $this->journal->prune();
        // Inactivité : un compte, jamais supprimé ici. Le nombre sert à déclencher un examen humain.
        $counts['inactive_accounts_to_review'] = $this->inactiveAccounts();

        $purged = collect($counts)->except('inactive_accounts_to_review')->filter();
        if ($purged->isNotEmpty()) {
            AuditLog::record(null, 'retention.purged', null, null, null, $purged->all());
        }

        return $counts;
    }

    /**
     * Nombre de comptes actifs sans activité connue depuis la durée de revue. Aucun identifiant n'est renvoyé.
     */
    public function inactiveAccounts(): int
    {
        $limit = now()->subMonths($this->durations()['inactivity_months']);

        return DB::table('users')->where('status', 'active')->whereRaw('COALESCE(last_seen_at, created_at) < ?', [$limit])->count();
    }
}
