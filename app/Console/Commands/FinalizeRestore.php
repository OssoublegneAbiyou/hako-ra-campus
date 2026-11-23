<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * À lancer une fois, juste après avoir mis en service une base restaurée, avant de relancer la file et le planificateur.
 *
 * 1. Les effacements de comptes et les retraits d'accès postérieurs à la sauvegarde sont appliqués de nouveau, et toutes les sessions fermées.
 * 2. Les anciens rappels sont neutralisés : un envoi externe encore « en file » dans la sauvegarde est peut-être déjà parti avant l'incident ;
 *    il est annulé plutôt que renvoyé. Un rappel dont l'échéance utile est passée expire.
 * 3. Les rappels encore valides restent programmés : le cycle normal les reprendra, une seule fois.
 * 4. Les traitements restés dans la file de la sauvegarde sont retirés : ils datent d'avant l'incident.
 */
class FinalizeRestore extends Command
{
    protected $signature = 'campus:restore:finalize {--keep-jobs : Conserver les traitements présents dans la file restaurée}';

    protected $description = 'Après restauration d’une sauvegarde : réapplique effacements et retraits, neutralise les anciens rappels, garde ceux encore valides.';

    public function handle(): int
    {
        $this->call('campus:privacy:reapply-journal');
        $now = now();
        $counts = DB::transaction(function () use ($now): array {
            return [
                // Envois externes non conclus au moment de la sauvegarde : annulés, jamais renvoyés.
                'deliveries_cancelled' => DB::table('notification_deliveries')->whereIn('status', ['queued', 'processing'])
                    ->update(['status' => 'cancelled', 'last_error' => 'restored_backup', 'finished_at' => $now, 'updated_at' => $now]),
                // Rappels dont la fenêtre utile est passée : expirés.
                'reminders_expired' => DB::table('user_notifications')->where('status', 'scheduled')->where('expires_at', '<=', $now)
                    ->update(['status' => 'expired', 'status_reason' => 'too_late', 'updated_at' => $now]),
                'reminders_kept' => DB::table('user_notifications')->where('status', 'scheduled')->where('expires_at', '>', $now)->count(),
            ];
        });
        $counts['jobs_removed'] = $this->option('keep-jobs') ? 0 : DB::table('jobs')->delete();
        // Battements de vie de l'ancienne instance : effacés, pour que la supervision ne voie que la nouvelle.
        Cache::forget('campus:runtime:worker');
        Cache::forget('campus:runtime:scheduler');
        AuditLog::record(null, 'restore.finalized', null, null, null, $counts);
        foreach ($counts as $label => $count) {
            $this->line($label.' : '.$count);
        }
        $this->info('Reprise prête : relancez la file et le planificateur. Les rappels encore valides partiront au prochain cycle, une seule fois.');

        return self::SUCCESS;
    }
}
