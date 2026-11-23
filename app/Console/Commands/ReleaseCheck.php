<?php

namespace App\Console\Commands;

use App\Jobs\RuntimeHeartbeat;
use App\Support\AppVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Contrôle technique juste après une mise en service : version, migrations, stockage privé, file et planificateur.
 * Il ne remplace pas le contrôle manuel (connexion, planning, tâche, fichier), dont la liste est rappelée en fin de sortie.
 */
class ReleaseCheck extends Command
{
    protected $signature = 'campus:release:check {--wait=90 : Secondes d’attente du processus de file}';

    protected $description = 'Après une mise en service : version, migrations en attente, stockage privé, processus de file, planificateur.';

    public function handle(): int
    {
        $failed = 0;
        $report = function (string $label, bool $ok, string $detail = '') use (&$failed): void {
            $this->line(($ok ? 'OK       ' : 'ÉCHEC    ').$label.($detail !== '' ? ' — '.$detail : ''));
            $failed += $ok ? 0 : 1;
        };
        $version = AppVersion::current();
        $report('Version en service identifiée', $version !== 'non versionnée', $version);

        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_values(array_diff(array_map(fn (string $file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []), $ran));
        $report('Aucune migration en attente', $pending === [], $pending === [] ? count($ran).' appliquées' : count($pending).' en attente');

        // Stockage privé : écriture, relecture, suppression d'un fichier témoin sans contenu utile.
        $probe = 'controle-mise-en-service-'.Str::random(12).'.txt';
        Storage::disk('local')->put($probe, 'ok');
        $stored = Storage::disk('local')->get($probe) === 'ok';
        Storage::disk('local')->delete($probe);
        $report('Stockage privé inscriptible', $stored && ! Storage::disk('local')->exists($probe));

        // File : un battement est déposé ; il n'est constaté que si un processus de file le traite réellement.
        Cache::forget('campus:runtime:worker');
        RuntimeHeartbeat::dispatch();
        $deadline = time() + max(5, (int) $this->option('wait'));
        while (! Cache::has('campus:runtime:worker') && time() < $deadline) {
            sleep(2);
        }
        $report('Processus de file actif', Cache::has('campus:runtime:worker'), Cache::has('campus:runtime:worker') ? 'battement traité' : 'aucun traitement en '.$this->option('wait').' s');
        $report('Planificateur actif', Cache::has('campus:runtime:scheduler'), Cache::has('campus:runtime:scheduler') ? 'battement de moins de 5 minutes' : 'aucun battement : la tâche planifiée chaque minute ne tourne pas');

        $this->line('');
        $this->line('À faire à la main, avec un compte de recette : se connecter ; ouvrir le planning ; créer une tâche puis la terminer ; joindre un fichier à cette tâche et le télécharger ; vérifier qu’un rappel arrive dans le centre de notifications.');
        if ($failed > 0) {
            $this->error($failed.' contrôle(s) en échec : ne pas ouvrir le service, appliquer la procédure d’échec (docs/exploitation-v1.md).');

            return self::FAILURE;
        }
        $this->info('Contrôles techniques réussis. Le contrôle manuel reste à faire.');

        return self::SUCCESS;
    }
}
