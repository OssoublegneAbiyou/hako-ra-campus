# HAKO-RA Campus V1

Application francophone en construction pour élèves et étudiants, en autonomie ou avec un établissement. Lot 01 : socle exécutable Laravel/React/TypeScript/Inertia/Tailwind/MySQL ; accueil public uniquement. Inscription et fonctionnalités métier non ouvertes. Aucun paiement, Academy, IA ou réseau social.

## Prérequis et versions
PHP 8.4 recommandé (contrainte projet ^8.3), extensions pdo_mysql, mbstring, openssl, intl et zip ; Composer 2 ; Node 24 LTS ; MySQL 8.4 avec InnoDB.
Sous ce Windows, le PATH pointe initialement vers PHP 8.0, incompatible :
```powershell
$env:Path = 'C:\wamp64\bin\php\php8.4.15;' + $env:Path
php -v
composer -V
node --version
```
Versions exactes verrouillées dans composer.lock/package-lock.json et recensées dans docs/etat-v1.md. Ne pas remplacer ces fichiers par un update lors d'une installation.

## Installation locale
Depuis le dossier du projet :
```powershell
composer install --no-interaction --prefer-dist
npm ci
Copy-Item .env.example .env
php artisan key:generate
```
Copier .env seulement lors de la première installation : conserver les réglages et la clé d'un environnement déjà utilisé. Ne jamais publier .env ou .env.testing. composer run setup installe/compile seulement, ne crée plus de clé et ne force plus de migration.

Créer deux bases exclusivement dédiées au projet depuis MySQL avec un compte habilité :
```sql
CREATE DATABASE hako_ra_campus_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE hako_ra_campus_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Créer des comptes locaux distincts, limités à leur base (hako_campus_local pour dev, hako_campus_test pour test) avec mots de passe propres enregistrés uniquement dans les fichiers locaux. Les exemples ne contiennent aucun secret réel. Le compte de test ne doit avoir aucun droit sur la base de développement.
Dans .env : DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_DATABASE=hako_ra_campus_dev, DB_USERNAME et DB_PASSWORD du compte dédié.
Les bases et comptes dédiés ont été préparés sur le poste actuel ; ne pas rejouer CREATE sur un environnement existant. Aucun autre projet n'est ciblé.

```powershell
php artisan config:clear
php artisan migrate --no-interaction
npm run build
php artisan serve
```
Ouvrir http://127.0.0.1:8000. Attendu : accueil français « Un espace pour organiser vos études et préparer la suite », date/fuseau et mention de construction, sans bouton d'inscription.
Wamp utilise ici MyISAM par défaut : config/database.php impose InnoDB pour Campus (transactions et index utf8mb4). Les deux tables vides lot01_incomplete_users issues du premier essai échoué ont été renommées et conservées, sans suppression. Elles ne sont pas utilisées par l'application.

## Développement, worker et scheduler
```powershell
composer run dev
```
Cette commande lance serveur local, queue database, scheduler et Vite avec concurrently ; Ctrl+C les arrête. Aucun Redis requis.
Ou quatre terminaux :
```powershell
php artisan serve
php artisan queue:work --tries=3 --timeout=60
php artisan schedule:work
npm run dev
```
Le scheduler émet chaque minute une sonde technique sans donnée personnelle ; le worker inscrit son horodatage dans le cache database. Son nom : campus-runtime-heartbeat. Ce test du socle n'est pas un rappel métier.
Vérification ponctuelle :
```powershell
php artisan schedule:list
php artisan schedule:run
php artisan queue:work database --stop-when-empty --tries=3 --timeout=60
```
Pour un futur hébergement, un processus supervisé exécute queue:work et le planificateur appelle chaque minute php artisan schedule:run dans le bon dossier. Aucun service distant installé/configuré dans ce lot.

## Français, dates, fichiers et logs
APP_LOCALE/APP_FALLBACK_LOCALE=fr ; APP_TIMEZONE=Africa/Abidjan est le fuseau IANA proposé d'affichage. Les instants serveur et base sont manipulés en UTC ; le frontend reçoit ISO 8601 puis formate via Intl.DateTimeFormat avec fuseau explicite. Les fuseaux individuels/institutionnels seront traités dans leurs lots.
FILESYSTEM_DISK=local pointe vers storage/app/private, sans route automatique de desserte et sans lien public. Ne pas exécuter storage:link pour rendre les pièces privées publiques. Upload, antivirus et autorisations métier restent au lot 15.
Logs locaux daily, niveau info, rotation proposée 14 jours ; processeur de masquage des clés sensibles, jetons Bearer et exceptions sans texte/trace sensible. Les appels métier ne doivent jamais journaliser corps de requête, secrets ou données privées en texte libre : le filtre n'est pas une preuve de détection universelle.
MAIL_MAILER=array en local : aucun courriel livré, aucun corps envoyé aux logs. SMTP/Push non configurés. APP_DEBUG=true est uniquement local ; recette/production exigent false, HTTPS et SESSION_SECURE_COOKIE=true avec paramètres propres.

## Tests MySQL et qualité
```powershell
Copy-Item .env.testing.example .env.testing
php artisan key:generate --env=testing
```
Première installation seulement : renseigner le compte dédié de test dans .env.testing ; ne jamais copier les identifiants d'un autre projet. Le fichier local du poste est déjà préparé.
Les tests refusent toute connexion autre que MySQL/base hako_ra_campus_test. Le lot 01 n'exécute ni migrate:fresh ni rollback ; migrer explicitement la base de test dédiée avant les tests :
```powershell
php artisan migrate --env=testing --no-interaction
php artisan test --compact
npm run typecheck
npm run lint
php vendor/bin/pint --test
npm run build
composer validate --strict
composer audit
npm audit
```
PHPUnit existant uniquement ; aucun nouveau framework de test. Les tests réels couvrent accueil Inertia, dates, worker database, scheduler, stockage non public et filtre de logs. Ils ne valident pas les permissions ou fonctions V1 futures.
ESLint 10 maintenu ; override minimatch ^11.0.8 pour corriger l'avis GHSA-g3hv-75wg-m249 dans l'outillage typescript-eslint. Retirer cet override lorsque l'amont déclare une version corrigée compatible, après npm ci/lint/typecheck/build.

## CI sans déploiement
.github/workflows/ci.yml : PHP 8.4, Node 24, MySQL de service 8.4, composer install et npm ci depuis les lockfiles, validation Composer, tests MySQL, typage, ESLint, Pint et build.
La base CI est jetable et le mot de passe root vide est limité au service éphémère du runner. Pas de secret du poste requis. Workflow préparé, non exécuté sur un runner distant ; exécution possible après mise en dépôt autorisée. Aucun déploiement.
Ne pas déclarer les scénarios R01–R58 validés par ce workflow du socle.

## Références officielles vérifiées pour le lot 01
- [Starter kit React Laravel 13](https://laravel.com/framework/docs/13.x/starter-kits) : React 19, Inertia 3, Tailwind 4.
- [Manifeste officiel du kit](https://github.com/laravel/react-starter-kit/blob/main/composer.json) : Laravel ^13.17 et Inertia ^3.
- [Installation Inertia serveur](https://inertiajs.com/docs/v3/installation/server-side-setup) et [client, dont intégration manuelle](https://inertiajs.com/docs/v3/installation/client-side-setup).
- [Vite](https://vite.dev/guide/) : prérequis Node ; Vite 8 existant conservé.
- [React](https://react.dev/versions), [TypeScript 5.9](https://www.typescriptlang.org/docs/handbook/release-notes/typescript-5-9.html), [ESLint maintenu](https://eslint.org/version-support/).
- [Queues](https://laravel.com/framework/docs/13.x/queues), [scheduler](https://laravel.com/framework/docs/13.x/scheduling), [localisation](https://laravel.com/framework/docs/13.x/localization).
Le socle du kit est intégré progressivement à l'existant selon l'installation manuelle officielle Inertia ; pas de copie/réinitialisation du kit complet ni d'ajout anticipé de Fortify/SSO/passkeys. Authentification à réaliser dans le futur lot identité, notamment avec les règles mineurs ; le Prompt 02 reçu couvre la navigation.

## Suivi
Lire docs/specification-v1.md, architecture-v1.md, etat-v1.md, recette-v1.md et arbitrages-v1.md. Aucun dépôt Git initial dans ce dossier ; aucun commit/push/publication créé. Lot 01 local ne vaut pas réception de la V1.


## Navigation et composants — lot 02
Accueil : navigation mobile à cinq entrées, sidebar ordinateur et Notes dans l'espace d'études ; contexte individuel visible pour la visite actuelle. Présentation proposée, sans police ou asset acheté.
En environnement local/testing, /planning, /taches, /opportunites, /profil et /notes montrent explicitement les écrans encore à construire. En production ces routes provisoires sont refusées (404) et les liens correspondants sont indisponibles. Ajouter les vrais écrans et leur autorisation au fil des lots, puis marquer leur navigation prête dans HandleInertiaRequests.
Atelier local : /developpement/composants (ou lien dans le pied de page), avec exemples fictifs, champs/erreurs, dialogue, filtres, pagination, provenance et états. Aucune saisie enregistrée. Route inaccessible en production.
Composants réutilisables : resources/js/components/ui ; layout : resources/js/layouts/CampusLayout.tsx. L'affichage d'un lien admin demande permission Gate et route existante ; le backend de chaque futur module reste responsable de son autorisation.
Contrôles exécutés : 13 tests MySQL/188 assertions, typecheck, lint, Pint et build ; Edge à 360/768/1440 px, clavier/focus/modal, filtres/pagination, hors réseau et repli 200 % simulé. Rapport et captures dans docs/preuves ; zoom natif par menu, Safari/iOS et lecteur d'écran restent à tester. Aucun nouveau framework de tests ou dépendance ajouté au projet.

## Modèle et frontières — lot 03
Migration additive : 2026_11_15_200703_create_campus_foundation_tables. User reçoit un statut ; neuf tables Campus définissent institutions, espaces personnels, années/périodes/groupes/matières, appartenances, inscriptions et affectations. Toutes relations référencées sont RESTRICT, sans cascade. Ne pas lancer down/rollback/migrate:fresh sur des données existantes.
Commandes locales (PHP 8.4, base dev dédiée configurée) :
```powershell
php artisan migrate --no-interaction
php artisan db:seed --class=CampusDemoSeeder --no-interaction
php artisan migrate --env=testing --no-interaction
php artisan test --compact --filter=CampusFoundationTest
php artisan test --compact
```
Seeder réservé à local/testing et aux noms hako_ra_campus_dev/hako_ra_campus_test. Il est répétable sur les fixtures inchangées et ne réactive pas une appartenance retirée. Il peut refuser un graphe de fixtures modifié/incomplet plutôt que lui rendre silencieusement des accès.
Fixtures : CAMPUS-FICTIF-A/B, apprenant-double@example.test (A/B), independant@example.test (sans établissement), enseignant-a@example.test (A/G1), admin-a/admin-b@example.test, editeur@example.test, polyvalent@example.test (learner A/teacher B) et plateforme@example.test. Mots de passe aléatoires non affichés par défaut ; sur le poste de développement, `php artisan campus:fixtures:access` leur donne un mot de passe saisi sans écho (et vérifie leur adresse), `--lock` referme l'accès. La commande se refuse hors environnement local et hors base hako_ra_campus_dev.
ContextVisibility est le point de lecture des frontières, sans tenant global : espace privé du propriétaire, appartenance courante du titulaire, groupes autorisés et affectations valides. Aucun passe-droit plateforme/éditeur. Les futurs endpoints devront en plus autoriser leurs mutations ; les modèles/seeder ne sont pas une API d'attribution de droits.
Tests du lot : 20 ciblés/77 assertions ; suite complète 33 tests/265 assertions, MySQL réel. Contrats progressifs et suppressions : docs/architecture-v1.md ; preuves de schéma : docs/preuves/lot03-schema-mysql.txt. Pas de table ou écran de note/retour/démarche créé par anticipation.

## Comptes et sessions — lot 04
Écrans : `/inscription`, `/connexion`, `/mot-de-passe-oublie`, `/activation` (compte remis par un établissement), `/profil` (sessions actives). Paramètres dans `config/campus.php` et `.env.example` (`CAMPUS_*`).

- **Courriels** : `MAIL_MAILER=array` par défaut, donc aucun message n'est envoyé. Un compte créé par adresse électronique ne peut être vérifié qu'après configuration d'un service d'envoi. Aucun envoi réel n'a été testé.
- **Second facteur** : code temporaire à six chiffres (Google Authenticator ou application compatible), à activer depuis le profil (`/profil/second-facteur`). Il est obligatoire pour les rôles d'enseignement, d'administration et d'édition ; sans lui, leurs actions répondent 403. Pour travailler en local sur des données fictives sans l'activer, mettre `CAMPUS_PRIVILEGED_WITHOUT_MFA=true` dans `.env` ; ce réglage est sans effet hors `local` et `testing`. Téléphone et codes de secours perdus : `php artisan campus:second-factor:reset <adresse> --reason="…"`. Contrôle dans Edge, sans dérogation : `node tests/browser/second-factor-edge.mjs` (port 8130).
- **Sessions** : la liste et la révocation ciblée exigent `SESSION_DRIVER=database`.
- **Mineurs** : parcours fermé tant que `CAMPUS_MINORS_ENABLED` et `CAMPUS_MINORS_FRAMEWORK_REFERENCE` ne sont pas tous deux renseignés.

Tests du lot : `php artisan test --compact tests/Feature/IdentityTest.php tests/Feature/ProvisionedAccountTest.php`.
## Invitations et contextes — lot 05
Écrans : `/administration/etablissement` (membres, invitations, export), `/administration/plateforme` (établissements, habilitations), `/invitation/{secret}` (acceptation), sélecteur de contexte dans l'en-tête. Ces écrans d'administration exigent `CAMPUS_PRIVILEGED_WITHOUT_MFA=true` en local, le second facteur n'étant pas livré.

- Durée de validité d'une invitation : `CAMPUS_INVITATION_TTL_DAYS` (14 par défaut).
- Le lien d'invitation s'affiche une seule fois ; l'envoi par courriel ne part que si un service d'envoi est configuré et qu'un worker tourne.
- Après mise à jour depuis une version antérieure au lot 05, lancer `php artisan view:clear` : l'ancienne vue racine écrivait les données des pages dans `storage/framework/views`.

Tests du lot : `php artisan test --compact tests/Feature/InvitationContextTest.php`.

## Référentiels académiques (lot 06)
Après `php artisan migrate` (base Campus de développement dédiée), ouvrir `/etudes/referentiels` depuis « Matières et référentiels ». Sans contexte institutionnel, matières/périodes privées ; avec contexte institutionnel, références de son année et droits selon rôle. Administration uniquement avec la dérogation locale déjà documentée tant que le MFA est absent. Aucun pilote privilégié réel n'est autorisé par cette dérogation.
Tests ciblés : `php artisan test --compact --filter=AcademicAdministrationTest` avec PHP 8.4 et la base `hako_ra_campus_test`. Recette, limites et preuves dans `docs/recette-v1.md`, section lot 06. Les mutations JSON renvoient un code stable, un message français, les erreurs et un `request_id` sans détail interne.


## Planning (lot 07)
Ouvrir `/planning` après connexion et vérification. Espace individuel : cours/événements privés, y compris week-end. Contexte établissement : administration habilitée en écriture et enseignants affectés en prévisualisation ; aucun brouillon exposé aux apprenants. La publication sera ajoutée au lot 08 ; MFA toujours requis avant tout pilote privilégié réel.
Migrations additives : `php artisan migrate` uniquement sur la base Campus dev dédiée. Tests : `php artisan test --compact --filter=ScheduleTest`, PHP 8.4 et MySQL `hako_ra_campus_test`.
Une demande matérialise au maximum 56 jours inclusifs ; première fenêtre créée avec la série, suivantes depuis « Matérialiser une autre fenêtre ». Validité maximale de 730 jours depuis l'ancrage initial ; génération rejouable, sans supprimer les exceptions. Durée quotidienne maximale de 12 h, sans minuit. Heures locales ambiguës/inexistantes refusées avec explication.
Jour/semaine/liste paginés, historique conservé, confirmation motivée des chevauchements. Ni rappel, ni publication, ni synchronisation hors ligne ne sont simulés. Preuves, recette et limites dans `docs/recette-v1.md` et `docs/preuves/lot07-verifications.txt`.

## Publication du planning — lot 08
Écran `/planning/publication` (administration d'établissement, dérogation locale `CAMPUS_PRIVILEGED_WITHOUT_MFA=true` requise) : différences entre brouillon et version publiée, publication, versions, retour arrière, signalements d'indisponibilité. `/planning?source=published` affiche le planning publié ; les apprenants ne voient que lui.

- Limite proposée par publication : `CAMPUS_PUBLICATION_MAX_OCCURRENCES` (20 000 par défaut, non mesurée).
- Les événements de publication sont écrits dans la table `domain_events` ; rien ne les consomme avant le lot 13.

Tests du lot : `php artisan test --compact tests/Feature/SchedulePublicationTest.php`.
## Import Excel du planning — lot 09
Écran `/planning/import` (administration d'établissement, dérogation locale requise) : modèle `.xlsx` à télécharger, dépôt, rapport de simulation, application au brouillon. La publication reste celle de `/planning/publication`.

- Lecture et écriture `.xlsx` natives (extensions PHP `zip` et `xmlreader`), sans bibliothèque ajoutée. Les formules ne sont jamais calculées ; macros, objets intégrés et liens externes sont refusés.
- L'analyse et l'application tournent en file : un worker doit être actif (`composer run dev` le lance).
- Paramètres : `CAMPUS_IMPORT_MAX_KB` (5120), `CAMPUS_IMPORT_MAX_ROWS` (5000), `CAMPUS_IMPORT_RAW_RETENTION_DAYS` (30).
- Purge du fichier brut : commande `php artisan campus:purge-import-files`, planifiée chaque jour à 03:15 ; le planificateur doit tourner.
- Mesure de 1 000 lignes : `CAMPUS_MEASURE=1 vendor/bin/phpunit --filter=test_measured_import_of_one_thousand_rows` (ajouter `XDEBUG_MODE=off` pour mesurer sans le débogueur).

Tests du lot : `php artisan test --compact tests/Feature/ScheduleImportTest.php`.
## Retour privé après cours — lot 10
Écrans `/retours` (à renseigner, historique) et `/planning/occurrences/{cours}/retour` (formulaire), accessibles à tout compte vérifié sans rôle particulier. L'accueil propose les cours terminés sans retour. Paramètre : `CAMPUS_CHECK_IN_PENDING_DAYS` (7).

Pour des tests plus rapides sur un poste où Xdebug est chargé : `XDEBUG_MODE=off php artisan test --compact`.

Tests du lot : `php artisan test --compact tests/Feature/CourseCheckInTest.php`.

## Tâches et devoirs — lot 11
Écran `/taches` : tâches personnelles et devoirs de ses groupes, filtres, étapes, corbeille (`/taches?vue=corbeille`). Accessible à tout compte vérifié, sans rôle particulier. Écran `/taches/devoirs` : devoirs de l'enseignant pour ses groupes et matières affectés ; la publication exige la dérogation locale `CAMPUS_PRIVILEGED_WITHOUT_MFA=true`. Paramètre : `CAMPUS_TASK_TRASH_DAYS` (7). Purge de la corbeille : `php artisan campus:purge-tasks` (planifiée à 03 h 30).

Tests du lot : `php artisan test --compact tests/Feature/TaskTest.php`.

## Notes et moyennes — lot 12
Écran `/notes` : notes personnelles ou résultats publiés par un établissement, une source à la fois, avec moyennes indicatives par période. Écran `/notes/evaluations` : évaluations de l'enseignant pour ses groupes et matières affectés. Écran `/notes/coefficients` : coefficients de matière de l'administration. La saisie par l'enseignant et les coefficients exigent la dérogation locale `CAMPUS_PRIVILEGED_WITHOUT_MFA=true`. Paramètre : `CAMPUS_GRADE_WATCH_THRESHOLD` (10).

Tests du lot : `php artisan test --compact tests/Feature/GradeTest.php`.

## Rappels et canaux — lot 13
Centre de notifications `/notifications`, préférences `/notifications/preferences`. Le cycle de rappels est mis en file chaque minute par le planificateur et exécuté par la file : il faut donc `php artisan schedule:work` (ou la tâche cron) et `php artisan queue:work`, déjà lancés par `composer run dev`. Cycle immédiat : `php artisan campus:notifications:run`.

Canaux externes, désactivés par défaut et non vérifiés :
- Courriel : configurer `MAIL_*`, puis `CAMPUS_NOTIFICATION_EMAIL_ENABLED=true`.
- Web Push : `php artisan campus:push:key` affiche la clé privée à placer dans `CAMPUS_PUSH_PRIVATE_KEY` (secret, à ne jamais partager) ; renseigner `CAMPUS_PUSH_SUBJECT=mailto:…`. Le serveur n'envoie aucun contenu et n'appelle que les services de push listés dans `config/campus.php`.

Tests du lot : `php artisan test --compact tests/Feature/NotificationTest.php`.

## Pièces jointes — lot 14
Dépôt de PDF, JPEG et PNG sur les tâches, les devoirs et les retours de cours ; fichiers privés dans `storage/app/private/attachments`. Un fichier n'est téléchargeable qu'après une analyse antivirus réussie : avec le réglage par défaut `CAMPUS_ATTACHMENT_SCANNER=none`, les fichiers déposés restent bloqués.

Antivirus (ClamAV) : installer et démarrer `clamd` en écoute TCP locale, régler `CAMPUS_ATTACHMENT_SCANNER=clamd`, `CAMPUS_CLAMD_HOST`, `CAMPUS_CLAMD_PORT`, puis exécuter `php artisan campus:attachments:scanner-check` (doit se terminer par « Antivirus opérationnel sur ce serveur »). L'analyse passe par la file : `php artisan queue:work` doit tourner. Maintenance : `php artisan campus:attachments:maintain` (planifiée toutes les dix minutes). Procédure de recette détaillée : `docs/recette-v1.md`, section lot 14.

Limites réglables : `CAMPUS_ATTACHMENT_MAX_KB` (5120), `CAMPUS_ATTACHMENT_MAX_PER_OBJECT` (3), `CAMPUS_ATTACHMENT_USER_QUOTA_MB` (100). Vérifier que `upload_max_filesize` et `post_max_size` de PHP dépassent la taille maximale.

Tests du lot : `php artisan test --compact tests/Feature/AttachmentTest.php`.

## Catalogue des opportunités — lot 15
Catalogue public `/opportunites` (fiches publiées seulement). Administration éditoriale `/administration/catalogue` et file des signalements `/administration/catalogue/signalements`, réservées au rôle d'éditeur du catalogue dans son contexte plateforme, avec la dérogation locale `CAMPUS_PRIVILEGED_WITHOUT_MFA=true`. Archivage des fiches closes : `php artisan campus:opportunities:archive-closed` (planifié toutes les quinze minutes).

Fiches d'exemple pour le développement, explicitement fictives : `php artisan db:seed --class=OpportunityDemoSeeder` (incluses dans `php artisan db:seed`). Elles ne constituent pas un catalogue réel.

Tests du lot : `php artisan test --compact tests/Feature/OpportunityTest.php`.

## Recherche et correspondances — lot 16
`/opportunites` : recherche, filtres, tris et pagination, sans compte. `/opportunites/profil` : critères facultatifs déclarés par la personne connectée, utilisés seulement pour la comparaison affichée sur les fiches. L'extension PHP `intl` fournit les noms de pays et de langues ; sans elle, les codes à deux lettres sont affichés.

Tests du lot : `php artisan test --compact tests/Feature/OpportunitySearchTest.php`.

## Favoris et démarches — lot 17
`/opportunites/suivi` : favoris et démarches privées du compte connecté. Favori et démarche se gèrent depuis la page d'une fiche. Paramètre : `CAMPUS_OPPORTUNITY_CLOSING_SOON_DAYS` (7).

Tests du lot : `php artisan test --compact tests/Feature/OpportunityFollowUpTest.php`.

## Recherches enregistrées et alertes — lot 18
`/opportunites/alertes` : recherches enregistrées, état, arrêt, reprise, suppression ; une recherche s'enregistre depuis `/opportunites` après avoir appliqué des filtres. Les synthèses et les rappels des fiches suivies sont produits par le cycle de rappels du lot 13 (`php artisan schedule:work` et `php artisan queue:work`, ou `php artisan campus:notifications:run` pour un cycle immédiat). Les rappels des fiches suivies se règlent dans `/notifications/preferences`.

Tests du lot : `php artisan test --compact tests/Feature/OpportunityAlertTest.php`.

## PWA et lecture hors connexion — lot 19
Campus s'installe comme raccourci web (manifest et service worker `public/sw.js`) ; ce n'est pas une application native. Page `/hors-ligne` : installation et choix « appareil personnel » pour conserver une copie de lecture (planning sur 14 jours, tâches, notes, opportunités). Paramètre : `CAMPUS_OFFLINE_TTL_DAYS` (7). Quand un fichier de la coque change (`offline.html`, `offline.js`, manifest, icônes), incrémenter `VERSION` dans `public/sw.js`.

Tests : `php artisan test --compact tests/Feature/PwaTest.php`, `npm run test:js`, et le contrôle en navigateur `node tests/browser/pwa-edge.mjs` (Edge ou Chrome requis ; variables `CAMPUS_PHP`, `CAMPUS_BROWSER`, `CAMPUS_PORT`).

## Brouillons hors connexion et synchronisation — lot 20
Sur un appareil déclaré personnel, une tâche personnelle ou un retour privé après cours peut être écrit sans réseau, puis envoyé à la réouverture de Campus, au retour du réseau, ou par « Synchroniser maintenant » (`/hors-ligne`). Point d'entrée : `POST /synchronisation/v1/operations` (session et CSRF). Purge des accusés : `php artisan campus:sync:prune` (quotidienne), paramètre `CAMPUS_SYNC_RETENTION_DAYS` (90).

Le code de la file est unique : `public/offline-core.js`. S'il change, incrémenter `VERSION` dans `public/sw.js` ; si le schéma local change, augmenter `DB_VERSION` et ajouter une étape à `migrate` sans jamais supprimer un magasin.

Tests : `php artisan test --compact tests/Feature/SyncTest.php`, `npm run test:js`, `node tests/browser/pwa-edge.mjs`.

## Accueil, parcours et aide — lot 21
L'accueil d'un compte connecté affiche ses prochaines actions et un parcours de démarrage calculé sur ses données ; l'administration d'un établissement y voit aussi la mise en route de son établissement. Page d'aide : `/aide` (contact : `CAMPUS_SUPPORT_EMAIL`, vide par défaut). Fuseau personnel : profil.

Recette manuelle avec les comptes fictifs : `php artisan campus:fixtures:access`, puis lancer le serveur avec `CAMPUS_PRIVILEGED_WITHOUT_MFA=true` pour les rôles d'administration et d'enseignement ; `php artisan campus:fixtures:access --lock` à la fin.

Parcours automatiques en navigateur réel : `node tests/browser/journeys-edge.mjs` (Edge ou Chrome ; variables `CAMPUS_PHP`, `CAMPUS_BROWSER`, `CAMPUS_PORT`). Le script écrit des données fictives préfixées « [PARCOURS … ] » dans la base de développement et referme les comptes fictifs en fin de passage. Tests : `php artisan test --compact tests/Feature/HomeTest.php`, `npm run test:js`.

## Confidentialité, droits et conservation — lot 22
Notice publique : `/confidentialite` (projet, à compléter et à faire valider avant ouverture). Renseigner `CAMPUS_PRIVACY_CONTROLLER`, `CAMPUS_PRIVACY_CONTACT`, `CAMPUS_HOSTING_REGION`. Compte connecté : `/confidentialite/parametres` (lecture de la notice, export, demandes, suppression). Plateforme : `/administration/plateforme/demandes`.

Conservation : `php artisan campus:retention:run` (quotidienne) ; durées dans `config/campus.php` (`retention`, `privacy`) et les variables `CAMPUS_RETENTION_*`, `CAMPUS_EXPORT_TTL_DAYS`.

Journal des effacements : fichier désigné par `CAMPUS_ERASURE_JOURNAL_PATH`, à placer sur un stockage distinct de la base et à sauvegarder séparément. Après la restauration d'une sauvegarde : `php artisan campus:privacy:reapply-journal`.

Tests : `php artisan test --compact tests/Feature/PrivacyTest.php`.

## Durcissement et mesures — lot 23
Rapport : `docs/audit-v1.md`. Avant une ouverture : `php artisan campus:security:check`, `composer audit`, `npm audit --omit=optional`.

Le serveur web de production doit compresser les réponses et poser les en-têtes de sécurité sur les fichiers statiques (`sw.js`, `offline.html`, `build/assets`) : l'application ne le fait que pour ses propres réponses. `APP_URL` doit porter le nom exact du site : hors local, les requêtes adressées à un autre nom sont refusées.

Contrôles : `node tests/browser/audit-edge.mjs` (accessibilité, contenu saisi, temps de chargement), `node tests/perf/load.mjs --smoke` (essai de charge court, local) ; la mesure complète (`--full`) se fait seulement sur un environnement décrit et autorisé.

## Exploitation, sauvegarde et supervision — lot 24

Document : `docs/exploitation-v1.md` ; modèles de configuration dans `docs/exploitation/`. Rien n'est déployé ; la restauration complète de la base n'a pas encore été exercée.

- Sauvegarde : `php artisan campus:backup key --output=<fichier hors dépôt>`, puis `CAMPUS_BACKUP_DIRECTORY` et `CAMPUS_BACKUP_KEY` ; `campus:backup create`, `list`, `verify latest --deep`.
- Restauration isolée : `campus:backup restore <nom> --database=<base vide> --storage=<dossier vide>`, puis `campus:restore:finalize`.
- Supervision : `php artisan campus:health` ; sonde `GET /sante/<contrôle>` avec l'en-tête `X-Monitoring-Token` une fois `CAMPUS_MONITORING_TOKEN` renseigné.
- Mise en service : fichier `REVISION` à la racine, puis `php artisan campus:release:check`.

## Recette finale et pilote — lot 25

- Verdict et bloquants : `docs/bilan-livraison-v1.md`. En l'état : démonstration fictive seulement.
- Matrice R01 à R58 : `docs/recette-v1.md`, section « Lot 25 — recette finale ».
- Préparation du pilote : `docs/pilote-v1.md`.
- Recette de bout en bout dans Edge ou Chrome, sur la base de développement et ses comptes fictifs : `node tests/browser/recette-edge.mjs` (port 8128), en complément de `node tests/browser/journeys-edge.mjs`.

## Connexion par compte Google

Facultative. Dans la console Google Cloud, créer un identifiant OAuth de type « application Web » avec l'adresse de retour `<APP_URL>/connexion/google/reponse`, puis renseigner `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET` dans `.env`. Sans ces deux valeurs, le bouton n'apparaît pas.

- Connexion : « Continuer avec Google » sur `/connexion`. Un compte Google inconnu crée un compte Campus après la déclaration de majorité.
- Compte existant : relié automatiquement si l'adresse est la même et déjà vérifiée, ou depuis le profil (« Relier mon compte Google »).
- Le second facteur reste demandé. Aucun rôle ne s'obtient par Google.
- Jamais essayée avec Google à ce jour : les tests fabriquent sa réponse.
