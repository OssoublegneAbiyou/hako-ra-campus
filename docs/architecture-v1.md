# Architecture V1

## Diagnostic historique au lot 00 et cible
Constaté : Laravel 13.42.0, Blade welcome, Vite/Tailwind ; React/TypeScript/Inertia absents. User et migrations users/cache/jobs standards, aucune auth exposée ni membership/policy Campus. bootstrap/app.php rend JSON selon api/* ou expectsJson mais sans contrat d'erreur métier. routes/console.php contient inspire, aucun rappel.
Disque local vers storage/app/private ; aucun téléchargement/scan autorisé démontré. Tests SQLite mémoire, mail array et queue sync : aucune preuve MySQL ou d'intégration externe.
Cible décidée : monolithe Laravel + React/TypeScript/Inertia + Tailwind + MySQL, base partagée. Aucun Next.js parallèle ni duplication complète des écrans par API.

## Frontières modulaires logiques

| Module | Responsabilité et propriété |
|---|---|
| Identity | Identité unique, sessions, récupération et MFA |
| Organizations | Institutions, invitations, memberships datées et rôles contextualisés |
| Academics | Années, périodes, groupes, matières et affectations institutionnels |
| Scheduling | Séries/occurrences, exceptions, imports et publications ; provenance explicite |
| Study | Retours/notes privés ; devoirs collectifs distincts des états individuels |
| Opportunities | Catalogue éditorial public publié ; favoris/démarches privés |
| Notifications | Préférences, intentions et livraisons par destinataire/contexte |
| Files | Dépôt, scan/quarantaine, quota et téléchargement contrôlé via parent |
| Administration/Audit | Exploitation et actes privilégiés sans lecture privée |

Conserver l'arborescence Laravel existante ; ces frontières ne créent pas prématurément des packages ou dossiers. Contrôleurs courts, Form Requests, policies, actions métier testables et transactions framework. Tests isolés des calculs/récurrences/décisions d'envoi, tests intégrés des droits.

## Modèle et invariants
User conserve son espace individuel sans institution. Membership porte user/institution/année/rôle/statut/dates ; enrollment et teaching assignment séparés. Rôles de plateforme explicitement limités, aucun users.role général.
Tables institutionnelles avec institution_id, objets personnels avec propriétaire. Contraintes FK/composites et validation empêchent liens interétablissements. Contrôler aussi chaque parent d'une ressource imbriquée. Pas de cascade de retrait d'appartenance vers données privées ; référentiels utilisés archivés.
Series produit occurrences stables ; publication versionnée immuable. Unicité retour(apprenant, occurrence), favori(user, fiche), import(empreinte/périmètre) et sync(user, clé client). Devoir collectif séparé de progression individuelle ; provenance des notes.
Décimaux pour notes et coefficients, arrondi seulement à l'affichage. Instants UTC avec fuseau d'origine, date sans heure distincte. Index contexte/date, propriétaire/échéance, livraison/statut/date et catalogue/statut/clôture.

## Autorisation et contexte
Le contexte actif est explicite ; sa valeur client/session est revérifiée contre l'identité, l'appartenance active et les permissions à chaque action. Institution_id n'autorise rien.
Requêtes filtrées et policies : propriétaire, institution, groupe, affectation et rôle. Pas de bypass admin sur données privées, aucun support/impersonation en V1. Réponses étrangères sans oracle d'existence.
Même contrôle pour jobs, caches, exports, rapports et fichiers. Clés de cache utilisateur/contexte/version ; invalidation et recontrôle après révocation. Job recontrôlé à l'exécution, pas seulement au dispatch.

## Transactions et traitements asynchrones
Import entièrement transactionnel vers brouillon, publication distincte. Cohérence entre écriture métier et événement asynchrone par boîte d'événements transactionnelle ou équivalent prouvé ; mécanisme concret à arrêter au lot 14. Un échec externe ne recrée ni n'annule une tâche déjà enregistrée.
Laravel queue pour imports, scan, envois et exports ; scheduler pour échéances, expiration, purge et surveillance. Intention de notification unique, états par livraison/canal, retries bornés et délais croissants. Avant envoi et après reprise : droits, préférences, date, statut et version ; rappel inutile expiré/annulé. Fournisseur accepté ne veut pas dire lu ; réponse perdue peut laisser état indéterminé, réconciliation si disponible. Aucune garantie externe exactement une fois.
Traitement long : identifiant et état consultables avec autorisation identique à la ressource.

## Contrats frontend et JSON
Écrans et mutations courantes Inertia sous session/CSRF ; JSON versionné pour synchronisation et vrais besoins asynchrones. La table de routes API du PDF est logique, pas un backlog de duplication.
Erreur cible : code stable, message français, errors par champ, request_id ; 401/403/404/422/409 et codes quota/fréquence distincts, aucune stack ou ressource étrangère révélée.
Clé de rejeu liée à utilisateur et contenu ; réutilisation incompatible refusée. Version connue pour modification différée et update atomique ; conflit exploitable. Frontend : loading/vide/erreur/interdit/offline/session expirée/succès, saisie conservée et reprise.
PWA : coque publique séparée de base locale privée par compte, opt-in et limites sur appareil partagé, horodatage et purge. Droits courants au sync, jamais autorisation locale seule. Identifiants client, version serveur, migration locale et mise à jour worker sans perte des opérations. Synchronisation manuelle et au retour, sans dépendance exclusive au background sync.

## Fichiers et services
Stockage privé, type réel/extension/taille contrôlés, quota atomique, scan en quarantaine fermée et parent autorisé au téléchargement ; ne pas publier storage/app/private. URL courte ne remplace pas permission.
HTTP(S) externe contrôlé sans fetch arbitraire serveur. Aucun coffre documentaire de candidature.
SMTP, Web Push, antivirus, sauvegarde externe et supervision non vérifiés/configurés par ce lot ; fournisseurs à arbitrer, aucune offre payante engagée. SMS/WhatsApp exclus.

## Environnements, preuves et évolutions
Local/recette/production séparés. Données fictives en test ; tests MySQL nécessaires pour verrous, transactions, contraintes et précision. Mesure de charge, navigateur et restauration à réaliser, pas à déduire de SQLite/build.
Aucun .git actuel ; pas d'initialisation ou commit dans ce lot. Changements reviewables par fichiers, historique initial indisponible.
Décisions produit : specification-v1.md ; dépendances : etat-v1.md ; vérifications : recette-v1.md ; points à clôturer : arbitrages-v1.md.


## Réalisation du socle au lot 01
Accueil Inertia Home servi par Laravel ; entrée app.tsx, page React TypeScript, Tailwind/Vite conservés. Middleware Inertia ajouté au groupe web, Blade root app ; aucune route API dupliquée. Pages d'authentification non ouvertes ; l'affectation initiale au 02 est remplacée par le Prompt 02 de navigation.
Locale fr, messages de base lang/fr ; instants UTC, app.display_timezone dérivé de APP_TIMEZONE (Africa/Abidjan par défaut), Intl avec timeZone explicite.
MySQL imposé en InnoDB pour les tables du projet ; bases hako_ra_campus_dev/test et comptes restreints distincts. Aucun changement du moteur global Wamp. Tests refusent une autre base/connexion.
Queue database configurée après commit, scheduler chaque minute et RuntimeHeartbeat testés réellement ; aucune implémentation de rappel métier ou d'outbox revendiquée. Sans superviseur/scheduler lancé, aucun service permanent n'est garanti.
Stockage local privé sans route automatique serve ; antivirus/téléchargement métier futur. Logs daily avec filtre partagé des canaux, exceptions réduites au type/code, mail array sans log du corps.
CI service MySQL et installation verrouillée préparés ; aucun runner distant ni déploiement. Sources de compatibilité dans README. Tests B01–B08 et preuves locales dans recette.


## Lot 02 — shell et composants
CampusLayout consomme campus partagé par HandleInertiaRequests : contexte serveur, navigation (key/label/href/pending), développement et administration. Aucun contexte d'établissement déduit d'une valeur client. Le seul contexte réel actuel est individuel, visite sans connexion ; futur contexte membre à brancher après le lot identité.
Navigation mobile à cinq entrées et sidebar ordinateur ; Notes accessible sur Accueil et dans l'espace d'études. Administration en sidebar ou navigation secondaire mobile, sous double condition Route::has et Gate::allows. Les futures routes administratives devront appliquer leurs propres autorisations, indépendamment de ces liens.
Routes des modules non implémentés gardées par environnement local/testing ; en production 404, navigation sans href pour ces entrées. À la livraison d'un module réel, remplacer son écran provisoire et marquer son entrée prête côté serveur, en conservant les policies.
Atelier DevelopmentUiController local/testing seulement, exemples fictifs fournis par le serveur et pagination bornée. Aucun POST, stockage ou succès de sauvegarde factice. Code de page chargé à la demande, sans nouvelle dépendance runtime.
Composants sous resources/js/components/ui : Fields, Button, ConfirmDialog, Collection et Feedback. Labels/aides/erreurs reliés via IDs, dialogue contrôlé avec focus initial/retour/cycle explicites, liste mobile/table ordinateur, pagination avec URLs réelles, filtres contrôlés et badges textuels. Le parent gère les données, versions et mutations futures.
Styles communs dans app.css : tokens réversibles, polices système, SVG locaux, repères textuels, focus contrasté, espace de défilement pour barres fixes, breakpoints et mouvement réduit.
Tests serveur PHPUnit existant, navigateur Edge contrôlé avec outil temporaire hors dépendances applicatives ; preuves dans docs/preuves. Pas de changement de framework de tests, de lockfile ou de base.

## Frontières du lot 03 — décrites avant création des migrations
Examen initial : tables Laravel users/sessions/password_reset_tokens, cache/cache_locks et jobs/job_batches/failed_jobs, toutes InnoDB ; tables partielles vides lot01 conservées hors modèle. Aucun schéma Campus préexistant, aucune réinitialisation.
- Compte : User conserve l'identité unique et son statut ; aucun rôle global users.role. Les identifiants de connexion sans email seront ajoutés avec leur procédure de récupération, pas inventés dans ce lot.
- Espace individuel : PersonalSpace possède un owner_id obligatoire/unique, fuseau IANA et préférences privées. Aucune institution, aucune lecture plateforme implicite. Compte et espace ne sont pas supprimés lors d'un retrait d'appartenance.
- Institution : référentiel isolé avec code unique, fuseau, pays et archived_at ; pas de tenant global Eloquent.
- Année : AcademicYear appartient à une institution ; Period et Group portent institution_id et academic_year_id cohérents par FK composite. Subject est un référentiel d'institution réutilisable entre années.
- Appartenance : Membership porte user_id, scope institution/platform, institution/année dans le premier cas, rôle et état/dates. Platform exige institution/année nulles explicitement et un rôle plateforme ; cela ne signifie jamais public. Une personne peut cumuler learner A, teacher B et son espace individuel.
- Groupe : Enrollment relie une appartenance learner au groupe de la même institution/année et au même utilisateur. TeachingAssignment exige une appartenance teacher et une matière du même établissement. Le rôle copié dans la FK est fixé par contrainte, pas une permission déclarée par le client.
- Données publiées futures : provenance institutionnelle, auteur, version et statut de publication explicites ; visibilité soumise à l'appartenance et aux affectations courantes, jamais au seul institution_id.
- Données privées futures : propriétaire non nul, type de périmètre explicite si mixte, relation parent autorisée ; aucun null interprété comme public, aucun bypass plateforme.

Migrations prévues : statut de compte + institutions, espaces personnels, années, périodes, groupes, matières, appartenances, inscriptions et affectations. Invitations et tous les objets métier restants restent des contrats, sans CRUD vide.
Intégrité : FK composites vers des clés uniques complètes, CHECK locaux aux lignes (dates/scope/rôle), colonnes générées et index uniques des relations en cours. Les lignes suspendues continuent de réserver le rôle ; les lignes closes libèrent l'unicité et gardent l'historique. L'expiration retire l'accès immédiatement par la requête, même si la clôture administrative n'a pas encore été faite.
Suppressions : aucune cascade de ce socle. RESTRICT pour tous parents référencés ; archiver les références utilisées. Retrait d'accès = état et dates, pas DELETE. Effacement du compte privé à traiter explicitement au lot droits, jamais cascade par établissement.
Références techniques consultées : MySQL 8.4, contraintes FK/CHECK et index de colonnes générées :
https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html
https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html
https://dev.mysql.com/doc/refman/8.4/en/create-table-generated-columns.html

## Contrats progressifs après réalisation du lot 03
Les lignes « Contrat » ci-dessous ne sont ni des tables livrées ni des CRUD en attente de remplissage. Elles fixent les propriétaires/périmètres et invariants à reprendre dans les prochains lots.

| Entité | État réel | Contrat et frontière |
|---|---|---|
| User | Migré, enrichi | Identité unique, statut active/suspended ; données du titulaire. Aucun rôle global. Email sans adresse individuelle : évolution avec récupération sûre, IAM02 maintenu. |
| PersonalSpace | Migré (complément du socle) | owner_id obligatoire et unique, préférences privées et fuseau ; aucun établissement. Provisionnement atomique avec le futur parcours de création de compte. |
| Institution | Migré | Code unique, pays/fuseau, archived_at ; accès de gestion habilité et références conservées. |
| Membership | Migré | Compte + scope institution/platform + rôle + état/dates ; institution/année obligatoires ou explicitement absentes selon scope. Pas de rôle transmis faisant autorisation. |
| Invitation | Contrat, non migré | Émetteur et scope/rôle explicites, empreinte de jeton (jamais secret brut), expiration/révocation, destinataire vérifié. Accepter sur compte existant ; règles de provisionnement sans email à définir avec récupération sûre. |
| AcademicYear | Migré | Institution, dates, code unique local ; FK composite pour tous enfants. |
| Period | Migré | Institution/année et parent du même contexte ; dates contenues, poids positif décimal. Types/périodes et règles de chevauchement à compléter au lot référentiels selon A08. |
| Group | Migré | Institution/année/code, archivage ; aucun déplacement de contexte sur modèle existant. |
| Enrollment | Migré | Compte + appartenance learner + groupe cohérents ; dates et current_slot unique, closed_at pour retrait effectif. Plusieurs groupes compatibles restent possibles. |
| Subject | Migré | Référentiel institutionnel réutilisable entre années, code local unique, archivage. |
| TeachingAssignment | Migré | Appartenance teacher, compte, institution/année, groupe et matière cohérents ; dates et unicité en cours. |
| CourseSeries | Migré/implémenté lot 07 | origin personal/institutional obligatoire ; propriétaire utilisateur pour personal, institution/année/groupe pour institutional, CHECK de combinaison. created_by explicite. Récurrence locale + fuseau IANA. |
| CourseOccurrence | Migré/implémenté lot 07 | Série, identifiant stable, début/fin UTC et fuseau, état/version ; conserver lien d’origine après report et références privées. |
| SchedulePublication | Contrat | Institution/année/version, auteur/date/motif/statut ; publié immuable, lecture limitée aux destinataires autorisés. Retour de version sans suppression d’historique. |
| ImportBatch | Contrat | Auteur, institution/année, empreinte/clé externe, fichier privé et état ; traitement atomique et rapport autorisé au même contexte. |
| CourseCheckIn | Contrat | owner_user_id obligatoire, occurrence, version et unicité (owner, occurrence) ; privé y compris pour plateforme. Retrait institutionnel ne supprime pas le retour personnel. |
| Task | Contrat | scope personal/group explicite ; owner obligatoire si personal, groupe et auteur habilité si collectif. Échéance officielle distincte de cible personnelle ; archivage/restauration selon politique. |
| TaskProgress | Contrat | Propriétaire utilisateur obligatoire, tâche et état/cible privés ; unique (task, owner), pas d’état collectif partagé. Conservation privée distincte de l’accès courant au devoir source. |
| Assessment | Contrat | Provenance personnelle/institutionnelle, matière/période/année cohérentes, barème/coefficient décimaux positifs, publication et auteur explicites. |
| Grade | Contrat | Apprenant destinataire obligatoire, auteur et provenance, évaluation même contexte ; données personnelles non remplaçables par une note institutionnelle. Décimaux, statuts manquant/absence/dispense distincts ; policies par délégation. |
| Attachment | Contrat | Propriétaire non nul, parent typé autorisé, visibility private/group explicite, quota/taille/hash/scan et stockage privé ; aucun null = public. Groupe cohérent et partage jamais implicite. |
| Opportunity | Contrat | Catalogue public seulement si status published et contrôle daté, auteur éditorial et source ; dates avec précision d’origine, pas de fetch arbitraire. |
| OpportunityRevision | Contrat | Fiche, version/campagne, auteur/contrôle et motif ; historique conservé. Visibilité des métadonnées internes éditoriales explicitement limitée. |
| Favorite | Contrat | Propriétaire obligatoire, fiche ; unique (owner, opportunity), aucun accès établissement au favori. |
| SavedSearch | Contrat | Propriétaire, critères et préférences d’alerte ; pas de critères privés transmis à un organisateur. |
| ApplicationTracker | Contrat | Propriétaire obligatoire, fiche/résumé daté, état et échéance privés ; clic externe ne modifie pas automatiquement le statut. Aucun dossier sensible de candidature. |
| NotificationDelivery | Contrat | Destinataire, source/contexte et clé logique/canal uniques, état/tentatives ; droits revérifiés au worker, métadonnées techniques séparées du contenu privé. |
| PushSubscription | Contrat | Propriétaire et appareil, endpoint/jetons protégés et révocables ; validation du fournisseur/endpoint sans fetch arbitraire, jamais de secret dans les logs. |
| SyncOperation | Contrat | Propriétaire + clé client + empreinte de contenu, contexte explicite et version serveur ; rejeu identique idempotent, conflit explicite, droits courants recontrôlés. |
| ConsentRecord | Contrat | Sujet, acteur/procédure, finalité, version de notice et preuve ; pas de seuil légal ou de lien parental inventé. Visibilité privée définie par finalité, jamais par null. |
| AuditLog | Contrat | acteur_type user/system explicite, acteur si user, contexte/objet/action/date/motif ; aucun contenu privé complet ou secret. Journal append-only, accès technique limité et conservation justifiée. |

### Accès et invariants exécutables
ContextVisibility exige un compte actuellement actif en base. personalSpace filtre toujours owner_id, y compris pour plateforme/éditeur. institutionMembership part des appartenances du titulaire, contrôle statut, début/fin/révocation et archivage institution/année. groups filtre le contexte puis Enrollment effectif pour learner ou TeachingAssignment effectif avec matière non archivée pour teacher ; institution_admin reste limité à son année/institution. Aucune valeur institution_id client ne suffit, aucune scope globale implicite.
Les méthodes sont des fondations de service, pas des endpoints authentifiés ni un système complet de permissions. Les futurs contrôleurs/jobs/exports devront utiliser ces frontières et autoriser leurs mutations. Les modèles/factories/seeder ne constituent pas une API de délégation de rôles.
Unicité SQL : current_slot réserve le rôle pour active/suspended, NULL après clôture ; scope_key normalise explicitement le contexte plateforme afin de ne pas laisser les NULL contourner l’index unique. Un matricule courant est unique dans institution/année, réutilisable ailleurs. Un rôle supplémentaire exige une appartenance supplémentaire, pas une concaténation de rôles.
Dates d’accès : starts_at inclusif, ends_at exclusif ; expiration/suspension/clôture retirent immédiatement la lecture. Une ligne expirée mais non close réserve encore sa place de façon conservatrice jusqu’à clôture explicite. revoked_at marque le retrait d’appartenance réel, closed_at la clôture d’inscription/affectation, même avant un début futur.
Contrainte de temporalité entre tables : validation modèle pour inclusion des périodes et préservation des enfants lors d’une modification parentale ; CHECK pour ordre local/poids. Les écritures métiers ne devront pas contourner ces validations via update SQL brut.
FK et archivage : RESTRICT partout, aucune cascade autorisée dans le socle. Les références utilisées sont archivées ; suppression physique de compte, institution, année ou groupe référencé refusée. Les procédures futures d’effacement devront traiter explicitement les dépendances et la conservation. Le down de migration est destructif et n’a pas été exécuté.
Tests de conservation privés actuellement prouvés : préférences PersonalSpace et compte après révocation, autre appartenance conservée. Les notes, retours et démarches ne sont pas encore migrés et ne sont pas déclarés testés.


## Lot 04 — identité et sessions
Écrans Inertia sous session et CSRF, sans API JSON. Routes en français : /inscription, /connexion, /deconnexion, /mot-de-passe-oublie, /reinitialisation, /verification-email, /confirmation-mot-de-passe, /activation, /profil, /etablissement/{membership}/comptes.
- Compte : users.email devient nullable ; login_identifier unique pour les comptes provisionnés ; CHECK imposant l'un des deux. Colonnes activation_secret_hash, activation_expires_at, activated_at, adult_declared_at, password_changed_at. Aucun rôle sur le compte.
- Inscription : crée User et PersonalSpace dans une transaction ; n'accepte ni rôle, ni établissement, ni matricule. Vérification par lien signé de 60 minutes ; les routes du profil exigent un compte vérifié. Un compte provisionné est réputé vérifié par son activation personnelle.
- Connexion : par adresse ou identifiant HK-, jamais par matricule. Limitation par couple identifiant + adresse IP (5 essais, 60 s) et plafond par IP sur la route. Message unique pour identifiant inconnu, mot de passe faux, compte suspendu ou non activé. Identifiant de session renouvelé.
- Récupération par email : réponse constante, y compris fréquence dépassée ; jeton à usage unique de 60 minutes ; le changement ferme toutes les sessions.
- Sessions : SessionRegistry lit la table sessions du pilote database. L'identifiant exposé au navigateur est une empreinte SHA-256, inutilisable comme cookie. Révocation ciblée ou globale après réauthentification (password.confirm, 15 minutes). Le middleware auth.session ferme aussi les autres sessions après changement de mot de passe, quel que soit le pilote.
- Compte suspendu : EnsureAccountIsActive relit le statut à chaque requête et ferme la session.
- Provisionnement (AccountProvisioner) : réservé à l'appartenance institution_admin effective de l'acteur, vérifiée par ContextVisibility. Le périmètre vient de cette appartenance, jamais d'une valeur envoyée. Le secret transite une fois par la session chiffrée de l'administrateur pour affichage ; seul son hachage est stocké.
- Second facteur : absent. EnsurePrivilegedAccess refuse les routes privilégiées, sauf dérogation locale explicite. Tout futur écran d'administration doit porter ce middleware jusqu'à la livraison du MFA.
- Journal : AuditLog en ajout seul (modification et suppression refusées par le modèle), sans secret ni contenu privé.
- Courriel : MAIL_MAILER=array en local et en test. Les notifications de vérification et de réinitialisation sont traduites (lang/fr.json) mais aucun envoi réel n'est prouvé.
Limites connues : pas de changement d'adresse électronique ni de mot de passe depuis le profil ; pas de sélecteur de contexte (lot 05) ; durée de réponse de la récupération non égalisée entre adresse connue et inconnue ; la liste des sessions exige SESSION_DRIVER=database.

## Lot 05 — invitations, contextes et autorisations
- Invitation (migrée) : scope et rôle contraints comme Membership, périmètre par FK composites, groupe et matricule réservés au rôle apprenant, empreinte unique du secret, états pending / pending_review / accepted / revoked / rejected, émetteur et appartenance émettrice, candidat, décideur, appartenance créée.
- ActiveContext : seul point de lecture du contexte. La session ne contient qu'un identifiant d'appartenance ; ContextVisibility::membership le revérifie (compte actif, appartenance effective, établissement et année non archivés). Les contrôleurs d'administration appellent require(rôle) puis une policy.
- Policies : décisions prises à partir de l'appartenance active passée explicitement, pas d'un rôle global. denyAsNotFound pour tout ce qui sort du périmètre. Les portes manage-institution, edit-catalogue et manage-platform ne servent qu'à afficher la navigation.
- InvitationService : création (périmètre déduit de l'appartenance active ; pour la plateforme, année et établissement vérifiés), révocation, acceptation transactionnelle sous verrou, décision sur les cas en attente. MembershipAdministration : retrait et départ, clôture des inscriptions et affectations.
- Traitements hors requête : DeliverInvitation porte l'acteur et son appartenance, relit les deux à l'exécution et repasse la policy ; chiffré en file car il transporte le secret du lien.
- Export : flux CSV construit à partir de l'appartenance active uniquement ; mêmes règles que l'écran.
- Journal : invitation.created, invitation.revoked, invitation.pending_review, invitation.rejected, membership.granted, membership.revoked, membership.left, privilege.escalation_denied, institution.created, members.exported, invitation.delivery_refused.
- Vue racine : directives @inertiaHead et @inertia. Les composants <x-inertia::*> sont proscrits : ils font écrire par le framework un fichier par contenu de page dans le cache de vues.
Règle pour les lots suivants : toute donnée institutionnelle se lit à partir d'ActiveContext et de ContextVisibility ; toute donnée privée se filtre par propriétaire, sans exception de rôle.

## Lot 06 — frontières avant migration (16 novembre 2026)
Référentiels institutionnels : administration uniquement dans son année active, avec middleware privilégié existant. Les enseignants consultent seulement leurs groupes et matières affectés ; ils ne modifient pas les référentiels. Les apprenants voient leurs groupes. Les matières et périodes personnelles ont des tables distinctes avec owner_id obligatoire. Les types de période sont institutionnels ou personnels, exclusifs par CHECK. Aucune institution fictive pour un indépendant.
Les périodes de même parent ne se chevauchent pas (bornes inclusives) ; les seules imbrications autorisées sont celles d'un parent explicite, de même année/périmètre. Les années actives d'un établissement ne se chevauchent pas. Les écritures prennent un verrou du périmètre et contrôlent une version pour les mises à jour. Pas de suppression : archivage, clôture datée et conservation de l'historique. Un changement de groupe clôt l'inscription avant d'en créer une nouvelle dans la même transaction.
La reprise d'année copie seulement les groupes et périodes sélectionnés, avec translation des dates et conservation des parents sélectionnés ; les matières sont déjà propres à l'établissement et réutilisables entre années. Aucun membre apprenant/enseignant, note, tâche ou donnée privée n'est copié. L'administrateur initiateur reçoit une appartenance d'administration dans la nouvelle année, tracée, pour pouvoir la sélectionner ; aucune habilitation d'un tiers.

Implémentation 06 : AcademicAdministration centralise autorité/transactions ; aucune donnée de périmètre du client ne fait autorité. Les écritures verrouillent l'institution puis l'appartenance agissante ; l'individuel verrouille son espace. La création d'année exige une confirmation récente du mot de passe et trace membership.year_continued. Le contrat JSON global utilise le mécanisme Exceptions::respond du framework installé ; code/message/errors/request_id, sans stack ni détail SQL. Les mutations Inertia des références renvoient les erreurs récupérables dans le formulaire ; les erreurs JSON conservent leurs statuts HTTP distincts. Trois tables ajoutées (types, matières personnelles, périodes personnelles), period_type_id nullable uniquement pour les anciennes périodes ou type non renseigné, jamais pour signaler public. Les FK et suppressions restent RESTRICT ; aucun DELETE de référence exposé.


## Lot 07 — frontières et décisions avant migration (16 novembre 2026)
CourseSeries porte un scope explicite personal/institution, propriétaire obligatoire pour le personnel, établissement/année obligatoires pour l'institutionnel. Aucun null ne signifie public. Révisions de règle à date d'effet, occurrences identifiées par UUID stable et clé unique série/date d'origine. Matière, groupes, enseignant et horaires sont des instantanés d'occurrence ; les modifications ne suppriment aucune ligne. Historique append-only, FK RESTRICT, contrats des tâches/retours futurs pointant l'UUID d'occurrence.
Seul le propriétaire écrit son planning individuel. Seule l'administration de l'année active écrit les brouillons institutionnels, avec le garde MFA existant ; enseignants affectés consultent leurs brouillons, apprenants n'accèdent pas aux brouillons. La publication et les instantanés publiés sont au lot 08 : aucune activation artificielle de publication ici. Administrateur plateforme sans passe-droit privé.
Récurrence hebdomadaire limitée : une plage locale par jour sélectionné, événement ponctuel possible, validité au plus 730 jours et matérialisation au plus 56 jours par demande. Fenêtre de consultation jour/semaine, pagination. Semaines actives optionnelles par lundis ISO, exclusions de dates et plages de vacances, jours non travaillés configurés par série. Instants UTC, fuseau IANA d'origine ; heure locale inexistante ou ambiguë refusée, sans normalisation silencieuse.
Les modifications du futur ajoutent une révision à date d'effet, réconcilient les occurrences déjà matérialisées en conservant leurs UUID et n'écrasent pas les exceptions manuelles. Une date retirée devient calendar_excluded, jamais DELETE ; elle peut redevenir active si une révision future la réintroduit, sauf exception manuelle. Conflits contrôlés sous verrou de périmètre : doublon exact bloqué ; autre chevauchement nécessitant confirmation et motif, uniquement propriétaire personnel/admin institutionnel. Aucune requête réseau du serveur vers un lien saisi.


### Implémentation du lot 07 et bornes
ScheduleAccess dérive le périmètre du contexte relu en base ; mutations sous verrou de l'institution/appartenance ou de l'espace individuel. ScheduleManager valide les références dans les dates de chaque séance et conserve les exceptions ; WeeklyRecurrence résout les heures locales par les transitions IANA, en exigeant un instant unique.
CourseSeriesRevision est une règle complète à date d'effet, append-only. Pour une date donnée, la version la plus récente parmi les révisions déjà effectives gagne : une modification de tout le futur remplace donc aussi les anciennes révisions différées. Chargement au plus d'une révision par date d'effet (730 dates maximum) ; historique complet conservé en base. L'interface ne charge que la dernière règle pour chaque série de sa page.
Horizon materialized_until mémorisé : lors d'un changement de jour, les semaines déjà générées au-delà de huit semaines sont également réconciliées, par blocs de 56 jours. Le traitement du futur reste limité aux 730 dates de validité depuis l'ancrage initial. Les fenêtres de lecture font un jour ou une semaine, par pages de 50 ; séries par 10, historique d'occurrence par 20.
CourseOccurrence.id est la cible UUID future des tâches/retours ; original_date est immuable via les opérations métier, même après report. Aucun DELETE de série/occurrence, FK RESTRICT. Le calendrier retiré conserve la ligne avec calendar_excluded ; cancelled + is_exception empêche sa recréation. Les révisions contiennent avant/après et motif ; leur accès reprend exactement l'autorisation de l'occurrence.
AuditLog conserve subject_id numérique et ajoute subject_uuid pour ces nouveaux objets ; aucun texte de cours ou motif personnel copié dans l'audit global. Les confirmations de conflit n'y stockent qu'un indicateur, le motif étant dans l'historique protégé.
Brouillons institutionnels accessibles uniquement à l'administration habilitée et aux enseignants concernés. Aucune publication simulée, aucune lecture des brouillons par les apprenants ; contrats et événements de publication/rappels restent aux lots 08/13. Les conflits restent locaux au propriétaire ou à l'établissement ; pas d'oracle sur l'emploi du temps privé ou étranger.

Lecture dans le fuseau du lecteur : la requête utilise l'intersection UTC avec le jour/semaine ; l'interface place une occurrence dans chaque date locale recoupée (fin exclusive). Si la conversion fait traverser minuit, les dates complètes sont affichées. Les limites de semaine ne cachent pas un cours commencé la veille ; UUID identique dans les deux vues.


## Lot 08 — publication versionnée du planning
- Deux états : course_occurrences (brouillon de travail, historique par révisions) et published_occurrences (projection lue par les membres, clé = UUID de l'occurrence, groupes dans published_occurrence_group).
- schedule_publications : version par établissement et année, unique ; kind publish ou rollback ; auteur, motif, date, résumé, empreinte de l'état publié, clé de demande unique. Modèle sans mise à jour ni suppression.
- schedule_publication_changes : une ligne par cours modifié dans une version, état avant et état après. L'état d'une version N se reconstruit en prenant, pour chaque cours, sa dernière différence de version inférieure ou égale à N.
- État canonique d'un cours : mêmes clés, triées, instants en UTC ; son empreinte SHA-256 sert à comparer brouillon et publié. L'empreinte de l'ensemble sert de jeton de prévisualisation.
- SchedulePublisher : diff (lecture seule), publish, rollback, stateAt ; tout sous le verrou d'établissement de ScheduleAccess::lock, dans une transaction qui écrit version, différences, projection, événements et audit.
- Retour arrière : passe par ScheduleManager::restore, qui réécrit le brouillon avec une révision d'historique, sans DELETE. Cours passés exclus.
- domain_events : type, clé de déduplication unique, périmètre, UUID du cours, charge utile (changement, avant/après limités aux horaires, statut, groupes, enseignant), occurred_at, processed_at. Écrite dans la transaction métier ; le lot 13 la consommera et revérifiera droits et préférences à l'envoi.
- teacher_unavailabilities : un signalement en attente au plus par cours et par enseignant (colonne générée et index unique) ; décision contrainte par CHECK.
- Lecture : ScheduleAccess::published filtre par rôle et par groupes effectifs ; ScheduleController choisit la source selon le rôle, la valeur demandée par le client ne donnant accès qu'à ce que le rôle permet.
Règle pour les lots suivants : toute information institutionnelle montrée à un apprenant vient de la projection publiée, jamais du brouillon ; les rappels partent des événements de publication.

## Lot 09 — import Excel du planning
- Chaîne : dépôt (contrôles synchrones du type réel) → import_batches en état uploaded → job AnalyzeScheduleImport (lecture bornée, simulation, rapport import_issues) → état valid ou invalid → décision de l'administration → job ApplyScheduleImport (transaction unique vers le brouillon) → état applied → publication par le lot 08.
- États d'un lot : uploaded, analyzing, invalid, valid, applying, applied, failed, contraints par CHECK. Le passage à applying se fait sous verrou de ligne : une double demande ne lance pas deux applications.
- XlsxReader : archive ouverte en lecture seule, taille décompressée et nombre d'entrées plafonnés avant toute lecture, parties XML lues en flux avec XMLReader sans accès réseau, déclaration DOCTYPE refusée. Seule la première feuille est lue, colonnes A à K, valeurs tronquées à 255 caractères.
- ScheduleImporter::resolve : fonction pure de lecture, commune à la simulation et à l'application. Elle charge les référentiels du seul périmètre de l'appartenance, produit pour chaque ligne une action (create, update, unchanged, match) et une règle au format du lot 07, et calcule une empreinte du plan.
- Application : requête de service construite pour le job (compte et appartenance de l'auteur), passée à ScheduleAccess::lock et à ScheduleManager, qui appliquent donc exactement les mêmes contrôles qu'à l'écran. Les clés de demande des séries sont dérivées du lot et de la ligne.
- Stockage : disque local privé, dossier imports par établissement, nom aléatoire indépendant du nom d'origine ; jamais sous public. parsed_rows garde les lignes lues pour l'application, purgées avec le fichier.
- Séries : colonne external_key, unique par établissement et année.
- Performance : index (institution_id, starts_at) sur course_occurrences ; la recherche de chevauchements se limite aux séances commencées dans les 12 heures précédentes, durée maximale d'une séance.
Règle pour les lots suivants : tout import ou traitement de masse suit ce schéma (simulation en lecture seule, rapport localisé, application atomique revérifiée, jamais de publication directe).

## Lot 10 — retour privé après cours
- course_check_ins : propriétaire obligatoire, UUID de l'occurrence, état, quatre champs facultatifs, instantané du titre et des horaires du cours, empreinte du contenu, version, date de clôture de la sollicitation. Unique (propriétaire, occurrence). CHECK : les champs ne sont remplis que pour l'état renseigné.
- course_check_in_events : version, état, noms des champs modifiés. Aucun contenu.
- tasks (forme minimale) : scope personal ou group contraint par CHECK, propriétaire, titre, état, priorité, échéance, occurrence source, retour source unique, clé client unique par propriétaire, version, date de mise en corbeille.
- CourseCheckIns::course décide de l'accès à un cours : occurrence personnelle du compte, ou projection publiée filtrée par les inscriptions effectives du compte, tous contextes confondus. Le brouillon institutionnel n'est jamais consulté.
- CourseCheckIns::save : verrou de l'espace personnel, contrôle de version, rejeu par empreinte, écriture du retour, de l'historique, de la tâche et de l'événement dans une transaction.
- Aucun rôle n'intervient : ni policy d'établissement, ni contexte actif. La seule policy est « propriétaire ».
- Événement checkin.settled dans domain_events, clé unique par retour, charge utile limitée au compte, à l'occurrence et à l'état.
Règle pour les lots suivants : toute donnée privée d'étude suit ce modèle — routes résolues pour le compte connecté, aucun identifiant d'objet d'autrui dans une adresse, historique sans contenu, événements sans contenu.

## Lot 11 — tâches et devoirs collectifs
- tasks étendue : matière personnelle (clé composée avec le propriétaire), établissement, année, groupe et matière (clés composées, même périmètre), appartenance de l'auteur, empreinte de création. CHECK : une tâche personnelle n'a aucun champ d'établissement ; un devoir les a tous et n'a ni propriétaire, ni matière personnelle, ni retour source. Unique (auteur, clé client).
- task_progress : devoir, apprenant, état, objectif personnel, version. Unique (devoir, apprenant).
- task_steps : tâche, propriétaire de l'étape, titre, fait, position, clé client unique par tâche et propriétaire.
- Tasks::visible est la seule requête de lecture côté apprenant : tâches personnelles du compte, ou devoirs non retirés des groupes où il est effectivement inscrit avec une appartenance utilisable, tous contextes confondus ; l'avancement joint est toujours celui du compte.
- Tasks::managed est la seule requête côté enseignant : devoirs dont le couple groupe/matière figure dans ses affectations effectives. Elle ne joint jamais task_progress.
- Écritures : verrou de l'espace personnel (tâche, avancement, étape) ou de l'appartenance d'enseignant (devoir), contrôle de version, rejeu reconnu par empreinte ou par état identique, tout dans une transaction.
- Événements dans domain_events : task.settled (clé par tâche, compte et version), homework.published, homework.updated, homework.withdrawn, homework.restored (clé par devoir et version). Charges utiles sans contenu.
- Tasks::shouldRemind est la règle que le lot 13 doit rappeler juste avant tout envoi.
- Purge : Tasks::purge supprime, une transaction par tâche, les étapes, les avancements puis la tâche restée en corbeille au-delà du délai.

## Lot 12 — notes et moyennes
- personal_grades : propriétaire, matière et période personnelles (clés composées avec le propriétaire), état, valeur, barème, coefficient, date, clé client, empreinte, version. CHECK : barème et coefficient positifs, valeur présente et comprise entre 0 et le barème seulement pour l'état noté.
- assessments : établissement, année, groupe, matière, période (clés composées : même établissement, même année), auteur, barème, coefficient, date, état brouillon/publiée/retirée, version.
- assessment_results : évaluation, apprenant, état, valeur, version. Unique (évaluation, apprenant). L'absence de ligne signifie « note manquante ».
- assessment_result_revisions : une ligne par saisie ou correction, non modifiable.
- subject_coefficients : coefficient d'une matière pour une année, unique (année, matière).
- GradeCalculator (app/Support) : fonctions pures en fractions exactes ; aucune requête, aucun arrondi avant display().
- Grades::personalSummary et Grades::publishedSummary sont les deux seules lectures côté apprenant ; chacune ne lit qu'une source et passe par la même fonction de synthèse. publishedSummary ne charge que les résultats du compte connecté.
- Grades::managed est la seule requête côté enseignant, limitée aux affectations effectives (même règle que Tasks::managed).
- Moyennes jamais stockées : elles sont recalculées à chaque lecture, donc toujours cohérentes après correction, suppression ou retrait.
- Événements dans domain_events : grade.published, grade.withdrawn (clé par évaluation), grade.corrected (clé par évaluation et version). Charges utiles limitées aux identifiants.

## Lot 13 — rappels et canaux
- user_notifications : rappel logique d'une personne — catégorie, type, titre neutre, détail réservé au centre, adresse interne, objet, échéance de référence (anchor_at), contexte de droits, clé unique (dedup_key), date programmée, date d'expiration, état programmé/remis/annulé/expiré, lu.
- notification_deliveries : envoi externe — canal, appareil éventuel, cible unique par rappel, état, tentatives, prochaine tentative, réservation, dernier code d'erreur. Aucune adresse ni contenu.
- push_subscriptions : un abonnement par appareil (empreinte unique de l'adresse), libellé, dernier envoi accepté, révocation et motif. L'adresse n'est jamais renvoyée au navigateur.
- notification_preferences : une ligne par compte ; sans ligne, valeurs par défaut.
- notification_cursors : position de reprise de chaque planification horaire, verrouillée pendant le calcul.
- Cycle (RunNotificationCycle, chaque minute) : NotificationPlanner::run (événements puis rappels de cours, de tâche, synthèses), NotificationDispatcher::release (remise dans le centre après recontrôle, création des envois), NotificationDispatcher::send (réservation en transaction courte, appel hors transaction, conclusion).
- Boîte d'événements : domain_events reste la seule source des alertes déclenchées par une action ; les lots producteurs n'appellent jamais le moteur de rappels.
- Recontrôle : NotificationDispatcher::invalid s'appuie sur les mêmes règles que les écrans (CourseCheckIns::course et shouldRemind, Tasks::visible et shouldRemind, appartenances utilisables, inscriptions effectives). Il est appelé à la remise et avant chaque envoi.
- Reprise après panne : fenêtre = max(dernière position − 2 minutes, maintenant − durée de validité). Tout ce qui est plus ancien n'est jamais planifié ; tout ce qui était programmé et a dépassé sa validité expire.
- Web Push : App\Support\Vapid (identification du serveur, OpenSSL) et App\Support\WebPush (liste fermée d'hôtes, signal sans corps, en-têtes TTL, Topic, Urgency).
- Règle pour les lots suivants (18 notamment) : pour alerter, écrire un événement dans domain_events ou créer un rappel par NotificationStore::create avec une clé unique ; ne jamais envoyer directement.

## Lot 14 — pièces jointes
- attachments : propriétaire, un seul parent (tâche ou retour de cours, contraint par CHECK), visibilité personnel/groupe (groupe seulement sur une tâche), nom affiché, nom de stockage aléatoire unique, type, extension, taille, empreinte SHA-256, état antivirus et détail, tentatives, retrait de diffusion, suppression, retrait du fichier, clé client unique par propriétaire.
- attachment_reports : fichier, auteur du signalement, établissement, motif, état ouvert/retenu/classé, décision ; un seul signalement ouvert par personne et par fichier.
- Attachments::inspect est le seul point d'entrée d'un fichier ; Attachments::store vérifie droits, limites et quota sous verrou de l'espace personnel, écrit le fichier puis la ligne, et supprime le fichier si la transaction échoue.
- Attachments::canRead est la seule règle de lecture : elle repart toujours du parent (Tasks::visible, affectation effective, propriété du retour) et de la visibilité. Attachments::canReview ouvre à l'administration les seuls fichiers de groupe signalés dans son établissement.
- Attachments::downloadable ajoute la condition antivirus : seul l'état « clean » est servi.
- ScanAttachment (file) : réservation de l'analyse en transaction, appel du scanner hors transaction, conclusion. L'interface Scanner a deux réalisations : ClamdScanner et UnavailableScanner (défaut), choisies par configuration dans AppServiceProvider.
- Fichiers hors de la base : disque « local » privé, dossier attachments/. La cohérence est rétablie par campus:attachments:maintain (fichiers sans ligne, lignes sans fichier attendu).
- Règle pour les lots suivants : tout nouveau parent de pièce jointe ajoute une colonne de parent, étend le CHECK et la règle canRead ; aucun autre code ne sert de fichier.

## Lot 15 — catalogue éditorial des opportunités
- opportunities : contenu éditorial, précision de date limite (deadline_kind, deadline_at + deadline_timezone, ou deadline_date), bornes techniques last_day_starts_at et closes_at, ancienne date (previous_deadline), état, vérification (date, auteur), édition précédente, champs normalisés pour les doublons. Trois CHECK : valeurs autorisées, cohérence de la date selon sa précision, publication impossible sans vérification.
- opportunity_revisions : instantané complet par version, action, auteur, note. Non modifiable.
- opportunity_reports et opportunity_report_events : signalement, responsable, décision ; historique de traitement non modifiable avec commentaire interne.
- Opportunities::published() est la seule requête du catalogue public ; Opportunities::open() y ajoute la borne closes_at. Aucune autre requête ne sert de fiche à un visiteur.
- Opportunities::present() est la seule représentation publique d'une fiche : elle ne contient ni auteur, ni état interne, ni note.
- Opportunities::transition() porte toutes les règles de changement d'état ; OpportunityReports::decide() l'appelle dans la même transaction pour suspendre ou archiver.
- Événements dans domain_events : opportunity.suspended, opportunity.resumed, opportunity.archived, opportunity.deadline_changed, clé par fiche et version. Le lot 18 les consommera pour informer les détenteurs de favoris ; d'ici là, le cycle de rappels les marque traités sans effet.
- NotificationStore::cancelSubject annule les rappels programmés portant sur une fiche (catégorie « opportunity »).
- Aucune requête sortante : ni vers la source, ni vers le lien de candidature.

## Lot 16 — recherche et correspondances
- opportunities : eligible_nationalities, eligible_residences (listes, vides = non précisé), normalized_fields (domaines en minuscules sans accents).
- opportunity_profiles : une ligne par compte — niveau, domaines, langues, pays de résidence, nationalité, version. Aucune route ne désigne le profil d'autrui ; rien n'est journalisé.
- OpportunitySearch::query est la seule requête de liste publique. Elle part toujours de Opportunities::open(), published() ou « publiée ou archivée après publication » : aucun paramètre ne peut y faire entrer un brouillon, une fiche à vérifier ou une fiche suspendue.
- Opportunities::identified() est la seule requête du détail public : publiée, ou suspendue/archivée après publication. Le contrôleur réduit la représentation selon l'état.
- OpportunityMatcher::compare (app/Support) : fonction pure, sans requête, sans autre entrée que la fiche et le profil déclaré.
- Labels (app/Support) : noms de pays et de langues par l'extension intl, avec repli sur le code.
- Les liens externes sont des ancres directes ; il n'existe aucune route de redirection ou de comptage.

## Lot 17 — favoris et démarches privées
- opportunity_favorites : compte, fiche, date ; unique (compte, fiche).
- opportunity_applications : compte, fiche, état, échéance personnelle, note, résumé de référence (JSON), date du dernier choix d'état, version ; unique (compte, fiche).
- opportunity_application_items : démarche, libellé, coché, position, clé client unique par démarche.
- OpportunityFollowUps est le seul service qui lit ou écrit ces tables. Toutes ses méthodes d'écran prennent le compte connecté et l'identifiant de la fiche ; aucune ne prend l'identifiant d'un favori ou d'une démarche.
- OpportunityFollowUps::followers est réservée au moteur de rappels (lot 18) et ne doit jamais alimenter un écran.
- Opportunities::reference construit le résumé ; Opportunities fige ce résumé dans les démarches lors d'une suspension ou d'un archivage, dans la même transaction.
- Aucune route ne correspond à l'ouverture d'un lien externe ; l'état d'une démarche n'a qu'un seul point d'écriture, OpportunityFollowUps::save.

## Lot 18 — recherches enregistrées et alertes
- saved_searches : compte, nom, filtres (JSON) et leur empreinte (unique par compte), fréquence, canaux, actif, instant de référence (baseline_at), dernière vérification, dernière synthèse, version.
- saved_search_matches : recherche, fiche, clé de révision, nature (nouvelle, mise à jour), détail, clé de la synthèse ; unique (recherche, fiche, révision). C'est la table de déduplication.
- notification_preferences : favorite_first_days, favorite_second_days.
- SavedSearches::planDigests est appelée par NotificationPlanner::run. Elle réutilise OpportunitySearch::query avec la clôture « ouvertes » : la requête des alertes est celle du catalogue public, donc jamais un brouillon ni une fiche suspendue.
- NotificationPlanner : planFavoriteReminders (quatrième position de reprise, « favorite_due ») et traitement des événements opportunity.suspended, opportunity.deadline_changed, opportunity.resumed pour les comptes renvoyés par OpportunityFollowUps::followers.
- NotificationDispatcher : recontrôle des types search.digest, opportunity.deadline, opportunity.updated, opportunity.resumed, opportunity.suspended ; canaux d'une synthèse relus sur la recherche au moment de l'envoi.
- Types de rappel de la catégorie « opportunity » : search.digest, opportunity.deadline, opportunity.updated, opportunity.suspended, opportunity.resumed. Tous portent un titre neutre ; le détail reste dans le centre.

## Lot 19 — PWA et cache de lecture
- Fichiers publics statiques : sw.js, offline.html, offline.js, manifest.webmanifest, icons/. Ils sont identiques pour tous et ne contiennent aucune donnée.
- Service worker : fonction strategyFor(méthode, adresse, mode, en-tête Inertia, origine) → shell, static, navigate ou network. Deux caches : campus-shell-vN (préchargé) et campus-static-vN (fichiers /build/assets/). Tout autre cache est supprimé à l'activation.
- Données privées hors connexion : IndexedDB « campus-private » (magasins snapshot et consulted) et clé localStorage « campus.offline.state » (mode, clé de compte, date de synchronisation, durée). Jamais dans Cache Storage.
- resources/js/offline/policy.ts : règles pures (decide, expired, accepts, synced), sans navigateur ; store.ts : IndexedDB, effacement, synchronisation ; runtime.ts : enregistrement du service worker, version en attente, proposition d'installation.
- CampusLayout appelle reconcile(clé du compte) à chaque ouverture : effacement ou resynchronisation selon decide.
- Serveur : GET /hors-ligne/instantane (compte connecté et vérifié) construit par OfflineSnapshot à partir des mêmes services que les écrans (Tasks::upcoming, Grades, OpportunityFollowUps, projection publiée du planning) : mêmes droits, réévalués à chaque appel.
- PreventPrivateCaching (middleware web) : « no-store, private » sur toute réponse à un compte connecté.
- Règle pour le lot 20 : les brouillons hors connexion iront dans IndexedDB sous la même clé de compte et seront effacés par purgeAll ; rien ne doit être ajouté à Cache Storage.

## Lot 20 — synchronisation des brouillons personnels
- Appareil : public/offline-core.js expose CampusOffline. Base IndexedDB « campus-private » en version 2 : magasins snapshot, consulted (lot 19) et outbox (clé id). migrate(base, ancienne version) ne fait qu'ajouter ; openDatabase se replie sur une base déjà migrée par une version plus récente.
- Opération locale : schema, id, accountKey, context, type, subject, targetId, after, baseVersion, payload, label, fingerprint, state (local, pending, synced, refused, conflict), attempts, sequence, dates, ack. L'empreinte est calculée au premier envoi ; ensuite l'opération est figée.
- createOutbox(environnement) : draft, discard, resolve, retry, synchronize, unsynced, prune. Le stockage, le réseau, l'horloge et le calcul d'empreinte sont fournis par l'environnement : le même code tourne dans le navigateur (IndexedDB, fetch) et sous Node pour les tests (mémoire, serveur simulé).
- Envoi : dans l'ordre d'écriture, une opération à la fois. 200, 409, 422 avec corps JSON : résultat définitif. Réseau, 401, 419, 429, 403, 5xx, redirection : arrêt, tout reste en attente. 419 : jeton renouvelé une fois par un GET sur /hors-ligne/instantane.
- Application : resources/js/offline/outbox.ts (accès typé, useOutbox), components/OutboxPanel.tsx, déclencheurs dans CampusLayout. Les formulaires Tâches et Retour après cours passent au brouillon quand navigator.onLine est faux ou sur onNetworkError d'Inertia ; la création garde la clé du formulaire comme identifiant d'opération.
- Page hors ligne : public/offline.js construit les formulaires et la liste des brouillons ; elle n'envoie jamais rien.
- Serveur : SyncController valide l'enveloppe sur le corps brut (l'empreinte porte sur ce que l'appareil a écrit), compare la clé de compte, puis SyncOperations::apply : verrou de l'espace personnel, recherche de l'accusé, exécution par Tasks::createPersonal, Tasks::updatePersonal ou CourseCheckIns::save, enregistrement de l'accusé dans la même transaction.
- Correspondance des erreurs : conflit HTTP 409 des services → « conflict » ; validation → « rejected / VALIDATION_FAILED » ; introuvable → « rejected / PARENT_UNAVAILABLE » ; interdit → « rejected / ACCESS_FORBIDDEN ».
- Table sync_operations : unique (user_id, operation_id) ; contrainte sur les types, le contexte « personal » et les issues ; aucun contenu saisi.
- Règle pour la suite : toute nouvelle opération synchronisable s'ajoute aux types de SyncOperation, à la contrainte de la table, à FIELDS dans offline-core.js, et passe par le service de l'écran en ligne correspondant, jamais par une écriture directe.

## Lot 21 — accueil et parcours complets
- HomeController rend « Home » avec dashboard = null pour un visiteur, un compte non vérifié ou inactif, sinon HomeDashboard::build(compte, appartenance active).
- HomeDashboard : today (OfflineSnapshot::courses sur la journée du fuseau personnel), pendingCheckIns (CourseCheckIns::pending), upcomingTasks (Tasks::upcoming), grades (Grades::personalSummary et publishedSummary, avec counted, total, missingCoefficients), opportunities (OpportunitySearch + OpportunityMatcher, seulement avec critères déclarés), individualSteps, institutionSteps (administration active seulement).
- Fuseau personnel : PATCH /profil/fuseau met à jour PersonalSpace.timezone et preferences.timezone_confirmed_at.
- Erreurs : bootstrap/app.php rend la page Inertia « Error » (autonome) pour 403, 404, 419, 429, 500, 503 hors JSON ; action non GET refusée → retour avec errors.reference sur les écrans listés, sinon flash « problem » affiché par CampusLayout.
- useUnsavedWarning(dirty, libellé) : beforeunload et événement « before » d'Inertia pour les navigations GET vers une autre page.
- CampusLayout écoute « networkError » d'Inertia pour le bandeau d'échec réseau.
- tests/browser/journeys-edge.mjs : pilote Edge ou Chrome par le protocole de débogage, sans dépendance ; champs désignés par leur libellé, boutons par leur texte ; serveur temporaire sur le port 8124 avec la dérogation locale ; traitement en file lancé à la demande pour l'analyse d'import.

## Lot 22 — droits des personnes et conservation
- Tables : privacy_acknowledgements (compte, version, empreinte, date), privacy_requests (type, état, texte, réponse, dates), data_exports (fichier privé, expiration, téléchargements). users : last_seen_at, erased_at, état « erased ».
- PrivacyNotice : versions en constante ; current(), find(), history() ; valeurs de configuration injectées à l'affichage.
- DataExporter : document(compte) par listes de colonnes ; create(), readable(), purge(), forget(). Fichiers sous exports/{compte}/ sur le disque privé.
- AccountEraser : blocker(compte) ; erase(compte, rejeu) dans une transaction, ordre de suppression respectant les clés étrangères ; fichiers retirés après validation ; journal écrit par DB::afterCommit.
- ErasureJournal : fichier de lignes JSON signées (HMAC de la clé de l'application) ; record(), read(), prune(). Chemin : campus.privacy.journal_path.
- RetentionPolicies : durations(), run(limite), inactiveAccounts(). Commandes : campus:retention:run (quotidienne, 4 h), campus:privacy:reapply-journal (manuelle, après restauration).
- Procédure après restauration d'une sauvegarde (à compléter au lot 24) : restaurer la base ; ne pas restaurer le journal depuis la même sauvegarde ; lancer php artisan campus:privacy:reapply-journal ; vérifier les nombres affichés et l'absence de lignes rejetées.
- Règle pour la suite : toute nouvelle table de données personnelles s'ajoute à DataExporter::document et à AccountEraser::erase, et le test PrivacyTest vérifie qu'elle est vide après une suppression.

## Lot 23 — durcissement
- SecurityHeaders (middleware global) : nosniff, refus des cadres, référent restreint, capteurs désactivés, politique de contenu (constante POLICY), HSTS sur requête sécurisée. Une réponse qui fixe déjà un en-tête le garde.
- ThrottlePerRoute remplace l'alias « throttle » : clé = compte (ou adresse IP) et nom de la route.
- bootstrap/app.php : trustHosts sur le nom de APP_URL.
- resources/views/app.blade.php : bloc `script[data-page="app"]` écrit avec JSON_HEX_TAG, JSON_HEX_AMP et JSON_UNESCAPED_UNICODE, suivi du conteneur `#app`.
- Règle pour les tests : chercher un texte dans le corps d'une réponse JSON se fait sur le corps décodé, jamais sur le texte brut, où les accents sont échappés.
- Outils : tests/browser/audit-edge.mjs (port 8125, relais gzip sur 8127), tests/perf/load.mjs (port 8126), campus:security:check.

## Lot 24 — exploitation, sauvegarde et supervision

- BackupCipher : chiffrement authentifié par blocs de 64 Ko (libsodium secretstream), en-tête `CAMPUSBK1` ; refuse un fichier modifié, tronqué ou une autre clé. Signature HMAC du manifeste avec la clé de sauvegarde.
- Backups : une sauvegarde est un dossier `campus-AAAAMMJJ-HHMMSS` contenant `base.sql.enc`, `fichiers.tar.enc` et `manifest.json` (empreintes, tailles, lignes par table, version). Le mot de passe MySQL passe par l'environnement du processus, jamais par la ligne de commande. Les fichiers privés sont archivés sans le journal ni le dossier de travail. `journal-effacements.enc` et `derniere-reussite.json` sont à la racine du dossier des sauvegardes.
- Restauration : uniquement vers une base vide autre que la base en service et vers un dossier vide ; contrôle du nombre de lignes par table et de l'arborescence des fichiers.
- FinalizeRestore : rejoue le journal des effacements, annule les envois en file (`restored_backup`), expire les rappels périmés, vide l'ancienne file et les battements, écrit `restore.finalized` dans l'audit.
- HealthChecks : neuf contrôles indépendants (database, queue, worker, scheduler, backup, scanner, disk, certificate, deliveries), trois états. Exposés par `campus:health` et par `GET /sante/{check?}` (en-tête `X-Monitoring-Token`, 404 sans jeton configuré, 200 ou 503).
- AppVersion : lit le fichier `REVISION` à la racine ; partagée avec l'interface (`campus.version`).
- ReleaseCheck : contrôle après mise en service (`campus:release:check`).
- Configuration : `campus.backup.*`, `campus.monitoring.*`. Planification : sauvegarde quotidienne à 2 h seulement si dossier et clé sont configurés.

## Second facteur des comptes privilégiés

- Table `second_factors` : une ligne par compte ; secret chiffré par l'application ; `confirmed_at` ; `last_used_step` (anti-rejeu) ; empreintes des codes de secours.
- Totp : RFC 6238, SHA-1, pas de 30 secondes, six chiffres, tolérance d'un pas ; adresse `otpauth://` pour les applications.
- SecondFactors : activation en deux temps (secret, puis confirmation par un code), vérification, codes de secours, retrait ; marqueur de session `campus.second_factor` lié au compte, posé après régénération de la session.
- EnsureSecondFactorVerified (groupe web) : un compte au facteur confirmé dont la session n'a pas présenté le code est renvoyé vers `/second-facteur` ; les requêtes JSON reçoivent 403. Seuls le défi et la déconnexion restent ouverts.
- PrivilegedAccess::available(?User) : dérogation locale ou de test ; sinon facteur confirmé et, s'il y a une session, code présenté dans cette session ; sans session (traitement de fond), facteur confirmé du compte agissant.
- HandleInertiaRequests : avant le code, ni contextes, ni appartenance active, ni clé de compte hors connexion.
- Routes : `/second-facteur` (défi), `/profil/second-facteur` et ses actions, toutes derrière la confirmation récente du mot de passe.
- Commande `campus:second-factor:reset`. Audit : `second_factor.enabled`, `.disabled`, `.recovery_code_used`, `.recovery_codes_regenerated`, `.reset_by_operator`.

## Connexion par compte Google

- Table `social_identities` : compte Campus, fournisseur, identifiant stable chez lui, adresse vue au rattachement ; unicité par (fournisseur, identifiant) et par (compte, fournisseur). Aucun jeton.
- GoogleSignIn : `authorizationUrl` (état, nonce, vérificateur PKCE gardés dix minutes en session, usage unique), `complete` (échange du code à l'adresse fixe de Google, contrôle des revendications). Trois intentions : `login`, `link`, `confirm`.
- GoogleSignInController : `/connexion/google` (départ, visiteur), `/connexion/google/reponse` (retour, visiteur ou compte connecté), `/connexion/google/creation` (déclaration puis création), `/profil/google/rattachement` (mot de passe confirmé), `/confirmation-google` (remplace la saisie du mot de passe par une authentification Google récente du compte relié).
- Ordre de reconnaissance à la connexion : identifiant Google déjà relié ; sinon adresse d'un compte actif ; sinon création après déclaration.
- Le compte passe ensuite par les mêmes contrôles que toute session : compte actif, second facteur, contexte.
- Configuration : `services.google.client_id`, `services.google.client_secret`. Sans les deux, bouton absent et routes en 404.
