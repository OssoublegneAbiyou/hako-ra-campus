<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Accès de recette aux comptes fictifs, sur le poste de développement uniquement. La commande refuse tout autre environnement,
 * toute autre base, et ne touche que les comptes @example.test du jeu fictif. Le mot de passe n'est jamais affiché ni journalisé.
 */
class FixtureAccess extends Command
{
    private const ACCOUNTS = ['independant', 'apprenant-double', 'enseignant-a', 'admin-a', 'admin-b', 'editeur', 'polyvalent', 'plateforme'];

    protected $signature = 'campus:fixtures:access
        {--password-file= : Fichier, hors dépôt, contenant le mot de passe à donner aux comptes fictifs (sinon il est demandé sans écho)}
        {--lock : Referme l’accès : chaque compte fictif reçoit un mot de passe aléatoire inconnu}';

    protected $description = 'Poste de développement seulement : ouvre ou referme l’accès par mot de passe aux comptes fictifs @example.test, pour la recette manuelle ou en navigateur.';

    public function handle(): int
    {
        if (! app()->environment('local') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'hako_ra_campus_dev') {
            $this->error('Commande réservée à l’environnement local et à la base de développement fictive.');

            return self::FAILURE;
        }
        $users = User::query()->whereIn('email', array_map(fn (string $name) => $name.'@example.test', self::ACCOUNTS))->get();
        if ($users->count() !== count(self::ACCOUNTS)) {
            $this->error('Jeu fictif incomplet : lancez d’abord le seeder CampusDemoSeeder.');

            return self::FAILURE;
        }
        if ($this->option('lock')) {
            // forceFill : le mot de passe et la vérification ne sont pas des champs ouverts à l'affectation de masse.
            $users->each(fn (User $user) => $user->forceFill(['password' => Hash::make(Str::random(64))])->save());
            $this->info($users->count().' compte(s) fictif(s) refermé(s).');

            return self::SUCCESS;
        }
        $file = $this->option('password-file');
        $password = is_string($file) && $file !== '' ? (is_file($file) ? trim((string) file_get_contents($file)) : '') : (string) $this->secret('Mot de passe à donner aux comptes fictifs (12 caractères au moins)');
        if (is_string($file) && $file !== '' && str_starts_with((string) realpath($file), (string) realpath(base_path()))) {
            $this->error('Le fichier du mot de passe doit se trouver hors du dépôt.');

            return self::FAILURE;
        }
        if (mb_strlen($password) < 12) {
            $this->error('Mot de passe absent ou trop court (12 caractères au moins).');

            return self::FAILURE;
        }
        $users->each(fn (User $user) => $user->forceFill(['password' => Hash::make($password), 'email_verified_at' => $user->email_verified_at ?? now()])->save());
        $this->info($users->count().' compte(s) fictif(s) ouvert(s) avec le mot de passe fourni. Refermez avec --lock après la recette.');

        return self::SUCCESS;
    }
}
