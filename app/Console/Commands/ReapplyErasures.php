<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use App\Services\AccountEraser;
use App\Services\ErasureJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * À lancer après la restauration d'une sauvegarde : applique de nouveau les effacements de comptes et les retraits d'appartenance
 * enregistrés dans le journal depuis la date de la sauvegarde, puis ferme toutes les sessions et tous les jetons de réinitialisation
 * (une sauvegarde restaurée peut contenir des sessions fermées depuis).
 */
class ReapplyErasures extends Command
{
    protected $signature = 'campus:privacy:reapply-journal {--keep-sessions : Ne pas fermer les sessions ni les jetons (à éviter après une restauration)}';

    protected $description = 'Après restauration d’une sauvegarde : réapplique les effacements et retraits d’accès du journal, puis ferme les sessions.';

    public function handle(ErasureJournal $journal, AccountEraser $eraser): int
    {
        $read = $journal->read();
        $counts = ['entries' => count($read['entries']), 'rejected' => $read['rejected'], 'accounts_erased' => 0, 'memberships_closed' => 0, 'sessions_closed' => 0];
        foreach ($read['entries'] as $entry) {
            if ($entry['kind'] === 'user.erased') {
                $user = User::query()->find($entry['user_id']);
                if ($user !== null && ($user->status !== 'erased' || $user->erased_at === null)) {
                    $eraser->erase($user, replay: true);
                    $counts['accounts_erased']++;
                }
            } elseif ($entry['membership_id'] !== null) {
                $membership = Membership::query()->where('user_id', $entry['user_id'])->find($entry['membership_id']);
                if ($membership !== null && $membership->status !== 'closed') {
                    DB::transaction(function () use ($membership): void {
                        $membership->enrollments()->where('status', 'active')->get()->each->close();
                        $membership->teachingAssignments()->where('status', 'active')->get()->each->close();
                        $membership->revoke();
                    });
                    $counts['memberships_closed']++;
                }
            }
        }
        if (! $this->option('keep-sessions')) {
            $counts['sessions_closed'] = DB::table('sessions')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
        }
        AuditLog::record(null, 'privacy.journal.reapplied', null, null, null, $counts);
        // Des nombres seulement : aucun identifiant de compte dans la sortie.
        foreach ($counts as $label => $count) {
            $this->line($label.' : '.$count);
        }
        if ($read['rejected'] > 0) {
            $this->warn('Des lignes du journal ont été ignorées : illisibles ou à la signature incorrecte.');
        }

        return self::SUCCESS;
    }
}
