<?php

namespace App\Support;

/**
 * Notices de confidentialité versionnées. Une version publiée ne se modifie pas : tout changement de fond crée une nouvelle version,
 * et la prise de connaissance d'une personne porte toujours sur une version et son empreinte.
 *
 * Ce texte décrit ce que fait réellement l'application. Il ne vaut pas analyse juridique : l'identité du responsable, l'hébergement,
 * les bases légales et le cadre applicable aux mineurs restent à compléter et à faire valider avant toute ouverture.
 */
class PrivacyNotice
{
    public const TO_COMPLETE = 'À compléter avant ouverture';

    /**
     * Versions, de la plus ancienne à la plus récente. Les durées entre accolades sont lues dans la configuration au moment de l'affichage.
     *
     * @var list<array{version: string, publishedOn: string, changes: string, sections: list<array{title: string, paragraphs: list<string>}>}>
     */
    private const VERSIONS = [
        [
            'version' => '2026-11-22',
            'publishedOn' => '2026-11-22',
            'changes' => 'Première version, rédigée avec l’application. Projet de travail : non validé juridiquement.',
            'sections' => [
                ['title' => 'Qui est responsable de vos données', 'paragraphs' => [
                    'Responsable du traitement : {controller}.',
                    'Contact pour vos droits : {contact}.',
                    'Lorsque vous êtes rattaché à un établissement, celui-ci est responsable des données qu’il gère dans Campus (inscriptions, planning, devoirs, résultats publiés). Campus ne décide pas à sa place de leur conservation.',
                ]],
                ['title' => 'Ce que Campus enregistre', 'paragraphs' => [
                    'Compte : nom, adresse électronique ou identifiant remis par un établissement, mot de passe sous forme chiffrée non réversible, dates de création et de dernière activité.',
                    'Espace individuel, visible par vous seul : matières, planning personnel, tâches, notes personnelles, retours après cours, pièces jointes privées, critères et suivi d’opportunités, recherches enregistrées, préférences de notification.',
                    'Rattachement à un établissement : rôle, groupe, matricule éventuel, et ce que l’établissement publie pour vous.',
                    'Sécurité : sessions ouvertes (adresse IP, navigateur, dernière activité) et journal des actions sensibles, sans leur contenu.',
                    'Campus n’enregistre ni donnée biométrique, ni géolocalisation précise, ni dossier de santé.',
                ]],
                ['title' => 'À quoi servent ces données et qui y accède', 'paragraphs' => [
                    'Elles servent à faire fonctionner votre espace, à vous rappeler ce que vous avez demandé, et à sécuriser l’accès.',
                    'Votre espace individuel n’est lisible que par vous. Ni votre établissement, ni l’administration de la plateforme ne peuvent lire vos notes personnelles, vos retours après cours ou vos démarches, et personne ne peut se connecter à votre place.',
                    'Les bases légales de chaque traitement : '.self::TO_COMPLETE.'.',
                    'Hébergement et éventuels transferts hors de votre pays : {hosting}.',
                    'Prestataires techniques (envoi de courriels, notifications, analyse antivirus) : '.self::TO_COMPLETE.'. Tant qu’ils ne sont pas configurés, aucun courriel ni notification du navigateur ne part.',
                ]],
                ['title' => 'Combien de temps elles sont gardées', 'paragraphs' => [
                    'Tâches supprimées : {task_trash_days} jours en corbeille, puis effacement définitif.',
                    'Invitations expirées ou traitées, et fichiers bruts d’import : {invitation_days} jours.',
                    'Notifications et traces de leur envoi : {notification_days} jours.',
                    'Journal des actions sensibles : {audit_months} mois.',
                    'Copie de lecture sur un appareil personnel : {offline_days} jours sans vérification, et effacement à la déconnexion.',
                    'Export de vos données : fichier privé disponible {export_days} jours.',
                    'Sauvegardes : prévues sur {backup_days} jours glissants. Une donnée effacée disparaît des sauvegardes à leur renouvellement, pas instantanément ; si une sauvegarde est restaurée, les effacements et retraits d’accès enregistrés depuis sont appliqués de nouveau.',
                    'Compte sans activité pendant {inactivity_months} mois : il est signalé pour examen. Aucun compte n’est supprimé automatiquement pour ce seul motif.',
                ]],
                ['title' => 'Vos droits', 'paragraphs' => [
                    'Depuis « Confidentialité et données », vous pouvez : obtenir un export de vos données, demander une rectification, vous opposer à un traitement, et supprimer votre compte. Chaque demande est suivie, avec sa date et son état.',
                    'L’export et la suppression demandent de saisir à nouveau votre mot de passe. La suppression efface votre espace individuel ; les données qu’un établissement gère sous sa responsabilité (rattachement, résultats qu’il a publiés) restent chez lui, détachées de votre identité de connexion, et relèvent de sa propre justification.',
                    'Autorité de contrôle compétente et voies de réclamation : '.self::TO_COMPLETE.'.',
                ]],
                ['title' => 'Personnes mineures', 'paragraphs' => [
                    'L’inscription des personnes mineures n’est pas ouverte. Elle le restera tant que l’information, l’autorisation ou la représentation requise et le dispositif de preuve n’auront pas été validés pour le cadre applicable.',
                ]],
            ],
        ],
    ];

    /**
     * @return array{version: string, publishedOn: string, changes: string, hash: string, sections: list<array{title: string, paragraphs: list<string>}>, toComplete: list<string>}
     */
    public static function current(): array
    {
        return self::present(self::VERSIONS[array_key_last(self::VERSIONS)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $version): ?array
    {
        foreach (self::VERSIONS as $notice) {
            if ($notice['version'] === $version) {
                return self::present($notice);
            }
        }

        return null;
    }

    /**
     * @return list<array{version: string, publishedOn: string, changes: string, hash: string}>
     */
    public static function history(): array
    {
        return array_map(fn (array $notice): array => ['version' => $notice['version'], 'publishedOn' => $notice['publishedOn'], 'changes' => $notice['changes'], 'hash' => self::hash($notice)],
            array_reverse(self::VERSIONS));
    }

    /**
     * Empreinte du texte de la version, hors valeurs de configuration : elle prouve quelle rédaction a été présentée.
     *
     * @param  array<string, mixed>  $notice
     */
    private static function hash(array $notice): string
    {
        return hash('sha256', json_encode([$notice['version'], $notice['sections']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $notice
     * @return array<string, mixed>
     */
    private static function present(array $notice): array
    {
        $given = fn (string $key): ?string => is_string(config($key)) && trim(config($key)) !== '' ? trim(config($key)) : null;
        $values = [
            '{controller}' => $given('campus.privacy.controller') ?? self::TO_COMPLETE,
            '{contact}' => $given('campus.privacy.contact') ?? self::TO_COMPLETE,
            '{hosting}' => $given('campus.privacy.hosting_region') ?? self::TO_COMPLETE,
            '{task_trash_days}' => (string) max(1, (int) config('campus.tasks.trash_days')),
            '{invitation_days}' => (string) max(1, (int) config('campus.retention.invitation_days')),
            '{notification_days}' => (string) max(1, (int) config('campus.retention.notification_days')),
            '{audit_months}' => (string) max(1, (int) config('campus.retention.audit_months')),
            '{offline_days}' => (string) max(1, (int) config('campus.offline.ttl_days')),
            '{export_days}' => (string) max(1, (int) config('campus.privacy.export_ttl_days')),
            '{backup_days}' => (string) max(1, (int) config('campus.retention.backup_days')),
            '{inactivity_months}' => (string) max(1, (int) config('campus.retention.inactivity_months')),
        ];
        $missing = array_values(array_filter([
            $given('campus.privacy.controller') ? null : 'Identité et coordonnées du responsable du traitement',
            $given('campus.privacy.contact') ? null : 'Contact pour l’exercice des droits',
            $given('campus.privacy.hosting_region') ? null : 'Région d’hébergement et transferts éventuels',
            'Bases légales de chaque traitement', 'Prestataires techniques et leurs engagements', 'Autorité de contrôle et voies de réclamation',
            'Cadre applicable aux personnes mineures', 'Validation juridique de l’ensemble de la notice',
        ]));

        return ['version' => $notice['version'], 'publishedOn' => $notice['publishedOn'], 'changes' => $notice['changes'], 'hash' => self::hash($notice), 'toComplete' => $missing,
            'sections' => array_map(fn (array $section): array => ['title' => $section['title'], 'paragraphs' => array_map(fn (string $text) => strtr($text, $values), $section['paragraphs'])], $notice['sections'])];
    }
}
