<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identité et sessions
    |--------------------------------------------------------------------------
    |
    | Paramètres proposés, à valider par le porteur. Les actions privilégiées
    | exigent le second facteur du compte. Le réglage "privileged_without_mfa"
    | n'en dispense qu'en local et en test, sur des données fictives.
    |
    */

    'identity' => [
        'registration_enabled' => (bool) env('CAMPUS_REGISTRATION_ENABLED', true),
        'activation_ttl_hours' => (int) env('CAMPUS_ACTIVATION_TTL_HOURS', 72),
        'login_max_attempts' => 5,
        'login_decay_seconds' => 60,
        'activation_max_attempts' => 5,
        'activation_decay_seconds' => 900,
        'recovery_max_requests' => 5,
        'privileged_without_mfa' => (bool) env('CAMPUS_PRIVILEGED_WITHOUT_MFA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | Durée de validité proposée d'un lien d'invitation, en jours.
    |
    */

    'invitations' => [
        'ttl_days' => (int) env('CAMPUS_INVITATION_TTL_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Planning
    |--------------------------------------------------------------------------
    |
    | Nombre maximal d'occurrences d'une année qu'une publication traite en une
    | fois. Valeur proposée, non mesurée en charge.
    |
    */

    'schedule' => [
        'publication_max_occurrences' => (int) env('CAMPUS_PUBLICATION_MAX_OCCURRENCES', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Excel du planning
    |--------------------------------------------------------------------------
    |
    | Valeurs proposées par le cahier des charges (5 Mo, 5 000 lignes, purge du
    | fichier brut après 30 jours). Les limites de décompression protègent le
    | serveur contre une archive piégée.
    |
    */

    'imports' => [
        'max_kilobytes' => (int) env('CAMPUS_IMPORT_MAX_KB', 5120),
        'max_rows' => (int) env('CAMPUS_IMPORT_MAX_ROWS', 5000),
        'max_sheets' => 3,
        'max_archive_entries' => 200,
        'max_uncompressed_bytes' => 60 * 1024 * 1024,
        'max_stored_issues' => 2000,
        'raw_retention_days' => (int) env('CAMPUS_IMPORT_RAW_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retour après cours
    |--------------------------------------------------------------------------
    |
    | Nombre de jours pendant lesquels un cours terminé sans retour reste
    | proposé dans l'application. Valeur proposée.
    |
    */

    'check_ins' => [
        'pending_days' => (int) env('CAMPUS_CHECK_IN_PENDING_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tâches
    |--------------------------------------------------------------------------
    |
    | Durée proposée de la corbeille avant purge, et nombre maximal de
    | microtâches par tâche et par personne.
    |
    */

    'tasks' => [
        'trash_days' => (int) env('CAMPUS_TASK_TRASH_DAYS', 7),
        'max_steps' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notes et moyennes
    |--------------------------------------------------------------------------
    |
    | Seuil proposé, sur 20, sous lequel une matière est signalée « à
    | surveiller ». Simple repère explicable, sans autre interprétation.
    |
    */

    'grades' => [
        'watch_threshold' => (string) env('CAMPUS_GRADE_WATCH_THRESHOLD', '10'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rappels et canaux
    |--------------------------------------------------------------------------
    |
    | Durées de validité (un rappel non remis avant ce délai expire au lieu
    | d'être envoyé en retard), reprises bornées et canaux externes. Le
    | courriel et le Web Push restent désactivés tant qu'ils ne sont pas
    | configurés ; le centre interne fonctionne toujours. Les hôtes Web Push
    | sont une liste fermée : aucune autre adresse n'est jamais appelée.
    |
    */

    'notifications' => [
        'course_reminder_ttl_hours' => 6,
        'task_reminder_ttl_hours' => 12,
        'event_ttl_hours' => 48,
        'digest_ttl_hours' => 3,
        'opportunity_reminder_ttl_hours' => 24,
        'max_attempts' => 4,
        'backoff_seconds' => [60, 300, 900],
        'processing_timeout_minutes' => 10,
        'batch' => 200,
        'email_enabled' => (bool) env('CAMPUS_NOTIFICATION_EMAIL_ENABLED', false),
        'push_private_key' => env('CAMPUS_PUSH_PRIVATE_KEY'),
        'push_subject' => env('CAMPUS_PUSH_SUBJECT'),
        'push_timeout_seconds' => 10,
        'push_hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes
    |--------------------------------------------------------------------------
    |
    | Limites proposées par le cahier des charges. L'antivirus est « none »
    | par défaut : dans ce cas aucune analyse n'aboutit et aucun fichier
    | n'est diffusé. « clamd » désigne un démon ClamAV joignable en TCP,
    | à l'adresse fixée par l'exploitant.
    |
    */

    'attachments' => [
        'max_file_kilobytes' => (int) env('CAMPUS_ATTACHMENT_MAX_KB', 5120),
        'max_files_per_object' => (int) env('CAMPUS_ATTACHMENT_MAX_PER_OBJECT', 3),
        'max_user_megabytes' => (int) env('CAMPUS_ATTACHMENT_USER_QUOTA_MB', 100),
        'max_image_pixels' => 40_000_000,
        'scanner' => env('CAMPUS_ATTACHMENT_SCANNER', 'none'),
        'clamd_host' => env('CAMPUS_CLAMD_HOST', '127.0.0.1'),
        'clamd_port' => (int) env('CAMPUS_CLAMD_PORT', 3310),
        'scan_timeout_seconds' => 30,
        'scan_stale_minutes' => 10,
        'deleted_row_retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Opportunités
    |--------------------------------------------------------------------------
    |
    | Nombre de jours avant la clôture connue à partir duquel un favori est
    | classé « bientôt clos ». Valeur proposée.
    |
    */

    'opportunities' => [
        'closing_soon_days' => (int) env('CAMPUS_OPPORTUNITY_CLOSING_SOON_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lecture hors connexion
    |--------------------------------------------------------------------------
    |
    | Nombre de jours sans contrôle des droits au-delà duquel la copie de
    | lecture conservée sur un appareil personnel est effacée à l'ouverture.
    | Valeur proposée.
    |
    */

    'support' => [
        // Adresse affichée sur la page d'aide. Vide : la page indique qu'aucun contact n'est encore configuré.
        'email' => env('CAMPUS_SUPPORT_EMAIL'),
    ],

    'offline' => [
        'ttl_days' => (int) env('CAMPUS_OFFLINE_TTL_DAYS', 7),
        // Durée de conservation des accusés de synchronisation (aucun contenu saisi n'y figure).
        'sync_retention_days' => (int) env('CAMPUS_SYNC_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parcours des mineurs
    |--------------------------------------------------------------------------
    |
    | Fermé par défaut. Aucun seuil d'âge n'est codé : l'ouverture exige à la
    | fois l'activation et la référence du cadre validé (arbitrage A06).
    |
    */

    'minors' => [
        'enabled' => (bool) env('CAMPUS_MINORS_ENABLED', false),
        'framework_reference' => env('CAMPUS_MINORS_FRAMEWORK_REFERENCE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidentialité
    |--------------------------------------------------------------------------
    |
    | Responsable, contact et hébergement : vides tant qu'ils n'ont pas été fournis.
    | La notice les affiche alors « à compléter avant ouverture » ; rien n'est inventé.
    | Le journal des effacements doit être placé sur un stockage distinct de la base
    | de données et sauvegardé séparément : il sert après une restauration.
    |
    */

    'privacy' => [
        'controller' => env('CAMPUS_PRIVACY_CONTROLLER'),
        'contact' => env('CAMPUS_PRIVACY_CONTACT'),
        'hosting_region' => env('CAMPUS_HOSTING_REGION'),
        'export_ttl_days' => (int) env('CAMPUS_EXPORT_TTL_DAYS', 7),
        'journal_path' => env('CAMPUS_ERASURE_JOURNAL_PATH', storage_path('app/private/journal/erasures.jsonl')),
        'journal_retention_days' => (int) env('CAMPUS_ERASURE_JOURNAL_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Durées de conservation proposées
    |--------------------------------------------------------------------------
    |
    | Toutes modifiables. Les sauvegardes elles-mêmes relèvent du lot 24 : la durée
    | inscrite ici est celle annoncée dans la notice et utilisée comme plancher pour
    | la conservation du journal des effacements.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sauvegardes
    |--------------------------------------------------------------------------
    |
    | Dossier hors de l'instance principale (volume distant monté, par exemple) et
    | clé de chiffrement conservée à part. Sans les deux, aucune sauvegarde n'est
    | planifiée. Les outils MySQL doivent être accessibles au compte de l'application.
    |
    */

    'backup' => [
        'directory' => env('CAMPUS_BACKUP_DIRECTORY'),
        'key' => env('CAMPUS_BACKUP_KEY'),
        'mysqldump' => env('CAMPUS_MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('CAMPUS_MYSQL_PATH', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervision
    |--------------------------------------------------------------------------
    |
    | Seuils proposés, à ajuster avec les moyens réels. Le jeton ouvre le point
    | /sante à une sonde externe ; sans jeton, ce point est fermé. Le contact
    | d'alerte est à renseigner : il n'est pas inventé.
    |
    */

    'monitoring' => [
        'token' => env('CAMPUS_MONITORING_TOKEN'),
        'alert_contact' => env('CAMPUS_ALERT_CONTACT'),
        'database_max_ms' => (int) env('CAMPUS_MONITORING_DATABASE_MAX_MS', 500),
        'queue_max_minutes' => (int) env('CAMPUS_MONITORING_QUEUE_MAX_MINUTES', 10),
        'failed_jobs_max' => (int) env('CAMPUS_MONITORING_FAILED_JOBS_MAX', 0),
        'heartbeat_max_minutes' => (int) env('CAMPUS_MONITORING_HEARTBEAT_MAX_MINUTES', 3),
        'backup_max_hours' => (int) env('CAMPUS_MONITORING_BACKUP_MAX_HOURS', 26),
        'disk_min_free_percent' => (int) env('CAMPUS_MONITORING_DISK_MIN_FREE_PERCENT', 15),
        'certificate_min_days' => (int) env('CAMPUS_MONITORING_CERTIFICATE_MIN_DAYS', 21),
        'delivery_failure_percent' => (int) env('CAMPUS_MONITORING_DELIVERY_FAILURE_PERCENT', 20),
        'mail_daily_limit' => env('CAMPUS_MAIL_DAILY_LIMIT'),
    ],

    'retention' => [
        'invitation_days' => (int) env('CAMPUS_RETENTION_INVITATION_DAYS', 30),
        'notification_days' => (int) env('CAMPUS_RETENTION_NOTIFICATION_DAYS', 90),
        'audit_months' => (int) env('CAMPUS_RETENTION_AUDIT_MONTHS', 12),
        'inactivity_months' => (int) env('CAMPUS_RETENTION_INACTIVITY_MONTHS', 24),
        'backup_days' => (int) env('CAMPUS_RETENTION_BACKUP_DAYS', 30),
        'request_months' => (int) env('CAMPUS_RETENTION_REQUEST_MONTHS', 12),
        'export_row_days' => 30,
    ],
];
