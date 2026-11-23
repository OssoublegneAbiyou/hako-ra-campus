<?php

namespace App\Services;

use App\Support\AppVersion;
use App\Support\Scanners\ClamdScanner;
use App\Support\Scanners\Scanner;
use App\Support\Vapid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Contrôles de supervision, un par composant. Chacun répond pour lui-même : une page d'accueil qui s'affiche ne dit rien de la file,
 * du planificateur, des rappels ou des sauvegardes. Aucun contrôle ne renvoie de donnée de compte ni de secret.
 *
 * États : « ok », « alert » (seuil dépassé ou composant muet), « unconfigured » (composant non configuré : à traiter avant ouverture).
 */
class HealthChecks
{
    public const CHECKS = ['database', 'queue', 'worker', 'scheduler', 'backup', 'scanner', 'disk', 'certificate', 'deliveries'];

    public function __construct(private Backups $backups) {}

    /**
     * @param  list<string>|null  $only
     * @return array{version: string, checked_at: string, healthy: bool, checks: array<string, array{label: string, status: string, detail: string}>}
     */
    public function run(?array $only = null): array
    {
        $checks = [];
        foreach ($only ?? self::CHECKS as $name) {
            if (in_array($name, self::CHECKS, true)) {
                try {
                    $checks[$name] = $this->{$name}();
                } catch (\Throwable) {
                    // Un contrôle qui échoue lui-même est une alerte ; le détail technique reste dans les journaux, pas dans la réponse.
                    $checks[$name] = ['label' => $name, 'status' => 'alert', 'detail' => 'Le contrôle n’a pas pu s’exécuter.'];
                }
            }
        }

        return ['version' => AppVersion::current(), 'checked_at' => now()->utc()->toIso8601String(),
            'healthy' => collect($checks)->every(fn (array $check) => $check['status'] === 'ok'), 'checks' => $checks];
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function result(string $label, ?bool $ok, string $detail): array
    {
        return ['label' => $label, 'status' => $ok === null ? 'unconfigured' : ($ok ? 'ok' : 'alert'), 'detail' => $detail];
    }

    private function database(): array
    {
        $started = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $started) * 1000);
        $max = (int) config('campus.monitoring.database_max_ms');

        return $this->result('Base de données', $ms <= $max, 'Réponse en '.$ms.' ms (seuil '.$max.' ms).');
    }

    private function queue(): array
    {
        $waiting = DB::table('jobs')->where('available_at', '<=', now()->timestamp)->count();
        $oldest = DB::table('jobs')->where('available_at', '<=', now()->timestamp)->min('available_at');
        $age = $oldest === null ? 0 : (int) floor((now()->timestamp - (int) $oldest) / 60);
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $maxAge = (int) config('campus.monitoring.queue_max_minutes');
        $maxFailed = (int) config('campus.monitoring.failed_jobs_max');

        return $this->result('File de traitement', $age <= $maxAge && $failed <= $maxFailed,
            $waiting.' traitement(s) en attente, le plus ancien depuis '.$age.' min (seuil '.$maxAge.' min) ; '.$failed.' échec(s) en 24 h (seuil '.$maxFailed.').');
    }

    private function heartbeat(string $label, string $key): array
    {
        $seen = Cache::get($key);
        $max = (int) config('campus.monitoring.heartbeat_max_minutes');
        if (! is_string($seen)) {
            return $this->result($label, false, 'Aucun battement reçu depuis plus de 5 minutes : le composant ne tourne pas.');
        }
        $age = (int) CarbonImmutable::parse($seen)->diffInMinutes(now(), true);

        return $this->result($label, $age <= $max, 'Dernier battement il y a '.$age.' min (seuil '.$max.' min).');
    }

    private function worker(): array
    {
        return $this->heartbeat('Processus de file (rappels, analyses, imports)', 'campus:runtime:worker');
    }

    private function scheduler(): array
    {
        return $this->heartbeat('Planificateur (tâches périodiques)', 'campus:runtime:scheduler');
    }

    private function backup(): array
    {
        if ($this->backups->directory() === null) {
            return $this->result('Sauvegarde quotidienne', null, 'Aucun dossier de sauvegarde configuré.');
        }
        $last = $this->backups->lastSuccess();
        $max = (int) config('campus.monitoring.backup_max_hours');
        if ($last === null) {
            return $this->result('Sauvegarde quotidienne', false, 'Aucune sauvegarde réussie enregistrée.');
        }
        $age = (int) $last->diffInHours(now(), true);

        return $this->result('Sauvegarde quotidienne', $age <= $max, 'Dernière réussite il y a '.$age.' h (seuil '.$max.' h).');
    }

    private function scanner(): array
    {
        $scanner = app(Scanner::class);
        if (! $scanner instanceof ClamdScanner) {
            return $this->result('Antivirus des fichiers', null, 'Aucun antivirus configuré : aucune pièce jointe ne peut être diffusée.');
        }

        return $this->result('Antivirus des fichiers', $scanner->available(), $scanner->available() ? 'Le démon répond.' : 'Le démon ne répond pas : les fichiers restent bloqués.');
    }

    private function disk(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! $total || $free === false) {
            return $this->result('Espace disque du stockage', false, 'Espace disque illisible.');
        }
        $percent = (int) floor($free / $total * 100);
        $min = (int) config('campus.monitoring.disk_min_free_percent');

        return $this->result('Espace disque du stockage', $percent >= $min, $percent.' % libre (seuil '.$min.' %).');
    }

    /**
     * Certificat HTTPS du site lui-même (nom de APP_URL) : jours restants avant expiration.
     */
    private function certificate(): array
    {
        $url = parse_url((string) config('app.url'));
        if (($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
            return $this->result('Certificat HTTPS', null, 'Le site n’est pas déclaré en HTTPS (APP_URL) : aucun certificat à contrôler.');
        }
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $client = @stream_socket_client('ssl://'.$url['host'].':'.($url['port'] ?? 443), $errorNumber, $errorText, 5, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return $this->result('Certificat HTTPS', false, 'Connexion HTTPS impossible ou certificat refusé.');
        }
        $certificate = openssl_x509_parse(stream_context_get_params($client)['options']['ssl']['peer_certificate']);
        fclose($client);
        $days = (int) floor(((int) ($certificate['validTo_time_t'] ?? 0) - now()->timestamp) / 86400);
        $min = (int) config('campus.monitoring.certificate_min_days');

        return $this->result('Certificat HTTPS', $days >= $min, 'Expire dans '.$days.' jour(s) (seuil '.$min.' jours).');
    }

    /**
     * Volumes d'envoi des dernières 24 heures : échecs, envois indéterminés, et part du plafond quotidien du service de courriel s'il est renseigné.
     */
    private function deliveries(): array
    {
        $mail = ! in_array(config('mail.default'), ['array', 'log', null], true);
        if (! $mail && ! Vapid::configured()) {
            return $this->result('Envois (courriel, notifications du navigateur)', null, 'Aucun canal d’envoi externe configuré : seul le centre de notifications fonctionne.');
        }
        $counts = DB::table('notification_deliveries')->where('updated_at', '>=', now()->subDay())->selectRaw('status, channel, count(*) as total')->groupBy('status', 'channel')->get();
        $sum = fn (callable $filter): int => (int) $counts->filter($filter)->sum('total');
        $accepted = $sum(fn ($row) => $row->status === 'accepted');
        $troubled = $sum(fn ($row) => in_array($row->status, ['failed', 'uncertain'], true));
        $emails = $sum(fn ($row) => $row->status === 'accepted' && $row->channel === 'email');
        $ratio = $accepted + $troubled === 0 ? 0 : (int) round($troubled / ($accepted + $troubled) * 100);
        $maxRatio = (int) config('campus.monitoring.delivery_failure_percent');
        $limit = config('campus.monitoring.mail_daily_limit');
        $quota = is_numeric($limit) && (int) $limit > 0 ? (int) round($emails / (int) $limit * 100) : null;
        $ok = ($accepted + $troubled < 5 || $ratio <= $maxRatio) && ($quota === null || $quota < 80);

        return $this->result('Envois (courriel, notifications du navigateur)', $ok, $accepted.' accepté(s), '.$troubled.' en échec ou indéterminé(s) en 24 h ('.$ratio.' %, seuil '.$maxRatio.' %)'
            .($quota === null ? ' ; plafond quotidien du service de courriel non renseigné.' : ' ; '.$quota.' % du plafond quotidien de courriels (alerte à 80 %).'));
    }
}
