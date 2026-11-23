<?php

namespace App\Console\Commands;

use App\Support\BackupCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle de la configuration avant ouverture. Il ne lit que des réglages et n'affiche jamais leur valeur : seulement « conforme au réglage attendu »
 * ou ce qui reste à corriger. Il ne remplace ni une revue de sécurité ni une validation juridique.
 */
class SecurityCheck extends Command
{
    protected $signature = 'campus:security:check';

    protected $description = 'Vérifie les réglages de sécurité attendus avant une ouverture (débogage, cookies, dérogations, services). N’affiche aucune valeur secrète.';

    public function handle(): int
    {
        $set = fn (string $key): bool => is_string(config($key)) && trim((string) config($key)) !== '';
        $blocking = [
            'Environnement « production »' => app()->environment('production'),
            'Mode de débogage désactivé (APP_DEBUG=false)' => config('app.debug') === false,
            'Clé de l’application définie' => $set('app.key'),
            'Adresse du site en HTTPS (APP_URL)' => str_starts_with((string) config('app.url'), 'https://'),
            'Cookie de session réservé à HTTPS (SESSION_SECURE_COOKIE=true)' => config('session.secure') === true,
            'Cookie de session inaccessible aux scripts' => config('session.http_only') === true,
            'Cookie de session limité au site (SameSite lax ou strict)' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'Session chiffrée (SESSION_ENCRYPT=true)' => config('session.encrypt') === true,
            'Dérogation sans second facteur désactivée (CAMPUS_PRIVILEGED_WITHOUT_MFA=false)' => config('campus.identity.privileged_without_mfa') === false,
            'Envoi de courriels configuré (pas « array » ni « log »)' => ! in_array(config('mail.default'), ['array', 'log', null], true),
            'File de traitement asynchrone (pas « sync »)' => config('queue.default') !== 'sync',
            'Analyse antivirus des fichiers configurée' => config('campus.attachments.scanner') !== 'none',
            'Notice : responsable du traitement renseigné' => $set('campus.privacy.controller'),
            'Notice : contact pour les droits renseigné' => $set('campus.privacy.contact'),
            'Notice : région d’hébergement renseignée' => $set('campus.privacy.hosting_region'),
            'Aucun compte fictif (@example.test) dans cette base' => ! DB::table('users')->where('email', 'like', '%@example.test')->exists(),
            'Sauvegardes : dossier et clé de chiffrement configurés' => $set('campus.backup.directory') && BackupCipher::configuredKey() !== null,
            'Supervision : jeton de la sonde externe configuré (32 caractères au moins)' => $set('campus.monitoring.token') && strlen((string) config('campus.monitoring.token')) >= 32,
        ];
        $advisory = [
            'Contact du support renseigné (CAMPUS_SUPPORT_EMAIL)' => $set('campus.support.email'),
            'Contact d’alerte de la supervision renseigné (CAMPUS_ALERT_CONTACT)' => $set('campus.monitoring.alert_contact'),
            'Dossier des sauvegardes hors de l’application (CAMPUS_BACKUP_DIRECTORY)' => $set('campus.backup.directory') && ! str_starts_with((string) realpath((string) config('campus.backup.directory')), (string) realpath(base_path())),
            'Journal des effacements hors du stockage de l’application (CAMPUS_ERASURE_JOURNAL_PATH)' => ! str_starts_with((string) config('campus.privacy.journal_path'), storage_path()),
            'Parcours des mineurs fermé, ou ouvert avec la référence d’un cadre validé' => config('campus.minors.enabled') !== true || $set('campus.minors.framework_reference'),
        ];
        $failed = 0;
        foreach ($blocking as $label => $ok) {
            $this->line(($ok ? 'OK          ' : 'À CORRIGER  ').$label);
            $failed += $ok ? 0 : 1;
        }
        foreach ($advisory as $label => $ok) {
            $this->line(($ok ? 'OK          ' : 'À VÉRIFIER  ').$label);
        }
        $this->line('');
        $this->line('Non contrôlé ici : activation effective du second facteur par chaque compte privilégié, en-têtes et HTTPS du serveur web pour les fichiers statiques, sauvegardes, validation juridique de la notice.');
        if ($failed > 0) {
            $this->error($failed.' réglage(s) bloquant(s) à corriger avant ouverture.');

            return self::FAILURE;
        }
        $this->info('Réglages bloquants conformes. Ce contrôle ne vaut pas autorisation d’ouvrir.');

        return self::SUCCESS;
    }
}
