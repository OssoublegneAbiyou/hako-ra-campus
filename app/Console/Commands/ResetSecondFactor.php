<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SecondFactors;
use App\Services\SessionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Récupération contrôlée : quand une personne a perdu son téléphone et ses codes de secours, l'exploitation retire son second facteur
 * après avoir vérifié son identité par un autre moyen. Le retrait est tracé, ferme les sessions du compte, et laisse les rôles
 * privilégiés fermés jusqu'à une nouvelle activation par la personne.
 */
class ResetSecondFactor extends Command
{
    protected $signature = 'campus:second-factor:reset {login : Adresse électronique ou identifiant HK- du compte} {--reason= : Motif, conservé dans l’audit (obligatoire)}';

    protected $description = 'Retire le second facteur d’un compte après vérification d’identité hors ligne. Tracé ; ferme les sessions du compte.';

    public function handle(SecondFactors $factors, SessionRegistry $sessions): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10) {
            $this->error('Indiquez --reason="…" (dix caractères au moins) : comment l’identité a été vérifiée.');

            return self::FAILURE;
        }
        $login = trim((string) $this->argument('login'));
        $user = str_contains($login, '@')
            ? User::query()->where('email', Str::lower($login))->first()
            : User::query()->where('login_identifier', Str::upper($login))->first();
        if ($user === null || ! $factors->remove($user, null, $reason)) {
            $this->error('Aucun second facteur à retirer pour ce compte.');

            return self::FAILURE;
        }
        $closed = $sessions->supported() ? $sessions->revokeAll($user) : 0;
        $this->info('Second facteur retiré, '.$closed.' session(s) fermée(s). La personne doit en activer un nouveau depuis son profil avant de retrouver ses rôles privilégiés.');

        return self::SUCCESS;
    }
}
