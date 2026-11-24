# État V1 et calendrier

23 novembre 2026. Lots 00 à 25 réalisés localement, puis, sur décisions du porteur : second facteur par code temporaire, et connexion par compte Google (écrite et testée avec une réponse fabriquée, jamais essayée avec Google faute d'identifiants). Verdict inchangé : prêt pour une démonstration fictive sur le poste ; **pas prêt** pour la recette, le pilote Fondation ni l'ouverture Opportunités. Bloquants restants : courriel jamais essayé, restauration complète non réalisée, aucun hébergement, notice et cadre des mineurs non validés, support non désigné, capacité non mesurée, antivirus absent, aucun responsable éditorial. Rien n'est validé dans Git ni publié. Détail : docs/bilan-livraison-v1.md.
Statuts : non commencé / en cours / implémenté / validé / bloqué. Implémenté signifie livrable présent ; validé exige preuves et réception correspondantes.
Le cahier de prompts complet est dans docs/cahier-prompts-v1.md. Les intitulés, dépendances et scénarios des lots 04 à 25 ci-dessous ont été réalignés sur ce cahier le 15 novembre 2026 ; ils remplacent la proposition provisoire antérieure. L0–L6 du PDF sont des macro-lots différents.

## Lots

| Lot | Résultat attendu | Dépendances | Recette | Local | Recette partagée | Production |
|---|---|---|---|---|---|---|
| 00 — Référence et diagnostic | Cinq documents autonomes et traçables | — | D00 | implémenté | non commencé | non commencé |
| 01 — Socle exécutable | React/TypeScript/Inertia sur Laravel, compilation et configuration | 00 | B01–B08 ; préparation R55/R57 | implémenté | non commencé | non commencé |
| 02 — Navigation et composants communs | Interface française, navigation responsive, contexte visible, composants accessibles et atelier local | 01 | C01–C09 ; R55 partiel | implémenté | non commencé | non commencé |
| 03 — Modèle et frontières de visibilité | Socle MySQL, appartenances contextualisées, intégrité des références et contrats progressifs | 01,02 | M01–M13 ; R05/R06/R08/R09 partiels | implémenté | non commencé | non commencé |
| 04 — Comptes et sessions | Inscription, vérification, connexion, récupération, sessions révocables, comptes provisionnés ; MFA | 03 | R01,R02,R10 ; I01–I17 | implémenté sauf MFA (R10 bloqué) | non commencé | non commencé |
| 05 — Invitations et contextes | Appartenances, invitations protégées, sélecteur de contexte, policies | 03,04 | R03–R09,R58 ; K01–K14 | implémenté ; R58 partiel ; fermé sans MFA | non commencé | non commencé |
| 06 — Référentiels académiques | Années, périodes, groupes, matières, inscriptions, affectations et références personnelles | 05 | A06-01 à A06-15 ; R08/R09/R55 partiels | implémenté | non commencé | non commencé |
| 07 — Cours et occurrences | Planning jour/semaine/liste, séries bornées, occurrences stables, conflits et historique | 06 | S07-01 à S07-25 ; R11/R15/R17 ; R18/R19 partiels | implémenté | non commencé | non commencé |
| 08 — Publication du planning | Brouillon, versions immuables, retour arrière, indisponibilités | 07 | P08-01 à P08-10 ; R20 ; R16 partiel | implémenté ; fermé sans MFA | non commencé | non commencé |
| 09 — Import Excel | Modèle XLSX, simulation, rapport, lot atomique, déduplication | 08 | X09-01 à X09-13 ; R12–R15 ; R16 et R54 partiels | implémenté ; cible de durée non tenue avec marge ; fermé sans MFA | non commencé | non commencé |
| 10 — Retour privé après cours | Retour unique par occurrence, privé, tâche liée | 07,08 | L10-01 à L10-09 ; R58 ; R21, R22, R23 partiels | implémenté | non commencé | non commencé |
| 11 — Tâches et devoirs | Tâches personnelles, devoirs collectifs, progression individuelle, microtâches, corbeille | 06,10 | T11-01 à T11-09 ; R24 ; R25, R57 partiels | implémenté | non commencé | non commencé |
| 12 — Notes et moyennes | Notes personnelles, résultats publiés, moyennes indicatives expliquées | 06,05 | G12-01 à G12-04 ; R26 à R30 ; R58 (notes) | implémenté | non commencé | non commencé |
| 13 — Rappels et canaux | Centre interne, préférences, envois durables, courriel et Web Push | 08,10,11 | N13-01 à N13-04 ; R18, R21, R22, R25, R43, R53 ; R44 partiel | implémenté ; canaux externes non vérifiés | non commencé | non commencé |
| 14 — Pièces jointes | Dépôt privé, quotas, antivirus, téléchargement autorisé | 10,11 | N14-01 à N14-04 ; R49, R50 | implémenté ; antivirus réel non vérifié | non commencé | non commencé |
| 15 — Catalogue éditorial | Fiches, workflow, révisions, signalements | 05 | C15-01 ; R31, R34 à R36 ; R37, R38 partiels | implémenté | non commencé | non commencé |
| 16 — Recherche et correspondances | Catalogue public, filtres, correspondances expliquées | 15 | R32, R33, R34 ; R40 partiel | implémenté | non commencé | non commencé |
| 17 — Favoris et démarches | Favoris uniques, suivi privé | 16 | D17-01, D17-02 ; R39, R40, R41 ; R37, R38 partiels | implémenté | non commencé | non commencé |
| 18 — Recherches enregistrées et alertes | Alertes dédupliquées, rappels de favoris | 13,17 | A18-01 ; R37, R38, R42, R43 | implémenté ; canaux externes non vérifiés | non commencé | non commencé |
| 19 — PWA et cache de lecture | Manifest, service worker, stockage privé consenti | 07,11,12,17 | P19 (PwaTest, tests JavaScript, Edge) ; R47 ; R44, R55 partiels | implémenté ; contrôlé dans Edge seulement | non commencé | non commencé |
| 20 — Synchronisation des brouillons | Opérations idempotentes, conflits, purge | 10,11,19 | SyncTest ; tests JavaScript ; Edge ; R23, R45, R48 réussis en tests ; R46, R47, R56, R57 partiels | implémenté ; synchronisation réelle d'un compte en navigateur non exercée | non commencé | non commencé |
| 21 — Accueil et parcours complets | Accueil de prochaine action, onboarding, états d'interface | modules précédents | HomeTest ; deux parcours dans Edge (preuves/lot21-parcours-edge.txt) ; R57 partiel | implémenté ; essai avec une personne débutante non fait | non commencé | non commencé |
| 22 — Droits et conservation | Notices, export, effacement, purges | 14,20 | PrivacyTest ; R51 réussi en tests ; préparation R52 | implémenté ; écrans non exercés en navigateur ; notice non validée juridiquement | non commencé | non commencé |
| 23 — Durcissement et mesures | Revue sécurité, charge, accessibilité, audit-v1.md | 01–22 | HardeningTest, QueryBudgetTest ; audit-edge ; R09, R58 réussis en tests ; R54, R55, R57 partiels | implémenté ; mesure de charge complète non faite | non commencé | non commencé |
| 24 — Déploiement et restauration | Exploitation, sauvegarde, restauration éprouvée, supervision | 23 | OperationsTest (7 tests) ; R52 et R53 réussis en tests avec restauration simulée ; exercice réel partiel (docs/preuves/lot24-sauvegarde.txt) | implémenté ; restauration complète de la base non réalisée ; aucun déploiement | Pint, PHPUnit, tests JS, typage, lint, build passés | exercice de restauration à refaire avec une base isolée ; modèles de configuration jamais éprouvés sur un serveur |
| 25 — Recette et pilote | Matrice complète, guides, bilan de livraison | 24 et arbitrages | R01–R58 : 40 passés, 15 passés en test avec partie non exécutée, 2 échoués (R10, R54), 1 non exécuté (R52) ; recette-edge 19 contrôles, journeys-edge 35 contrôles | réalisé ; verdict : démonstration fictive seulement | PHPUnit 251 tests sans échec ; Edge 54 contrôles sans échec ; audits de dépendances sans alerte | recette et production non exécutées : environnements inexistants |

## Diagnostic historique au lot 00
- Aucun .git ; git status échoue. Aucune initialisation, aucun commit/push. Diff Git impossible ; revue par fichiers. Modifications préexistantes préservées.
- AGENTS.md/CLAUDE.md lus ; .ai/rules absent ; aucune instruction imbriquée trouvée dans app/routes/tests/database/resources.
- PDF complet de 39 pages lu ; extraction paginée et empreinte dans reference-cahier-v1.txt. docs/cahier-prompts-v1.md absent.
- Squelette Laravel, User/factory standard, migrations users/cache/jobs. Aucune route d'authentification ni règle Campus, membership ou policy métier observée. GET / Blade welcome, santé framework /up.
- React/TypeScript/Inertia absents ; Tailwind présent. Disque local vers storage/app/private ; aucune preuve de contrôle antivirus ou de droit de téléchargement.
- phpunit.xml : SQLite mémoire, queue sync, mail array, session array. Les deux tests ne prouvent ni MySQL ni workers ni droits.
- Versions installées : PHP utilisé 8.4.15 ; PHP PATH 8.0.30 incompatible avec php ^8.3. Composer 2.9.5 ; Laravel 13.42.0 ; Boost 2.10.4 ; PHPUnit 12.5.44 ; Pint 1.32.3 ; Node 24.12.0 ; npm 11.6.2 ; Vite 8.3.8 ; laravel-vite-plugin 3.2.1 ; Tailwind et @tailwindcss/vite 4.3.5 ; concurrently 10.0.7.
- Lockfiles présents ; aucune dépendance applicative changée au lot 00. Boost de la préparation antérieure conservé. Lecteur pypdf 7.1.0 installé uniquement dans le dossier temporaire.
- npm ls : multiplex optionnel manquant et packages wasm extraneous ; code 0.
- Tests : 2 réussis, 2 assertions, 530 ms ; assertTrue et GET /, aucun R validé.
- Build : succès, 3 modules, 234 ms, public/build généré ; avertissement fontaine optionnel. TLS Bunny précédent non reproduit, résolution durable non démontrée.
- Aucun serveur manuel, migration ni environnement distant touché. Base créée lors d'une intervention antérieure ; connexion applicative et schéma non vérifiés dans ce lot.

## Commandes disponibles
Pour Composer qui appelle @php, sélectionner PHP 8.4 :
`$env:Path = 'C:\wamp64\bin\php\php8.4.15;' + $env:Path`
- php artisan serve ; composer run dev appelle artisan dev.
- php artisan test --compact ; composer run test exécute config:clear puis tests ; vendor/bin/phpunit.
- npm run dev / npm run build ; aucun script typecheck/lint JS.
- php artisan route:list --except-vendor ; php artisan list ; composer show --direct ; npm ls --depth=0.
- php artisan queue:work / schedule:run disponibles, aucun traitement métier ou service durable validé.
- composer run setup contient key:generate et migrate --force : ne pas lancer aveuglément sur l'existant.
- migrate:fresh/refresh/reset sont destructifs et non autorisés pour ce lot.
Aucun ajout de stack au lot 00 ; compatibilité des futurs ajouts à vérifier dans les sources officielles au lot concerné.

## Calendrier et écarts
Ordre indicatif : semaine 1 lots 00–06 ; semaine 2 lots 07–12 ; semaine 3 lots 13–18 ; semaine 4 lots 19–25. Aucune date engagée ni capacité hebdomadaire fournie.
Le PDF propose 55–80 jours effectifs pour L0–L6 : estimation, pas engagement. Écart à arbitrer avec capacité réelle ; aucun P0 réduit pour tenir le mois.
Les lots 00 à 03 sont implémentés localement ; aucun P0 métier des modules futurs validé. Sans réception suffisante, démonstration fictive ; ouverture seulement de la phase dont les P0 et conditions sont satisfaits. Voir arbitrages-v1.md.

## Contrôle documentaire D00
Contrôle structurel Python exécuté : cinq documents UTF-8 non vides, 26 lots uniques 00–25, 58 cas uniques R01–R58, intitulés des lots sans fonction exclue et instructions Boost conservées avec ajout commun : succès. Relecture des cinq documents effectuée ; réception porteur non faite.


## Lot 01 — application exécutable
Livré : accueil React/TypeScript/Inertia français et responsive, Tailwind sans téléchargement de police, dates ISO UTC et fuseau IANA proposé Africa/Abidjan, messages français, bases dev/test distinctes, queue database après commit, sonde scheduler/worker, stockage privé sans route publique automatique, filtrage de logs, README reproductible et CI MySQL sans déploiement.
Le socle du starter kit est intégré à l'existant suivant l'installation manuelle officielle Inertia, sans réinitialiser ni copier les fonctions d'authentification : Fortify, inscription, vérification, récupération et MFA restent au lot identité (affectation 02 prévue historiquement, remplacée par le Prompt 02 de navigation). Aucune inscription ouverte.
Bases : hako_ra_campus_dev et hako_ra_campus_test sur MySQL 8.4.7, comptes distincts limités à leur seule base ; secrets uniquement dans les fichiers locaux ignorés. Aucun accès aux bases d'autres projets.
Premier échec migration : MyISAM était le moteur par défaut Wamp (limite index 1 000 octets). Correction : config MySQL Campus impose InnoDB ; tables users partielles vides renommées lot01_incomplete_users et conservées dans chaque base, puis migrations relancées. Aucun DROP, rollback ou migrate:fresh exécuté.
Migrations users/cache/jobs : trois exécutées sur chacune des bases, statut Ran. Tests gardés sur hako_ra_campus_test par garde serveur avant exécution ; aucune migration destructrice utilisée par les tests.
Versions PHP inchangées : PHP 8.4.15, Laravel 13.42.0 ; inertiajs/inertia-laravel 3.0.0. Versions frontend verrouillées : react 19.3.1, react-dom 19.3.1, @inertiajs/react 3.11.0, typescript 5.9.3, vite 8.3.8, @vitejs/plugin-react 6.3.0, tailwindcss 4.3.5, eslint 10.15.0, typescript-eslint 8.72.2, minimatch 11.0.8.
Les sources officielles sont liées dans README : starter kit Laravel 13, Inertia 3, React, Vite, TypeScript, ESLint, files/scheduler/localisation. Tailwind source(none) limite la détection aux sources frontend explicites.
ESLint 9 initialement installé puis remplacé par 10 maintenu. Alerte minimatch corrigée via override ^11.0.8 ; lint, typage et build repassés. Réévaluer l'override après correctif amont.
npm ci a révélé un lockfile optionnel incomplet : réparation avec npm 11.21.2 invoqué temporairement, puis npm ci rejoué avec npm 11.6.2 du poste : succès. Aucun changement global de npm ; sauvegarde des anciens node_modules retirée après succès.
Vérifications finales :
- composer install --no-interaction --prefer-dist : succès depuis lockfile ; composer validate --strict : succès.
- npm ci : succès, 199 packages installés, 206 audités.
- PHP 8.4 artisan test --compact : 8 tests réussis, 37 assertions, 2 873 ms, MySQL réel. Cela inclut deux tests initiaux du squelette.
- npm run typecheck et npm run lint : succès.
- Pint --dirty : indisponible sans Git ; Pint complet exécuté, puis pint --test : succès.
- npm run build final : succès, 582 modules, 343 ms, aucun téléchargement de police.
- composer audit et npm audit : aucune vulnérabilité signalée.
- migrate:status sur dev/test : trois migrations Ran ; schedule:list : sonde chaque minute.
- YAML du workflow parsé avec Symfony YAML existant, service MySQL présent : succès. Workflow GitHub non exécuté à distance.
- HTTP réel sur serveur local temporaire : / et assets CSS/JS 200 ; /up 200, français et données Inertia/fuseau vérifiés.
Limites : Edge headless n'a produit aucun DOM exploitable ; rendu interactif/visuel, clavier, zoom et mobile non validés par navigateur. R55/R57 restent non exécutés au complet. Pas de CI distante, SMTP, Push, antivirus ni service système permanent vérifié.
Aucun commit/push/publication ni initialisation Git. Modifications reviewables par fichiers, pas de diff Git possible sans dépôt.


## Lot 02 — navigation et composants communs
Source effective : Prompt 02 « Construire la navigation et les composants communs ». Il remplace l'intitulé provisoire « Identité et sessions » attribué au numéro 02 lors du diagnostic. L'identité reste non commencée ; regroupement proposé au 03 à confirmer au prochain prompt. Aucun P0 retiré.
Livré :
- Présentation sobre proposée, modifiable : vert profond, tons clairs, typographie système et SVG locaux, aucun achat ni dépendance applicative ajoutée.
- Layout commun : navigation latérale ordinateur, cinq entrées mobiles Accueil/Planning/Tâches/Opportunités/Profil ; Notes depuis Accueil et Espace d'études. Contexte serveur visible, actuellement Espace individuel / Visite sans connexion.
- Aucun établissement fictif, sélecteur de contexte simulé, statistique d'usage ou donnée d'apprenant. Les appartenances réelles et leur changement restent au lot identité/contexte.
- Les cinq modules non construits ouvrent en local/testing un écran explicitement provisoire ; en production, les routes sont refusées en 404 et les liens rendus indisponibles avec libellé. Accueil est le seul écran fonctionnel de présentation.
- Administration : liens uniquement si route nommée existante ET Gate serveur favorable, sur ordinateur et mobile. Aucune permission globale accordée ni route administrative métier créée. Cette règle de navigation ne remplace pas les futures policies serveur.
- Champs texte/zone de texte/sélection avec labels, aide et erreur reliées ; résumé d'erreurs ; boutons occupés ; confirmation native avec cycle Tab/Maj+Tab, Échap et restauration du focus ; liste mobile/table ordinateur, pagination, filtres réinitialisables, badges de provenance, états et succès fermable.
- Atelier /developpement/composants gardé côté serveur pour local/testing ; exemples fictifs uniquement fournis dans cet environnement, aucune saisie envoyée ou enregistrée.

Fichiers principaux : resources/js/layouts/CampusLayout.tsx, resources/js/components/ui/*, resources/js/components/Icon.tsx, resources/js/types.ts, pages Home/ModulePending/ComponentLab, app.tsx, app.css ; middleware HandleInertiaRequests, DevelopmentUiController, routes/web.php ; tests/Feature/NavigationTest.php.
Preuves :
- Suite MySQL : 13 tests réussis, 188 assertions, 2 197 ms ; 5 nouveaux tests de navigation, restrictions et permissions.
- npm run typecheck, npm run lint : succès ; npm run build final : 591 modules, 362 ms ; Pint complet et pint --test : succès. --dirty reste indisponible sans Git.
- Edge 157.0.4341.60 via outil navigateur temporaire hors dépendances du projet : 7 pages × 3 largeurs (360/768/1440), aucun défilement horizontal, contexte et titre visibles.
- Formulaire invalide, filtres, dialogue, focus initial/cyclique/restauré, Échap, message de succès, pagination, hors réseau avec saisie conservée et mouvement réduit : contrôles réussis.
- 28 déplacements Tab sur mobile : focus non masqué par les barres fixes. Lien d'évitement premier, visible et fonctionnel.
- Zoom CSS 200 % et repli équivalent 1440 vers 720 px CSS avec densité 2 : accueil/atelier sans débordement. Le raccourci de zoom natif ne change pas le viewport headless ; zoom par menu navigateur réel restant à confirmer.
- Rapport et captures : preuves/lot02-navigateur.txt, preuves/lot02-mobile.png, preuves/lot02-desktop.png. Captures relues ; aucune donnée réelle.

Échecs corrigés : résolution asynchrone typée des pages ; montage de fixture authentifiée/route nommée dans le test de permission ; cycle focus du dialogue qui sortait de la modale dans Edge. Le sélecteur du message de succès du script navigateur a été corrigé pour cibler la région status, sans changer le comportement produit.
Limites : pas d'authentification, de contexte institutionnel réel, de module métier, de PWA, d'enregistrement distant ou de statistique simulée. Safari/iOS, lecteur d'écran et zoom natif par menu non testés. R55 n'est couvert que pour le shell et les composants actuels ; R57 n'est pas validé par un formulaire d'atelier sans requête réseau.
Aucun changement de lockfile, migration, commit, push, publication ou service payant. Git absent : pas de diff Git disponible ; revue par les fichiers et captures.


## Lot 03 — modèle et frontières de visibilité
Le Prompt 03 reçu remplace l'intitulé provisoire Identité/contextes par le socle de données et ses frontières. L'authentification, les invitations et leurs parcours restent non implémentés ; aucun P0 n'est retiré, affectation exacte à rapprocher des prompts suivants.
Avant la migration : schéma Laravel existant examiné ; frontières compte/espace privé/institution/année/groupe/affectation/publié/privé consignées dans architecture-v1.md. Dépôt toujours sans Git, aucun code réinitialisé.
Implémenté :
- User préservé, statut active/suspended ajouté ; aucun users.role. PersonalSpace distinct avec owner_id obligatoire/unique et préférences privées.
- Neuf tables nouvelles : institutions, personal_spaces, academic_years, periods, groups, subjects, memberships, enrollments, teaching_assignments. Dates/fuseaux, archivage, fenêtres d'accès et retraits datés.
- Rôle sur Membership, scope institution/platform explicite ; un compte peut être learner A, teacher B et indépendant. Plateforme/éditeur sans institution ni année par contrainte explicite ; aucun null ne signifie public.
- FK composites entre année/institution, groupes, membres, utilisateurs, rôles et matières ; CHECK locaux aux lignes, index uniques des relations en cours incluant les suspensions. Clôture libérant le créneau tout en gardant l'historique.
- Validation des dates et parents, interdiction de déplacer le contexte d'une référence existante, préservation des périodes/sous-périodes lors d'un changement des dates parentes.
- ContextVisibility filtre propriétaire, compte actif en base, appartenance effective, année/institution non archivées, groupe et affectation. Aucun tenant global ni bypass plateforme. Il s'agit de services de fondation, pas d'endpoints ou de toutes les policies futures.
- Retrait Membership par statut/revoked_at, clôture Enrollment/TeachingAssignment par closed_at ; aucun DELETE/cascade. Les FK sont RESTRICT.
- Factories cohérentes ; CampusDemoSeeder idempotent et fermé hors local/testing et hors bases Campus dédiées. Données A/B, indépendant, double apprenant, enseignant A/G1, admins A/B, éditeur, profil polyvalent et administrateur plateforme fictif. Adresses example.test, mots de passe aléatoires non partagés et non affichés.
- Contrats des 30 entités demandées + espace individuel documentés dans architecture-v1.md. Pas de migration/CRUD vide pour les entités futures.

Fichiers principaux : migration 2026_11_15_200703_create_campus_foundation_tables.php ; app/Models et app/Services/ContextVisibility.php ; factories, CampusDemoSeeder/DatabaseSeeder ; tests/Feature/CampusFoundationTest.php.
Vérifications réellement exécutées :
- Migration additive sur hako_ra_campus_test puis hako_ra_campus_dev : succès, statut Ran sur les deux, quatre migrations au total. Aucun rollback/down/migrate:fresh.
- Seeder test : succès ; seeder dev exécuté deux fois : succès, sans double appartenance.
- 20 tests ciblés MySQL, 77 assertions, 4 151 ms ; suite complète : 33 tests, 265 assertions, 5 535 ms, tous réussis.
- Pint --dirty indisponible sans Git ; Pint complet puis pint --test : succès.
- Métadonnées SQL réellement relues : FK, CHECK, index uniques présents. Fixtures dev : 2 institutions, 8 espaces privés, 9 appartenances, 3 inscriptions et 2 affectations.
- Preuves : preuves/lot03-schema-mysql.txt (schéma sans données de ligne) et preuves/lot03-verifications.txt (sorties observées).
Échecs corrigés : génération de namespaces/imports ; test de protection du seeder ciblant initialement le dialogue de confirmation Laravel plutôt que son garde interne. La protection de production n'a pas été désactivée : le test appelle le garde du seeder directement, qui refuse avant toute écriture.
Frontend et dépendances inchangés : pas de nouveau build/typage/audit répété dans ce lot ; la suite complète couvre l'accueil et la navigation existants. CI distante non exécutée.
Limites : aucun écran d'authentification, de sélection institutionnelle ou de CRUD métier ; aucune invitation ou donnée de note/retour/démarche migrée. La conservation prouvée porte sur PersonalSpace/préférences et compte, pas sur ces futures entités. Types et chevauchements de périodes, délégations officielles, rôles accordés/récupération de compte et journaux métier complets restent aux lots correspondants. Aucune capacité ou conformité juridique annoncée.


## Lot 04 — comptes et sessions
Source : Prompt 04 du cahier. Décision du porteur pendant le lot : aucune dépendance ajoutée, donc second facteur et codes de secours non livrés. IAM07 et R10 sont bloquants pour tout compte privilégié réel ; les actions privilégiées sont refusées par défaut.
Livré :
- Inscription par adresse électronique avec explication des données, mot de passe de 10 caractères minimum, création atomique du compte et de l'espace individuel. Aucun rôle, établissement ou matricule accepté.
- Vérification par lien signé de 60 minutes, renvoi limité ; profil fermé tant que l'adresse n'est pas vérifiée. L'écran signale que l'envoi de courriels n'est pas configuré.
- Connexion par adresse ou identifiant, limitation des tentatives, message unique, renouvellement de session ; déconnexion.
- Récupération non énumérante, lien de 60 minutes à usage unique, fermeture de toutes les sessions au changement.
- Profil : nom modifiable, appartenances, sessions actives consultables et révocables après réauthentification.
- Comptes provisionnés sans adresse (IAM02) : écran d'administration d'établissement, identifiant HK- aléatoire, secret temporaire personnel de 72 h affiché une seule fois, activation qui fixe le mot de passe personnel, récupération assistée avec contrôle d'identité et trace limitée.
- Parcours mineurs : mécanisme configurable, fermé par défaut, sans seuil d'âge codé.
- Cookies : httponly, samesite lax, secure par défaut en production. Réauthentification valable 15 minutes.
- Journal audit_logs en ajout seul.

Fichiers principaux : migration 2026_11_15_213000_add_identity_columns_and_audit_logs.php ; config/campus.php ; app/Http/Controllers/Auth/*, ProfileController, Institution/ProvisionedAccountController ; app/Services/AccountProvisioner.php, SessionRegistry.php, ContextVisibility.php ; app/Http/Middleware/EnsureAccountIsActive.php, EnsurePrivilegedAccess.php ; app/Models/User.php, AuditLog.php ; routes/web.php ; resources/js/pages/auth/*, Profile.tsx, institution/ProvisionedAccounts.tsx ; tests/Feature/IdentityTest.php, ProvisionedAccountTest.php.
Vérifications réellement exécutées :
- Migration additive sur hako_ra_campus_test puis hako_ra_campus_dev : succès, cinq migrations Ran sur chacune. Aucun rollback ni migrate:fresh.
- php artisan test --compact : 54 tests réussis, 614 assertions, 14 621 ms sur MySQL.
- Pint : corrigé puis passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 449 ms.
- Parcours HTTP réel (serveur local temporaire, base dev) : inscription, refus du profil non vérifié, déconnexion, connexion, refus CSRF 419, attributs des cookies. Détail dans recette-v1.md.
Échecs rencontrés : 7 tests en échec au premier passage, tous dus au harnais qui partageait session et garde entre utilisateurs simulés ; corrigé par TestCase::freshDevice. Aucun contrôle applicatif désactivé.
Modifications de l'existant : NavigationTest adapté, /profil étant désormais un écran réel protégé ; accueil mis à jour avec les liens de connexion et d'inscription.
Limites et non vérifié :
- Second facteur absent : R10 bloqué, aucun pilote établissement ou éditorial ouvrable.
- Aucun courriel réellement envoyé ni reçu (MAIL_MAILER=array) : un compte créé par email ne peut pas être vérifié tant qu'un service d'envoi n'est pas configuré.
- Écrans non contrôlés en navigateur (360 px, clavier, zoom) ; R55 non étendu à ces écrans.
- Pas de changement d'adresse ni de mot de passe depuis le profil ; pas de sélecteur de contexte ni d'invitation (lot 05).
- Les comptes fictifs du seeder ont des mots de passe aléatoires : l'écran d'administration n'est testable à la main qu'avec un administrateur fictif dont le mot de passe est connu.
- Un compte fictif smoke-lot04-31960@example.test a été créé dans la base dev par le contrôle HTTP.
- Dépôt toujours sans Git ; CI distante non exécutée.


## Lot 05 — invitations et isolation des contextes
Source : Prompt 05 du cahier. Second facteur toujours absent : toutes les routes d'administration portent le middleware privileged et répondent 403 hors dérogation locale.
Livré :
- Invitations : secret aléatoire de 32 octets dans le lien, seule son empreinte SHA-256 est stockée ; validité 14 jours (proposée) ; révocables ; consommées une seule fois sous verrou de ligne.
- Acceptation : écran public qui propose d'abord la connexion au compte existant, retour automatique à l'invitation après connexion. Rôle et périmètre lus dans l'invitation, jamais dans la requête. Refus si le compte a déjà ce rôle dans ce périmètre.
- Rapprochement : matricule déjà rattaché à un autre compte du même établissement, ou adresse du compte différente de l'adresse invitée : demande en attente de contrôle, sans aucun accès ; l'administration approuve ou refuse.
- Qui accorde quoi : l'administration d'établissement invite apprenants et enseignants de son établissement et de son année ; seule la plateforme crée un établissement (avec sa première année) et habilite une administration ou un éditeur. Rôle privilégié : réauthentification, confirmation à l'écran et trace.
- Contexte actif : sélecteur dans l'en-tête et sur le profil ; identifiant gardé en session et relu en base à chaque requête. Appartenance retirée, suspendue, expirée ou archivée : retour immédiat à l'espace individuel.
- Retrait et départ : retrait par l'autorité du périmètre ou départ volontaire ; inscriptions et affectations closes, compte et espace individuel conservés.
- Autorisations : policies Invitation, Membership, Institution et PersonalSpace ; aucun Gate::before ; ressources hors périmètre introuvables (404) sans indice d'existence.
- Export CSV des membres limité au périmètre actif, sans adresse électronique, cellules de formule neutralisées, tracé.
- Job DeliverInvitation chiffré en file, qui revérifie les droits de l'émetteur à l'exécution.

Fichiers principaux : migration 2026_11_15_230000_create_invitations_table.php ; app/Models/Invitation.php ; app/Services/InvitationService.php, ActiveContext.php, MembershipAdministration.php, ContextVisibility.php ; app/Policies/* ; app/Jobs/DeliverInvitation.php ; app/Http/Controllers/Admin/*, ContextController, InvitationAcceptanceController, ProfileController ; app/Providers/AppServiceProvider.php ; routes/web.php ; resources/js/pages/admin/*, InvitationShow.tsx, Profile.tsx, layouts/CampusLayout.tsx ; tests/Feature/InvitationContextTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 05. 68 tests réussis, 1 010 assertions ; Pint, typage, ESLint et build passés ; migration appliquée sur test et dev ; contrôle HTTP réel.
Anomalie héritée du lot 01 corrigée : la vue racine écrivait les données de chaque page dans le cache de vues (313 fichiers, dont 9 avec un secret temporaire fictif). Vue racine passée aux directives @inertia, cache vidé, test de non-régression ajouté. Sur tout autre poste ayant exécuté l'application avant cette correction, lancer php artisan view:clear.
Modifications de l'existant : NavigationTest (l'entrée Établissement existe désormais, le test utilise l'entrée Catalogue encore absente) ; EnsurePrivilegedAccess factorisé dans App\Support\PrivilegedAccess ; libellés de rôle déplacés sur Membership.
Limites et non vérifié :
- R58 partiel : retours de cours, notes personnelles et démarches n'existent pas encore ; à reprendre aux lots 10, 12 et 17.
- Pas de suspension ni de changement de rôle en place : un changement de rôle passe par un retrait puis une nouvelle invitation.
- Affectation d'un enseignant à un groupe et une matière : lot 06. Une invitation d'apprenant peut déjà viser un groupe.
- Aucun envoi réel de courriel ; le lien affiché une fois sert de repli.
- Écrans non contrôlés en navigateur ; acceptation réellement concurrente non testée.
- Purge des invitations expirées : lot 22.
- Dépôt Git initialisé localement, fichiers indexés, aucun commit ; envoi vers GitHub en attente d'un accès.


## Lot 06 — référentiels académiques (16 novembre 2026)
Vérification préalable 04/05 : instructions, état Git et références relus ; code comptes/sessions, invitations, contexte, policies et retraits inspecté. Suite héritée : 68 tests réussis, 1 012 assertions, 25 925 ms. Les preuves ne lèvent ni R10/MFA ni l'absence de réception réelle de courriels. Le dépôt est désormais Git, avec fichiers déjà indexés et modifications existantes : préservés, aucun commit/push.

Livré :
- Écran Inertia /etudes/referentiels : années, périodes, groupes/classes, matières, types de période, inscriptions, affectations ; recherche, pagination par 20, états vides, formulaires conservés après validation, confirmation et succès. Sélecteurs bornés à 200, recherche pour accéder aux autres propositions.
- Autorité institutionnelle limitée au contexte actif et à son année ; refus de A sur B, enseignant en consultation selon ses affectations, jamais de mutation enseignant. Administration refusée sans dérogation locale existante, y compris la consultation dans ce contexte privilégié ; aucune activation de MFA simulée.
- Types configurables ; périodes imbriquées uniquement par parent explicite, dans ses dates, sans chevauchement entre frères. Dates de période inclusives ; fenêtres d'inscription semi-ouvertes [entrée, sortie[. Fuseau IANA institutionnel, heures d'entrée/sortie converties en UTC. Fuseaux des sous-périodes personnelles identiques au parent.
- Archivage sans DELETE ; année archivée ferme son contexte ; sorties et changements de groupe conservés. Changement effectif non futur clôturant l'ancien groupe et créant le nouveau atomiquement ; les affectations futures peuvent être annulées par clôture datée.
- Reprise sélective vers une nouvelle année : groupes/périodes et hiérarchie, translation des dates ; refus/rollback si sélection étrangère ou incompatible. Matières réutilisées au niveau établissement, aucun apprenant/enseignant ou contenu privé copié. Nouveau contexte d'administration pour le seul initiateur.
- Matières et périodes personnelles dans des tables distinctes, owner_id obligatoire ; aucun établissement fictif ni matricule. Types avec scope exclusif institution/propriétaire par CHECK et FK composites pour les périodes.
- Verrou de périmètre et vérification d'appartenance en base dans la transaction ; versions sur mises à jour, doublons refusés, audit sans contenu privé. Journalisation d'une création d'année avec habilitation de continuation explicitée dans l'interface.
- Contrat commun d'erreurs JSON branché lors de la revue 04/05 : codes stables, messages français, erreurs de champs, request_id et en-tête ; aucun détail interne ou nom de ressource étrangère.

Fichiers principaux : app/Services/AcademicAdministration.php ; app/Http/Controllers/AcademicReferenceController.php ; resources/js/pages/AcademicReferences.tsx ; migration 2026_11_16_075845_add_academic_administration.php ; modèles/factories PersonalSubject, PersonalPeriod, AcademicPeriodType ; tests/Feature/AcademicAdministrationTest.php ; app/Support/JsonErrorResponse.php, bootstrap/app.php et traductions. DatedAssignment::close date aussi la sortie et incrémente la version ; le test de remplacement historique fournit désormais sa propre entrée/sortie.
Migration additive appliquée sur hako_ra_campus_test puis hako_ra_campus_dev ; sept migrations Ran sur chaque base. Aucun rollback/fresh ni donnée d'un autre projet touchée. Preuves et commandes finales : recette-v1.md et preuves/lot06-verifications.txt.

Limites : MFA et transport mail restent bloquants ; aucune production/CI distante testée. Concurrence simultanée non éprouvée en charge (verrous/contraintes testés séquentiellement). Zoom natif 200 %, lecteur d'écran et Safari/iOS non vérifiés. Les notes/tâches/retours privés des lots futurs ne sont pas prétendus migrés ou validés. Les dates d'heure ambiguë lors du changement saisonnier ne constituent pas une validation du moteur de planning du lot 07.

Résultat final lot 06 : 83 tests, 1 187 assertions, 28 330 ms ; typecheck/lint/Pint/build réussis (605 modules, 458 ms), diff sans erreur de blancs. Quinze tests supplémentaires. Compte fictif navigateur désactivé et serveur temporaire arrêté. Création de nouvelle année réauthentifiée, continuation d'administration auditée ; conflits Inertia conservant les formulaires et erreurs JSON structurées testés.


## Lot 07 — cours et occurrences (16 novembre 2026)
Livré localement :
- /planning réel protégé, vues jour/semaine/liste ; grille à sept jours sur grand écran, liste mobile, recherche et pagination de 50 occurrences. Séries par 10 et historique par 20. Navigation production raccordée ; accueil corrigé pour refléter les fonctions effectivement disponibles.
- CourseSeries, CourseOccurrence, leurs révisions append-only et table des groupes d'occurrence. UUID stable et unicité série/date d'origine ; scope personal/institution explicite, FK propriétaires et contextes, instantanés d'horaires et références, aucune suppression de cours.
- Récurrence hebdomadaire ou événement ponctuel, week-end personnel autorisé ; semaines actives, jours non travaillés, exclusions et vacances configurables. 730 jours inclusifs de validité au maximum depuis l'ancrage initial, fenêtres générées de 56 jours inclusifs maximum, renouvellement explicite rejouable. Une plage quotidienne, durée maximale de 12 h et sans traversée de minuit.
- Heures locales et IANA conservés, instants UTC ; Europe/Paris testé au printemps/automne. Heures sautées ou répétées refusées sans choix arbitraire d'un instant. Date exclue possible pour éviter une transition.
- Report, annulation, rétablissement et modification d'une occurrence avec motif/version ; date d'origine, UUID et historique conservés. Révision du futur à date d'effet, nouvelle version, réconciliation jusqu'à la borne déjà matérialisée ; anciennes dates retirées marquées calendar_excluded, nouvelles dates créées. Exceptions manuelles préservées ; dernière révision applicable prioritaire, y compris sur une ancienne révision différée.
- Doublons exacts interdits même avec confirmation ; chevauchements groupe/enseignant/salle ou agenda personnel exigeant confirmation explicite et motif. Refus/rollback atomique sans écritures partielles. Motif conservé dans l'historique autorisé, pas copié en texte libre dans l'audit global.
- Propriétaire seul sur son personnel ; administration de l'année active seule en écriture institutionnelle avec garde privilégié existant ; enseignant affecté en prévisualisation sans écriture. Apprenant sans accès aux brouillons et sans mutation institutionnelle. Aucun passe-droit plateforme. Retraits/archivage revérifiés à chaque opération et affectations valides sur toute la séance.
- Extension additive du journal : subject_uuid distinct du subject_id numérique existant, sans convertir ni effacer l'historique.

Fichiers principaux : app/Services/ScheduleAccess.php, WeeklyRecurrence.php, ScheduleManager.php ; app/Http/Controllers/ScheduleController.php ; app/Models/Course* ; trois migrations 2026_11_16_081825, 082300 et 082701 ; resources/js/pages/Schedule.tsx et ScheduleHistory.tsx ; tests/Feature/ScheduleTest.php. Tests de navigation adaptés au vrai planning protégé.
Vérifications : trois migrations additives sur test puis dev ; dix migrations Ran sur chaque base, aucun rollback/fresh. Suite finale : **108 tests réussis, 1 503 assertions, 40 218 ms**, dont 25 nouveaux tests du planning. Typage, ESLint, Pint et build réussis (607 modules, 478 ms). Diff sans erreur de blancs ; versions et preuves dans preuves/lot07-verifications.txt.
Navigateur réel Edge : création hebdomadaire/personnelle, samedi, report stable, annulation, trois versions d'historique, révision future, brouillon institutionnel, enseignant en lecture seule, neuf combinaisons vue/largeur sans débordement ; exclusions/vacances saisies, refus d'un doublon avec formulaire préservé, hors connexion, zoom CSS 200 % et 40 focus mobiles visibles/non masqués. Captures relues.
Corrections pendant le lot : journal initialement limité aux identifiants numériques, étendu avec une colonne UUID ; fixture de pagination corrigée pour ne pas muter la date de début en calculant la fin ; priorité d'une nouvelle révision du futur explicitement testée.
Limites : publication versionnée/indisponibilités = lot 08 ; rappels = lot 13. R18 est seulement couvert sur l'état annulé et sa non-régénération, sans prétendre annuler un rappel inexistant. R19 prouve le refus de mutation institutionnelle et l'ajout personnel ; planning publié non livré. Tâches/retours non migrés : UUID/FK RESTRICT préparés et conservation prouvée des occurrences, pas de faux test d'intégration avec ces objets futurs. MFA toujours absent, administration réelle fermée ; mail réel/CI distante/production non vérifiés. Zoom natif, lecteur d'écran, Safari/iOS, charge et concurrence simultanée non validés. Les conflits sont limités au périmètre autorisé, sans révéler les cours d'un autre établissement.
Recette locale terminée : brouillon institutionnel fictif annulé par le service métier ; compte fictif désactivé, deux appartenances retirées et sessions fermées ; secret temporaire supprimé, serveur 8017 arrêté. Données personnelles fictives et historique conservés dans dev, aucun autre compte existant modifié par cette recette.

Dernier contrôle S07-25 : une conversion de fuseau traversant minuit/semaine n'occulte plus le cours ; affichage dans chaque journée recoupée avec dates complètes, même UUID. Vérifié en navigateur et inclus dans les 108 tests / 1 503 assertions ci-dessus. Le second compte fictif et le serveur temporaire de ce complément ont été fermés, aucun secret conservé.


## Lot 08 — publication et révision du planning (16 novembre 2026)
Vérification préalable 06/07 : code des référentiels et du planning relu, suite héritée rejouée (108 tests, 1 503 assertions). Aucun défaut bloquant ; constats dans recette-v1.md.
Livré :
- Brouillon et publié séparés. Le brouillon reste course_occurrences ; chaque publication crée une version immuable (auteur, date, motif, résumé) et ses différences avant/après par cours.
- Prévisualisation : comparaison du brouillon à la version en vigueur, liste paginée, compteurs. La publication ne s'applique que si la version et l'empreinte prévisualisées sont toujours exactes ; sinon conflit, rien n'est écrit.
- Lecture : les apprenants voient uniquement le planning publié de leurs groupes effectifs ; les enseignants leurs cours publiés par défaut, le brouillon sur demande ; l'administration les deux. Dernière publication affichée.
- Retour arrière : nouvelle version qui rétablit l'état d'une version antérieure pour les seuls cours à venir. Le brouillon est réaligné ; les cours ajoutés depuis sont retirés sans suppression et marqués pour ne pas réapparaître. Identifiants, date d'origine et historique conservés.
- Indisponibilité : signalement par l'enseignant du cours, en attente ; décision de l'administration (refus, annulation dans le brouillon, report ou remplacement à préparer). Le planning publié ne change qu'à la publication suivante.
- Événements métier : boîte domain_events écrite dans la transaction de publication, un événement par cours modifié et un par version, clé de déduplication unique. Aucun envoi dans ce lot.
- Destinataires : ScheduleAccess::recipients calcule, au moment de l'appel, les inscrits effectifs des groupes et les enseignants désignés.
- Confirmation explicite à l'écran avant publication et avant retour arrière, motif obligatoire.

Fichiers principaux : migration 2026_11_16_100000_create_schedule_publication_tables.php ; app/Services/SchedulePublisher.php, ScheduleAccess.php, ScheduleManager.php (méthode restore) ; app/Http/Controllers/SchedulePublicationController.php, ScheduleController.php ; app/Models/SchedulePublication.php, SchedulePublicationChange.php, PublishedOccurrence.php, DomainEvent.php, TeacherUnavailability.php ; resources/js/pages/SchedulePublication.tsx, Schedule.tsx ; tests/Feature/SchedulePublicationTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 08. 114 tests réussis, 1 840 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Incident de migration : premier essai en échec sur la base de test (mot réservé non protégé). Migration corrigée, deux tables vides créées par cet essai retirées sur la seule base de test après vérification, puis exécution réussie. Aucune autre table ni donnée touchée ; aucun rollback ni migrate:fresh.
Modifications de l'existant : ScheduleController (source draft ou published), Schedule.tsx (bandeau de publication, bascule, signalement), un test du lot 07 adapté, migration du lot 07 reformatée par Pint.
Limites et non vérifié :
- R16 partiel : les rappels n'existent pas encore ; le lot 13 doit consommer domain_events et vérifier le recalcul et l'absence de renvoi.
- Second facteur absent : publication, retour arrière, décision et signalement fermés hors dérogation locale.
- Retour arrière : pas de contrôle de conflit ni de revalidation des références (enseignant retiré ou groupe archivé depuis) sur l'état restauré ; une modification de brouillon non publiée sur un cours restauré est écrasée.
- Report ou remplacement après signalement : préparé à la main par l'administration avec les outils du lot 07, puis publié.
- Écrans non contrôlés en navigateur ; simultanéité réelle et volume non mesurés ; limite proposée de 20 000 occurrences par publication.
- Constat hérité ouvert : contexte plateforme actif = 403 sur /planning et /etudes/referentiels.
- Git : dépôt local sans commit ; envoi vers GitHub toujours en attente d'un accès.
Complément du lot 08 (décisions déléguées par le porteur, voir arbitrages-v1.md) : le retour arrière ignore et compte les cours dont les références ne sont plus valides et signale les chevauchements sans se bloquer ; un contexte plateforme affiche le planning et les références personnels. Cas P08-08 à P08-10 ; suite à 117 tests et 1 965 assertions. Les deux limites correspondantes ci-dessus (absence de revalidation au retour arrière, 403 en contexte plateforme) sont levées ; reste qu'une modification de brouillon non publiée sur un cours rétabli est écrasée.


## Lot 09 — import Excel du planning (16 novembre 2026)
Livré :
- Modèle XLSX téléchargeable : feuille Planning (en-têtes A à K) et feuille Notice (colonnes, formats de date et d'heure, fuseau de l'établissement, limites). Texte seul, sans macro ni formule.
- Dépôt dans le stockage privé après contrôle de l'extension, de la taille (5 Mo proposés), de la signature et de la structure réelle du classeur. Macros, objets intégrés, liens externes, chiffrement, chemins internes anormaux et décompression excessive refusés.
- Lecture bornée et sans exécution : première feuille seulement, 5 000 lignes proposées, 3 feuilles au plus, formules signalées et jamais calculées, aucune entité ni ressource externe chargée.
- Simulation en file, sans aucune écriture dans le planning : rapport par feuille, ligne et colonne, avec erreurs bloquantes, conflits et avertissements ; progression et échec visibles ; rapport CSV téléchargeable, cellules de formule neutralisées.
- Références inconnues signalées, jamais créées : groupe, matière, enseignant, affectation.
- Répétition empêchée : empreinte du fichier unique par établissement et année, clé d'import pour le rejeu, lignes déjà présentes ignorées, doublons dans le fichier bloquants.
- Mise à jour par identifiant externe stable (nouvelle colonne external_key des séries) ; sans identifiant, correspondance proposée et décision exigée avant application.
- Application en file, dans une seule transaction, au seul brouillon : droits, références et résultat de la simulation revérifiés à l'exécution ; toute erreur annule l'ensemble. La publication reste celle du lot 08.
- Purge quotidienne du fichier brut et des lignes lues après 30 jours proposés ; rapport et bilan conservés.

Choix technique : aucune dépendance ajoutée. Le format XLSX est lu et écrit avec les extensions natives zip et xmlreader de PHP (app/Support/Xlsx), ce qui permet de maîtriser les limites de ressources.
Fichiers principaux : migrations 2026_11_16_120000 et 121000 ; app/Support/Xlsx/XlsxReader.php, XlsxWriter.php ; app/Services/ScheduleImporter.php ; app/Jobs/AnalyzeScheduleImport.php, ApplyScheduleImport.php ; app/Http/Controllers/ScheduleImportController.php ; app/Console/Commands/PurgeImportFiles.php ; app/Models/ImportBatch.php, ImportIssue.php ; resources/js/pages/ScheduleImport.tsx ; tests/Feature/ScheduleImportTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 09. 130 tests réussis, 1 ignoré, 2 222 assertions ; Pint, typage, ESLint et build passés ; migrations sur test et dev.
Mesure réelle de 1 000 lignes (preuves/lot09-mesure-1000-lignes.txt) : analyse avec rapport 2,9 s ; application au brouillon 118,4 s ; total 121,3 s sans débogueur, 335,4 s avec Xdebug actif sur ce poste. Premier passage avant optimisation : 614,7 s. La cible de 2 minutes n'est pas tenue avec marge ; elle reste à vérifier sur l'environnement cible au lot 23.
Modification de l'existant (lot 07) : ScheduleManager optimisé sans changement de comportement (borne de 12 heures et index pour la recherche de chevauchements, lectures groupées, références lues une fois par traitement) ; suite identique avant et après.
Limites et non vérifié :
- Second facteur absent : import utilisable seulement avec la dérogation locale.
- Durée : cible à la limite sans débogueur, non tenue avec ; piste restante dans le fichier de preuve.
- Aucun fichier produit par Excel, LibreOffice ou Google Sheets n'a été testé ; aucun essai navigateur ; worker réel non éprouvé.
- Une ligne = un cours, un groupe, un jour. Vacances, semaines actives et liens de visioconférence ne s'importent pas ; ils se saisissent ensuite dans le brouillon.
- L'enseignant se désigne par son adresse électronique ou son identifiant HK- : il n'existe pas de code enseignant dans les référentiels.
- Détection des conflits à la simulation limitée à la première fenêtre de 56 jours ; l'application refait le contrôle complet du lot 07.
- La purge exige que le planificateur tourne.


## Lot 10 — retour privé après cours (16 novembre 2026)
Livré :
- Formulaire de retour ouvert depuis l'accueil (« Votre prochaine action »), le planning et l'écran « Mes retours » : résumé personnel, travail à effectuer, points à revoir, niveau de compréhension. Tous les champs sont facultatifs ; « Rien à signaler » en un clic.
- États : non renseigné (aucune ligne), reporté (reste proposé), ignoré (n'est plus proposé mais reste renseignable), renseigné, rien à signaler. Aucune pénalité, aucun classement, aucune IA.
- Un retour au plus par propriétaire et par cours (contrainte unique). Modifiable ; historique limité à la version, à l'état et aux noms des champs modifiés, jamais à leur contenu.
- Cours ouvrant un retour : son propre événement personnel, ou un cours publié d'un groupe où l'on est effectivement inscrit, à partir du début du cours. Jamais un brouillon, jamais un cours annulé.
- Confidentialité : toutes les routes sont limitées au compte connecté ; aucune route ne désigne le retour d'une autre personne. Policy propriétaire seul, sans exception de rôle. Le retour reste à son propriétaire après retrait d'une appartenance.
- Tâche facultative créée depuis le retour, dans la même transaction, une seule fois par retour, rattachée au cours.
- Contrat rejouable pour le lot 20 : expected_version (0 à la création), rejeu identique sans effet, version dépassée = 409 ; réponse JSON au format d'erreur commun si elle est demandée.
- Pour le lot 13 : règle shouldRemind (cours toujours prévu, toujours accessible, retour ni renseigné ni ignoré) et événement checkin.settled écrit une fois dans la transaction, sans contenu privé.
- Cours reporté : même occurrence, donc même retour ; l'horaire affiché suit la publication.

Fichiers principaux : migration 2026_11_16_140000_create_course_check_ins_and_tasks.php ; app/Services/CourseCheckIns.php ; app/Http/Controllers/CourseCheckInController.php ; app/Models/CourseCheckIn.php, CourseCheckInEvent.php, Task.php ; app/Policies/CourseCheckInPolicy.php ; resources/js/pages/CheckIn.tsx, CheckIns.tsx, Home.tsx, Schedule.tsx ; tests/Feature/CourseCheckInTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 10. 139 tests réussis, 1 ignoré, 2 660 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : route d'accueil (liste des retours en attente du compte connecté), ScheduleController et Schedule.tsx (lien vers le retour sur les cours éligibles).
Limites et non vérifié :
- R21 et R22 partiels : aucun rappel n'est envoyé avant le lot 13 ; seuls la proposition dans l'application, la règle et l'événement sont prouvés.
- R23 incomplet : contrat testé en ligne seulement ; aucun brouillon hors connexion avant le lot 20.
- Table tasks créée dans sa forme personnelle minimale pour la tâche issue du retour ; aucun écran de tâches avant le lot 11, qui devra l'étendre.
- Pièces jointes absentes (lot 14) ; agrégation des difficultés (CHK06) non réalisée et non prévue sans fonction autorisée explicite.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 11 — tâches et devoirs collectifs (17 novembre 2026)
Livré :
- Écran « Mes tâches » (/taches) : tâches personnelles et devoirs des groupes où l'on est effectivement inscrit, quel que soit le contexte actif. Champs : titre, description facultative, matière facultative, échéance facultative, priorité, cours source éventuel (lien « Ajouter une tâche » depuis le planning).
- États à faire, en cours, terminé, abandonné. Le retard est calculé à l'affichage (échéance dépassée et tâche encore ouverte) ; il n'est jamais enregistré et MySQL refuse tout autre état.
- Filtres état, priorité, matière et période d'échéance. Les tâches sans échéance sont listées à part et restent visibles quel que soit le filtre de date.
- Devoir collectif (/taches/devoirs) : un enseignant publie pour un groupe et une matière qui lui sont effectivement affectés. Le contenu officiel est dans tasks ; l'avancement de chaque apprenant est dans task_progress, une ligne par apprenant, créée seulement quand il agit.
- L'apprenant ne modifie ni le contenu ni la date officielle ; il règle son état et un objectif personnel distinct. L'enseignant ne lit aucun avancement individuel.
- Changement du contenu officiel : avancements conservés, un événement homework.updated par version (clé unique), aucun événement si rien ne change. Publication, retrait et rétablissement produisent aussi un événement et une ligne de journal, sans contenu.
- Suppression confirmée : corbeille de 7 jours (CAMPUS_TASK_TRASH_DAYS), restauration, puis purge par la commande planifiée campus:purge-tasks (03 h 30, limite par exécution), avec étapes et avancements.
- Microtâches : étapes simples et privées, sur une tâche personnelle ou un devoir ; la première étape non terminée est affichée comme prochaine action. 20 étapes au plus par tâche et par personne.
- Rappels (pour le lot 13) : règle Tasks::shouldRemind fausse dès qu'une tâche est terminée, abandonnée, supprimée ou inaccessible ; événement task.settled une fois par version. Une ancienne modification rejouée ne rouvre pas une tâche terminée (409).
- Contrôle de version et idempotence partout : client_key à la création (tâche, devoir, étape), expected_version à la modification, rejeu identique sans effet, version dépassée = 409 au format d'erreur commun.
- Accueil : « Vos prochaines tâches » pour le compte connecté.

Fichiers principaux : migration 2026_11_17_090000_extend_tasks_for_homework_and_steps.php ; app/Services/Tasks.php ; app/Http/Controllers/TaskController.php, HomeworkController.php ; app/Models/Task.php, TaskProgress.php, TaskStep.php ; app/Policies/TaskPolicy.php ; app/Console/Commands/PurgeTasks.php ; resources/js/pages/Tasks.tsx, Homework.tsx ; tests/Feature/TaskTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 11. 151 tests réussis, 1 ignoré, 3 239 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : /taches n'est plus un écran d'attente (routes/web.php, HandleInertiaRequests, NavigationTest) ; route d'accueil et Home.tsx (prochaines tâches) ; Schedule.tsx (lien « Ajouter une tâche ») ; bootstrap/app.php (messages d'erreur des formulaires étendus à /taches) ; routes/console.php ; config/campus.php ; .env.example (une ligne fusionnée par erreur entre CAMPUS_MINORS_FRAMEWORK_REFERENCE et CAMPUS_INVITATION_TTL_DAYS a été séparée).
Limites et non vérifié :
- La publication, la modification et le retrait d'un devoir par un enseignant exigent la dérogation locale de second facteur : indisponibles hors local/test tant que le second facteur n'est pas livré. Les tâches personnelles et l'avancement des apprenants n'en dépendent pas.
- R25 partiel : aucun rappel n'est programmé ni envoyé avant le lot 13 ; seuls la règle et l'événement sont prouvés.
- R57 partiel : création unique et rejeu prouvés côté serveur ; la conservation de la saisie après une coupure réseau n'a pas été contrôlée en navigateur, et la file hors connexion est au lot 20.
- Aucune notification n'est envoyée : les événements attendent le lot 13.
- Gestion déléguée des devoirs par l'administration : non construite (arbitrage A31).
- Une tâche personnelle ne peut porter qu'une matière personnelle ; un apprenant sans matière personnelle crée ses tâches sans matière (arbitrage T46).
- Pas de dépôt ni de correction de copies, pas de pièces jointes (lot 14).
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 12 — notes et moyennes (17 novembre 2026)
Livré :
- Écran « Mes notes » (/notes) avec une source à la fois : « Mes notes personnelles » ou « Résultats publiés » d'un établissement et d'une année. La provenance est affichée sur chaque note. Les deux sources ne sont jamais additionnées ; aucune vue combinée n'existe.
- Notes personnelles : intitulé, matière personnelle, période personnelle facultative, date, état, note, barème, coefficient. Privées : propriétaire seul, aucune trace dans le journal ni dans les événements.
- Résultats institutionnels (/notes/evaluations) : un enseignant crée une évaluation pour un groupe et une matière qui lui sont affectés, dans une période de l'année, saisit les résultats, puis publie. Chaque apprenant ne lit que son propre résultat ; un brouillon ou une évaluation retirée n'est jamais visible.
- L'apprenant ne peut ni modifier ni remplacer un résultat publié : aucune route d'écriture ne lui est ouverte.
- Règles : note entre zéro et le barème ; barème et coefficients strictement positifs (validation et contraintes MySQL) ; période de la même année et du même établissement (validation et clé étrangère composée) ; date dans la période.
- Calcul en fractions exactes : note sur 20 = 20 × valeur / barème ; moyenne de matière = somme(note sur 20 × coefficient) / somme(coefficients des notes retenues) ; moyenne générale = somme(moyenne de matière non arrondie × coefficient de matière) / somme(coefficients des matières disposant d'une moyenne). Arrondi à deux décimales à l'affichage seulement.
- États distincts de zéro : à venir, absence, dispense, non rendu, et note manquante (aucun résultat saisi). Ils ne comptent ni au numérateur ni au dénominateur. Zéro est une vraie note.
- Affichage indicatif avec couverture : notes retenues sur évaluations, matières comptées sur matières. Une matière sans note n'abaisse pas la moyenne générale. Aucune moyenne annuelle : le calcul porte sur une période.
- Coefficients de matière : fixés par l'apprenant pour ses matières personnelles ; fixés par l'administration de l'établissement, par année, pour les matières institutionnelles (/notes/coefficients). Si un coefficient institutionnel n'est pas défini, la moyenne générale n'est pas calculée et l'écran le dit.
- Matière à surveiller : moyenne indicative strictement inférieure à 10/20 (CAMPUS_GRADE_WATCH_THRESHOLD), avec le nombre de notes ; aucun autre commentaire.
- Correction ou retrait d'une évaluation publiée : motif obligatoire, révision conservée par résultat (valeur, barème, auteur, motif), journal et événement sans aucune valeur, moyennes recalculées à la lecture suivante.
- Contrôle de version et rejeu sur toutes les écritures (clé client à la création, version attendue ensuite).

Fichiers principaux : migration 2026_11_17_140000_create_grade_tables.php ; app/Support/GradeCalculator.php ; app/Services/Grades.php ; app/Http/Controllers/GradeController.php, GradebookController.php ; app/Models/PersonalGrade.php, Assessment.php, AssessmentResult.php, AssessmentResultRevision.php, SubjectCoefficient.php ; app/Policies/PersonalGradePolicy.php ; resources/js/pages/Grades.tsx, Gradebook.tsx, GradeCoefficients.tsx ; tests/Feature/GradeTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 12. 161 tests réussis, 1 ignoré, 3 754 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : /notes n'est plus un écran d'attente (routes/web.php, HandleInertiaRequests, NavigationTest) ; bootstrap/app.php (messages d'erreur des formulaires étendus à /notes) ; config/campus.php ; .env.example ; colonne grade_coefficient ajoutée à personal_subjects (valeur 1 par défaut).
Limites et non vérifié :
- Saisie, publication et correction par l'enseignant, et coefficients par l'administration, exigent la dérogation locale de second facteur : indisponibles hors local/test tant que le second facteur n'est pas livré. Les notes personnelles et la lecture des résultats publiés n'en dépendent pas.
- Le calcul exact utilise brick/math, déjà installé avec Laravel mais non déclaré dans composer.json : à déclarer explicitement si le porteur l'approuve (arbitrage T57).
- Aucune notification : les événements grade.published, grade.corrected et grade.withdrawn attendent le lot 13.
- L'administration ne consulte pas les résultats des apprenants et aucun bulletin n'est produit (arbitrage A32).
- Après retrait de son appartenance, un apprenant ne voit plus les résultats de cet établissement ; ils restent en base (conservation à trancher au lot 23).
- Une évaluation retirée ne peut pas être republiée ; il faut en créer une nouvelle.
- Pas de saisie en masse par fichier, pas d'import de notes.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 13 — rappels et canaux (17 novembre 2026)
Livré :
- Centre interne de notifications (/notifications) : liste, compteur de non-lues dans l'en-tête, « lu » enregistré seulement sur une action de la personne. Il fonctionne sans aucun canal externe.
- Préférences (/notifications/preferences) : fuseau, plage silencieuse, délai après un cours (5 minutes par défaut, 0 à 120), rappels de tâche la veille et le jour même à l'heure choisie, synthèse quotidienne facultative, et pour chaque catégorie : centre, courriel, appareils. La catégorie « compte et sécurité » est toujours active et hors plage silencieuse ; les courriels de vérification et de mot de passe ne dépendent pas de ces réglages.
- Déclenchements : fin de cours + délai ; changement de planning publié (une alerte regroupée par personne et par publication) ; échéance de tâche J-1 et jour J ; devoir publié, modifié, retiré, rétabli ; résultat publié, corrigé, retiré ; synthèse quotidienne. Les alertes d'opportunités ont leur catégorie mais aucun déclencheur avant le lot 18.
- Calcul entièrement côté serveur : le planificateur met en file, chaque minute, un cycle « planifier, remettre, envoyer » exécuté par la file. Aucun onglet ouvert n'est nécessaire.
- Cohérence transaction/événement : les faits métier sont écrits dans la boîte domain_events dans la transaction qui les produit (lots 08, 10, 11, 12) ; le cycle les consomme par lots verrouillés et marque chacun traité. Un événement écrit pendant un arrêt est repris au cycle suivant.
- Recontrôle juste avant la remise et juste avant chaque envoi externe : compte actif, droits (inscription, appartenance), préférence de catégorie et de canal, état et échéance de l'objet. Un rappel devenu inutile est annulé : cours annulé ou reporté, retour renseigné, tâche terminée ou déplacée, devoir retiré, appartenance retirée.
- Après une panne : chaque rappel a une durée de validité (cours 6 h, tâche 12 h, événement 48 h, synthèse 3 h). Au-delà il expire ; la planification ne reprend que la fenêtre récente. Pas de rafale.
- Unicité : une clé par personne, type, objet et échéance pour le rappel ; une ligne par rappel et par cible (courriel, ou appareil) pour l'envoi. Contraintes uniques MySQL, verrous « skip locked », réservation de l'envoi avant l'appel au fournisseur.
- Reprises bornées : 4 tentatives, délais 1, 5 puis 15 minutes, seulement quand le service de push annonce une indisponibilité. États suivis : en file, en traitement, accepté par le fournisseur, échec, indéterminé, annulé, expiré.
- Livraison incertaine : sans réponse du fournisseur, ou si le traitement est interrompu après réservation, l'envoi est marqué « indéterminé » et n'est pas renvoyé. Aucune promesse d'exactement-une-fois externe.
- Courriel : désactivé par défaut (CAMPUS_NOTIFICATION_EMAIL_ENABLED) ; il ne contient qu'un titre neutre et un lien.
- Web Push : consentement demandé seulement après un clic expliqué ; un abonnement par appareil ; retrait par l'utilisateur ; révocation automatique quand le service répond 404 ou 410. Le serveur envoie un signal sans aucun contenu : le navigateur affiche un message générique et renvoie au centre. Seuls les services de push d'une liste fermée, en HTTPS, sont appelés.
- Aucun SMS, aucun WhatsApp, aucun service facturé.

Fichiers principaux : migration 2026_11_17_180000_create_notification_tables.php ; app/Services/NotificationPlanner.php, NotificationDispatcher.php, NotificationPreferences.php, NotificationStore.php ; app/Support/WebPush.php, Vapid.php ; app/Jobs/RunNotificationCycle.php ; app/Console/Commands/RunNotifications.php, GeneratePushKey.php ; app/Http/Controllers/NotificationController.php ; app/Notifications/CampusReminder.php ; public/push-sw.js ; resources/js/pages/Notifications.tsx, NotificationSettings.tsx ; tests/Feature/NotificationTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 13. 171 tests réussis, 1 ignoré, 4 121 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : routes/console.php (cycle chaque minute) ; HandleInertiaRequests, types.ts et CampusLayout.tsx (compteur et lien « Notifications ») ; bootstrap/app.php ; config/campus.php ; .env.example ; ApplicationFoundationTest (le planificateur met désormais deux tâches en file ; le test vérifie les deux et l'exécution réelle du cycle par la file).
Limites et non vérifié :
- Canaux externes NON VÉRIFIÉS : aucun service de courriel ni clé Web Push n'est configuré sur ce poste. Les envois ne sont prouvés que contre des simulateurs. Aucune intégration n'est déclarée opérationnelle.
- Le format du signal Web Push (VAPID, sans contenu) est écrit avec la seule extension OpenSSL et n'a été accepté par aucun service de push réel.
- R44 partiel : le comportement serveur est prouvé ; le refus dans un vrai navigateur de téléphone n'a pas été exercé.
- Concurrence réelle (deux processus simultanés) non exercée : les tests rejouent le cycle et simulent un envoi réservé par un autre processus ; les verrous et contraintes sont en place.
- Courriel : toute erreur de transport est classée « indéterminé » et n'est pas reprise (pas de clé d'idempotence SMTP). Les reprises bornées ne concernent donc que le Web Push.
- Le service worker public/push-sw.js est minimal et dédié au push ; il devra être fusionné avec celui du lot 19 (PWA).
- Les rappels de tâche parcourent chaque minute les tâches à échéance sous 49 heures : non mesuré en charge.
- Aucune purge des notifications anciennes (lot 22).
- Un rappel « veille » n'est pas émis pour une tâche créée après l'heure de ce rappel.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 14 — pièces jointes (17 novembre 2026)
Livré :
- Dépôt de fichiers PDF, JPEG et PNG sur une tâche personnelle, un devoir ou un retour de cours. Contrôles avant tout enregistrement : taille (5 Mo), extension, type réel lu dans le contenu, signature du format, cohérence entre extension et contenu, image lisible, absence d'élément actif dans un PDF (script, action automatique, lancement, fichier embarqué, formulaire XFA). SVG, documents à macros, exécutables et tout autre format sont refusés.
- Stockage privé (storage/app/private/attachments), sous un nom aléatoire de 64 caractères sans extension, sans rapport avec le nom affiché. Rien sous public/ ; aucun lien public, aucune adresse temporaire.
- Limites vérifiées sous verrou du compte, dans la transaction de dépôt : 3 fichiers par élément, 100 Mo par compte. L'espace utilisé est calculé à partir des fichiers présents, donc toujours cohérent ; les fichiers encore en quarantaine comptent.
- Visibilité explicite fixée au dépôt : « personnel » (son auteur seul) ou « groupe » (réservé à l'enseignant affecté, sur un devoir). Un apprenant peut joindre un fichier personnel à un devoir : ni les autres apprenants ni l'enseignant ne le voient. Un fichier personnel ne devient jamais collectif.
- Droits recontrôlés à chaque opération : parent lisible (tâche non supprimée, inscription effective, affectation effective) et propriétaire. Connaître l'identifiant d'un fichier ne donne aucun droit.
- Téléchargement par un contrôleur autorisé seulement, avec enregistrement forcé (Content-Disposition: attachment), X-Content-Type-Options: nosniff, Content-Security-Policy « default-src 'none'; sandbox », Cross-Origin-Resource-Policy: same-origin, Cache-Control: private, no-store.
- Antivirus asynchrone : quarantaine → en analyse → sain, refusé ou erreur. Seul un résultat « sain » explicite ouvre le téléchargement. Scanner absent, injoignable, en erreur ou interrompu : le fichier reste bloqué et l'analyse est retentée par la maintenance. Fichier refusé : retiré du stockage aussitôt.
- Client ClamAV (clamd, protocole INSTREAM) sans dépendance ; réglage par défaut « none ». Commande de contrôle campus:attachments:scanner-check : réponse du démon, détection du fichier de test standard EICAR assemblé en mémoire, acceptation d'un contenu sain.
- Suppression par le propriétaire (ou l'enseignant gérant le devoir) : fichier retiré tout de suite, espace rendu. Maintenance planifiée toutes les dix minutes (campus:attachments:maintain) : relance des analyses, fichiers de lignes supprimées, lignes supprimées depuis 30 jours, fichiers orphelins de plus d'un jour. La purge d'une tâche emporte ses fichiers.
- Signalement d'un fichier partagé par un membre du groupe ; traitement par l'administration de l'établissement (/pieces-jointes/signalements) : retrait de la diffusion ou classement sans suite, journalisé. L'administration ne lit que les fichiers signalés, jamais les fichiers personnels.
- Interface : état de chaque fichier affiché (en quarantaine, en analyse, disponible, refusé, analyse impossible), espace utilisé, message explicite quand l'antivirus n'est pas configuré. Aucun contournement n'existe pour débloquer un fichier.

Fichiers principaux : migration 2026_11_17_210000_create_attachment_tables.php ; app/Services/Attachments.php ; app/Http/Controllers/AttachmentController.php ; app/Jobs/ScanAttachment.php ; app/Support/Scanners/Scanner.php, ClamdScanner.php, UnavailableScanner.php ; app/Console/Commands/MaintainAttachments.php, CheckAttachmentScanner.php ; app/Models/Attachment.php, AttachmentReport.php ; resources/js/components/AttachmentList.tsx ; resources/js/pages/AttachmentReports.tsx ; tests/Feature/AttachmentTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 14. 178 tests réussis, 1 ignoré, 4 568 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : Tasks::purge (emporte les pièces jointes) ; TaskController, HomeworkController, CourseCheckInController et leurs écrans (liste et dépôt) ; AppServiceProvider (choix du scanner) ; JsonErrorResponse (nouveau code 423 FILE_NOT_AVAILABLE) ; routes/console.php ; bootstrap/app.php ; config/campus.php ; .env.example ; deux tests antérieurs rendus exacts (liste des routes « retour » du lot 10, recherche de valeurs insensible aux identifiants aléatoires au lot 12).
Limites et non vérifié :
- ANTIVIRUS NON VÉRIFIÉ : aucun démon ClamAV n'est installé sur ce poste. Le client clamd n'a jamais dialogué avec un vrai démon ; seuls la lecture de ses réponses, le cas « injoignable » et un scanner de test sont prouvés. En l'état (scanner « none »), tout fichier déposé reste bloqué : la fonction de téléchargement n'est utilisable qu'après installation et contrôle de l'antivirus.
- Dépôts réellement simultanés non exercés : le verrou du compte et le calcul du quota dans la transaction sont en place ; les tests enchaînent les dépôts.
- La recherche de contenu actif dans un PDF est une lecture de texte : un script placé dans un flux compressé du PDF n'est pas détecté par ce contrôle. L'antivirus et le téléchargement forcé sans interprétation sont les protections complémentaires.
- Les images ne sont pas réencodées : leurs métadonnées (dont une éventuelle position GPS) sont conservées telles quelles.
- Après retrait de son appartenance, un apprenant ne peut plus télécharger le fichier personnel qu'il avait joint à un devoir (le parent n'est plus lisible) ; il peut le supprimer.
- Un fichier signalé reste disponible jusqu'à la décision de l'administration. Aucune notification n'est envoyée à l'administration ni à l'auteur (à brancher sur le lot 13 si souhaité).
- Les limites php.ini upload_max_filesize et post_max_size du serveur doivent rester supérieures à 5 Mo (128 Mo sur ce poste).
- Dépôt sur les notes, les opportunités et les démarches : hors périmètre (pas de coffre de documents de candidature).
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 15 — catalogue éditorial des opportunités (17 novembre 2026)
Livré :
- Fiches de bourse, concours, stage et formation : titre, catégorie, organisateur, campagne, source officielle obligatoire, lien de candidature facultatif, résumé, conditions publiées, pays, modalité, niveaux, domaines, langues, financement (complet, partiel, non financé, non précisé), frais (gratuit, payant, non précisé), date limite et sa précision, vérification datée, état.
- Date limite en quatre précisions distinctes : date et heure avec fuseau obligatoire (aucun fuseau par défaut, pas même Abidjan), date seule, clôture continue, date non communiquée. Aucune heure n'est jamais inventée. L'heure affichée est celle du fuseau de l'organisateur ; l'équivalent sur l'appareil du lecteur est donné à part.
- Règle technique affichée pour une date seule : la fiche passe en « dernier jour possible » quand ce jour commence dans le premier fuseau du monde (UTC+14) et n'est considérée close qu'à sa fin dans le dernier (UTC−12). L'écran précise que ce n'est pas une heure limite garantie.
- Workflow brouillon → à vérifier → publiée → suspendue ou archivée. Publier exige la source, l'organisateur, des conditions d'au moins 20 caractères et un acte explicite de vérification (case à cocher), daté et attribué. La même personne peut rédiger et vérifier : les deux actes sont deux révisions distinctes. Modifier une fiche publiée, ou reprendre une fiche suspendue, exige un nouvel acte. MySQL refuse une fiche publiée sans vérification.
- Vérification manuelle : le serveur n'appelle jamais l'adresse de la source. La page publique affiche « Source vérifiée le … » et rappelle que cela ne garantit ni l'éligibilité ni l'obtention.
- Révisions complètes et non modifiables (contenu, état, auteur, note) pour chaque action.
- Doublons : même titre normalisé, même organisateur et même campagne refusés (contrainte unique) ; même source et même campagne refusés sauf confirmation explicite. Nouvelle édition = fiche distincte en brouillon, liée à la précédente, sans recopie des dates ni de la vérification.
- Archivage automatique (toutes les quinze minutes) à la clôture connue ; jamais pour une clôture continue ou une date non communiquée. Changement de date d'une fiche publiée : ancienne et nouvelle dates conservées et affichées, rappels programmés invalidés, un seul événement.
- Texte nettoyé (balises et caractères de contrôle retirés, affichage toujours échappé) ; adresses HTTP ou HTTPS seulement, sans identifiants.
- Catalogue public (/opportunites) : seules les fiches publiées, pour tout visiteur ; brouillons, fiches à vérifier, suspendues et archivées introuvables. Liste ouverte triée par clôture réelle.
- Signalement par fiche (compte connecté) : lien cassé, date erronée, offre close, doute sur l'authenticité, autre. Motif limité à 500 caractères, un signalement ouvert par personne et par fiche, cinq par jour. L'identité du signalant n'est affichée ni au public ni à l'équipe éditoriale.
- File éditoriale (/administration/catalogue/signalements) : responsable, état, commentaire interne, décision (fiche corrigée, suspendue, archivée, aucune action), historique non modifiable. Un signalement ne dépublie rien par lui-même ; seule la décision d'un éditeur suspend. Un éditeur ne traite pas son propre signalement.
- Suspension : la fiche quitte immédiatement le catalogue public, ses rappels programmés sont annulés, un événement opportunity.suspended est émis pour informer les détenteurs de favoris.
- Fixtures de développement : quatre fiches préfixées « [FICTIF] », organisateur fictif, adresses en example.test. Chargées dans la base de développement.

Fichiers principaux : migration 2026_11_17_230000_create_opportunity_tables.php ; app/Services/Opportunities.php, OpportunityReports.php ; app/Http/Controllers/OpportunityController.php, Admin/CatalogueController.php ; app/Models/Opportunity.php, OpportunityRevision.php, OpportunityReport.php, OpportunityReportEvent.php ; app/Console/Commands/ArchiveClosedOpportunities.php ; database/seeders/OpportunityDemoSeeder.php ; resources/js/pages/Opportunities.tsx, Opportunity.tsx, admin/Catalogue.tsx, admin/CatalogueEdit.tsx, admin/CatalogueReports.tsx ; resources/js/components/OpportunityForm.tsx, OpportunityDeadline.tsx ; tests/Feature/OpportunityTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 15. 186 tests réussis, 1 ignoré, 5 252 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : /opportunites n'est plus un écran d'attente et la boucle des modules provisoires a disparu de routes/web.php (plus aucun module en attente) ; HandleInertiaRequests ; NavigationTest (deux tests réécrits) ; NotificationStore::cancelSubject ; DatabaseSeeder ; routes/console.php ; bootstrap/app.php.
Limites et non vérifié :
- Toute l'administration éditoriale exige le rôle d'éditeur dans son contexte plateforme ET la dérogation locale de second facteur : indisponible hors local/test tant que le second facteur n'est pas livré. Le catalogue public et le signalement n'en dépendent pas.
- R37 et R38 partiels : les favoris n'existent pas encore (lot 17) ni leurs rappels (lot 18). Sont prouvés : l'annulation des rappels programmés portant sur la fiche et l'émission d'un événement unique. L'information effective des détenteurs de favoris reste à brancher au lot 18.
- Recherche et filtres du catalogue public : lot 16. La liste actuelle n'a qu'un filtre ouvertes/toutes.
- Pays et langues saisis en codes à deux lettres, sans contrôle contre une liste officielle ni libellés.
- Un seul éditeur fictif existe dans les fixtures ; la séparation signalant/éditeur a été testée avec un second éditeur créé dans le test.
- Un signalement exige un compte : un visiteur non connecté ne peut pas signaler.
- Le catalogue ne contient aucune opportunité réelle : il devra être alimenté et vérifié par un éditeur avant tout pilote.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 16 — recherche et correspondances (20 novembre 2026)
Livré :
- Catalogue public (/opportunites), consultable sans compte : recherche sur le titre, l'organisateur et les domaines (sans casse ni accents, cinq mots au plus, tous exigés) ; filtres catégorie, niveau, pays où se déroule l'opportunité, domaine, modalité, financement, langue et clôture ; tris par clôture la plus proche (défaut), publication la plus récente, pertinence. Filtres visibles, comptés et réinitialisables en un lien ; vingt fiches par page.
- Pertinence simple, affichée à l'écran : par mot recherché, 3 points dans le titre, 2 dans l'organisateur, 1 dans un domaine ; à égalité, clôture la plus proche. Tout est calculé en base MySQL : aucun moteur externe.
- Filtre de clôture : ouvertes (défaut), dans les 30 jours, sans date limite connue, clôturées, ouvertes et clôturées. Les fiches à clôture continue ou à date non communiquée restent dans la liste ouverte, après les fiches datées ; elles ne sont jamais présentées comme closes.
- Fiches expirées ou suspendues identifiées : une fiche close et archivée après publication reste lisible par son adresse et dans le filtre « clôturées », marquée comme telle, sans lien de candidature. Une fiche suspendue n'apparaît dans aucune liste ; son adresse affiche seulement son titre et un avertissement, sans contenu, sans source ni lien. Brouillons et fiches à vérifier restent introuvables partout.
- Critères déclarés (/opportunites/profil), tous facultatifs et privés : niveau, domaines, langues, pays de résidence, nationalité — ces deux derniers distincts. Bouton « Tout effacer ». Rien n'est déduit du compte : sans critère déclaré, aucune comparaison n'est affichée.
- Critères d'éligibilité de la fiche distincts du lieu : l'éditeur saisit séparément le pays où se déroule l'opportunité, les nationalités admises et les pays de résidence admis.
- Correspondances déterministes et expliquées, critère par critère : « correspond à vos critères », « information à vérifier », « critère non satisfait », avec la phrase qui montre ce que publie la fiche et ce que la personne a déclaré. Une information absente d'un côté ou de l'autre donne toujours « à vérifier ». Pour les domaines, intitulés libres, l'absence d'intitulé commun donne « à vérifier », jamais « non satisfait ». Mention permanente : comparaison indicative, pas une décision d'éligibilité.
- Détail de la fiche : financement, frais, précision de l'échéance, organisateur, date de vérification, nom de domaine de destination des liens. Action « Candidater sur le site de l'organisateur » : lien direct vers l'adresse de l'organisateur, nouvel onglet, sans référent ; aucune route de Campus ne s'intercale, rien n'est enregistré ni transmis.
- Noms français des pays et des langues à partir de leur code (extension intl) ; le code seul si elle est absente.

Fichiers principaux : migration 2026_11_20_090000_add_opportunity_search_and_profiles.php ; app/Services/OpportunitySearch.php ; app/Support/OpportunityMatcher.php, Labels.php ; app/Models/OpportunityProfile.php ; app/Http/Controllers/OpportunityController.php ; resources/js/pages/Opportunities.tsx, Opportunity.tsx, OpportunityProfile.tsx ; tests/Feature/OpportunitySearchTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 16. 191 tests réussis, 1 ignoré, 6 251 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : Opportunities (critères d'éligibilité, domaines normalisés, fiches « identifiées ») ; CatalogueController et OpportunityForm.tsx (deux champs d'éligibilité) ; OpportunityTest adapté à une décision du prompt 16 : une fiche suspendue ou archivée après publication n'est plus introuvable mais identifiée, et le filtre de la liste s'appelle désormais « cloture ».
Limites et non vérifié :
- R40 partiel : les démarches n'existent pas encore (lot 17). Est prouvé : ouvrir un lien externe ne passe par aucune route et n'écrit rien. Le lot 17 devra conserver cette propriété (un clic ne marque jamais une démarche « envoyée »).
- La recherche est une recherche de sous-chaînes en base : pas de tolérance aux fautes, pas de synonymes. Non mesurée au-delà de quelques dizaines de fiches ; la liste des valeurs de filtre est calculée sur 2 000 fiches au plus.
- Les critères comparés sont ceux que l'éditeur a saisis en champs structurés ; une condition écrite seulement dans le texte « conditions » n'est pas comparée. D'où la mention systématique de vérifier sur la source.
- Pays et langues toujours saisis en codes à deux lettres, y compris par l'utilisateur dans ses critères ; pas de liste déroulante de pays.
- Le filtre « pays » porte sur le lieu de l'opportunité, pas sur l'éligibilité.
- La nationalité déclarée est une donnée personnelle facultative : sa conservation et son effacement à la suppression du compte relèvent du lot 22.
- Écrans non contrôlés en navigateur (formulaire de filtres à dix champs sur 360 px notamment).
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 17 — favoris et démarches privées (20 novembre 2026)
Livré :
- Favoris : un seul par personne et par fiche (contrainte unique), ajout et retrait rejouables. Deux appareils du même compte voient le même état. On n'ajoute en favori qu'une fiche publiée ; un favori survit à la clôture ou à la suspension de la fiche.
- Page « Mon suivi » (/opportunites/suivi) : favoris classés bientôt clos (clôture connue sous 7 jours), ouverts (y compris sans date connue), suspendus, expirés. Une fiche suspendue n'y montre que son titre et l'avertissement ; une date prolongée montre la nouvelle et l'ancienne.
- Démarche privée, commencée depuis une fiche publiée : état à étudier, dossier en préparation, envoyée, en attente, retenue, non retenue, abandonnée. L'état ne change que par le choix de la personne dans le formulaire ; chaque choix est daté. Ouvrir le site de l'organisateur ne passe par aucune route et ne modifie jamais rien.
- Contenu d'une démarche : échéance personnelle facultative (distincte de la date officielle), note privée de 500 caractères au plus, supprimable réellement, et liste de pièces à préparer sous forme de lignes de texte à cocher (20 au plus). Aucun fichier : aucune route de dépôt, et la table des pièces jointes n'accepte pas ce parent.
- Résumé de référence daté dans chaque démarche : titre, organisateur, campagne, catégorie, date limite telle qu'affichée, site de la source, date de vérification, date du relevé. Il suit la fiche tant qu'elle est publiée et se fige à la suspension ou à l'archivage. Ni conditions, ni résumé, ni adresse complète de la source.
- Confidentialité : chaque route est résolue pour le compte connecté à partir de la fiche ; aucune adresse ne désigne le favori ou la démarche d'autrui. Ni enseignant, ni établissement, ni éditeur, ni administrateur de la plateforme n'y accède. Rien n'est journalisé ; aucun événement ne désigne une personne. L'équipe éditoriale ne voit ni qui suit une fiche ni combien.
- Préparation du lot 18 : les événements opportunity.suspended, opportunity.deadline_changed, opportunity.archived et opportunity.resumed existent déjà (lot 15), avec la fiche et sa version ; OpportunityFollowUps::followers donne les comptes à prévenir (favori, ou démarche encore en cours).
- Contrôle de version sur la démarche : une page restée ouverte sur un autre appareil ne remplace rien (409).

Fichiers principaux : migration 2026_11_20_140000_create_opportunity_follow_up_tables.php ; app/Services/OpportunityFollowUps.php ; app/Http/Controllers/OpportunityFollowUpController.php ; app/Models/OpportunityFavorite.php, OpportunityApplication.php, OpportunityApplicationItem.php ; app/Policies/OpportunityApplicationPolicy.php ; resources/js/pages/OpportunityFollowUp.tsx ; resources/js/components/ApplicationPanel.tsx ; tests/Feature/OpportunityFollowUpTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 17. 197 tests réussis, 1 ignoré, 6 865 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : Opportunities (résumé de référence, figé à la suspension et à l'archivage) ; OpportunityController et Opportunity.tsx (section « Mon suivi » de la fiche) ; Opportunities.tsx (lien vers le suivi) ; config/campus.php et .env.example (CAMPUS_OPPORTUNITY_CLOSING_SOON_DAYS) ; un test du lot 16 qui fige la liste des routes « opportunites ».
Limites et non vérifié :
- R37 et R38 restent partiels : la personne voit le changement dans « Mon suivi », mais aucune alerte ne lui est envoyée avant le lot 18.
- Constat transversal fait pendant ce lot (arbitrage A43) : le limiteur de débit de Laravel utilisé sur 71 routes compte par compte, toutes routes confondues. Une personne très active pendant une minute peut donc se voir refuser une action sur une route au plafond bas (30 par minute sur 22 routes), sans rapport avec cette route. Non corrigé ici, car cela touche tous les lots ; à traiter au durcissement.
- Deux demandes strictement simultanées n'ont pas été exercées ; la contrainte unique de MySQL et l'insertion « ignorer si existe » sont en place et testées séparément.
- L'échéance personnelle ne produit aucun rappel avant le lot 18.
- La liste de suivi est limitée aux 200 favoris et 200 démarches les plus récents.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 18 — recherches enregistrées et alertes (20 novembre 2026)
Livré, sur le moteur de rappels du lot 13 :
- Recherche enregistrée depuis le catalogue (« Enregistrer cette recherche ») : mots, catégorie, niveau, pays, domaine, modalité, financement, langue. Nom facultatif, fréquence (une synthèse par jour ou par semaine au plus), canaux (courriel, appareils) en plus du centre de notifications. Dix recherches au plus par compte ; mêmes filtres = même recherche.
- Synthèses : une notification par recherche et par période, regroupant au plus vingt fiches ouvertes publiées ou mises à jour depuis l'enregistrement. Les fiches déjà au catalogue ne sont pas annoncées ; une fiche n'est annoncée qu'une fois par révision pertinente (clé unique recherche, fiche, révision). Au-delà de vingt, le reste part dans la synthèse suivante.
- Mise à jour importante : un changement de date limite d'une fiche correspondante est annoncé comme « mise à jour », avec l'ancienne date. Les autres modifications ne sont pas annoncées.
- Page « Mes alertes » (/opportunites/alertes) : état de chaque recherche (active ou arrêtée, fréquence, dernière vérification, dernière synthèse, nombre de fiches annoncées), arrêt et reprise, suppression. Page d'une alerte : fiches annoncées, critères qui expliquent l'annonce, lien vers la source officielle.
- Désabonnement effectif immédiatement : les synthèses programmées ou visibles de la recherche sont retirées ; plus rien n'est produit. Favoris et démarches ne sont pas touchés. À la reprise, seules les fiches publiées après la reprise sont annoncées.
- Rappels des fiches suivies (favori, ou démarche en cours), 7 jours puis 1 jour avant la date limite, réglables de 0 à 30 jours dans les préférences (0 = aucun). Échéance avec heure : rappel à l'instant exact. Date seule : rappel à l'heure de rappel de la personne, dans son fuseau, sans jamais supposer une heure de clôture. Clôture continue ou date non communiquée : aucun rappel.
- Prolongation : les rappels calés sur l'ancienne date sont annulés, les suiveurs sont prévenus du changement (nouvelle et ancienne dates), et les rappels sont recalculés sur la nouvelle date.
- Suspension : les suiveurs sont prévenus une fois ; le message renvoie à « Mon suivi », jamais à une page d'offre, et ne contient ni motif interne ni lien vers la source. Une fiche suspendue n'entre dans aucune synthèse et ne produit aucun rappel d'échéance. Reprise : les suiveurs sont prévenus.
- Tout passe par le recontrôle du lot 13 juste avant la remise et avant chaque envoi : compte actif, catégorie « Opportunités et favoris » activée, canaux, plage silencieuse, fuseau, fiche toujours publiée (ou toujours suspendue pour l'avis de suspension), personne suivant toujours la fiche, recherche toujours active.
- Confidentialité : recherches, critères et démarches restent privés ; aucune route ne désigne la recherche d'autrui. Le courriel ne contient qu'un titre neutre et un lien : ni nom de recherche, ni critère, ni titre de fiche.

Fichiers principaux : migration 2026_11_20_180000_create_saved_search_tables.php ; app/Services/SavedSearches.php ; app/Http/Controllers/SavedSearchController.php ; app/Models/SavedSearch.php, SavedSearchMatch.php ; app/Services/NotificationPlanner.php, NotificationDispatcher.php (étendus) ; resources/js/pages/OpportunityAlerts.tsx, OpportunityAlert.tsx ; tests/Feature/OpportunityAlertTest.php.
Vérifications réellement exécutées : voir recette-v1.md, section lot 18. 202 tests réussis, 1 ignoré, 7 244 assertions ; Pint, typage, ESLint et build passés ; migration sur test et dev.
Modifications de l'existant : NotificationPlanner (événements d'opportunités, rappels de fiches suivies, synthèses), NotificationDispatcher (recontrôles, canaux par recherche), NotificationPreferences et NotificationController (deux réglages de rappel), NotificationSettings.tsx, Opportunities.tsx (formulaire d'enregistrement), OpportunityController (règles de filtres partagées), config/campus.php ; deux tests figés ajustés (liste des routes « opportunites », nombre de positions de reprise du moteur).
Limites et non vérifié :
- Courriel et Web Push toujours non configurés : les envois externes de ces alertes ne sont prouvés que contre le simulateur. Le centre de notifications, lui, est pleinement exercé.
- Une fiche plus ancienne que la recherche et qui se met à correspondre après une modification (changement de catégorie, par exemple) n'est pas annoncée : seules la publication et le changement de date le sont.
- Un rappel dont l'instant est passé depuis plus de 24 heures sans que le cycle ait tourné expire : c'est voulu (pas de rappel tardif), mais cela suppose un planificateur qui tourne.
- L'échéance personnelle d'une démarche (lot 17) ne produit toujours aucun rappel : le prompt 18 ne la demande pas.
- Chaque cycle parcourt les fiches publiées se clôturant dans les 33 jours et leurs suiveurs, et jusqu'à 500 recherches dues : non mesuré en charge.
- Pas de lien de désabonnement dans le courriel lui-même : le courriel renvoie à la page de l'alerte, où se trouve le bouton d'arrêt.
- Écrans non contrôlés en navigateur.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 19 — PWA et cache de lecture (22 novembre 2026)
Livré :
- Manifest (public/manifest.webmanifest), quatre icônes originales simples (un « H » blanc sur fond vert, générées localement, dont une version « maskable »), lien dans la page et couleur de thème. La description du manifest et l'écran disent explicitement que Campus est un raccourci web, pas une application native.
- Service worker unique (public/sw.js), qui absorbe celui du push du lot 13 (public/push-sw.js est supprimé). Il ne conserve que deux caches publics : la coque (page hors ligne neutre, son script, manifest, icônes) et les fichiers de build à empreinte. Navigations : réseau seulement, jamais conservées ; hors connexion, page neutre. Requêtes Inertia, données, écritures, routes d'administration, exports, pièces jointes, autres origines : le service worker ne s'en mêle pas. Toute activation supprime tout cache qui n'est pas l'un de ses deux caches publics.
- Mise à jour sans rechargement forcé : une nouvelle version attend ; un bandeau « Une nouvelle version de Campus est prête » propose « Mettre à jour maintenant » et rappelle d'enregistrer une saisie en cours. Version de la coque portée par une constante du service worker ; les fichiers de build sont versionnés par leur nom.
- Aide à l'installation selon le navigateur (page /hors-ligne) : bouton quand le navigateur le propose, consignes pour iPhone et iPad, message neutre sinon. Refuser l'installation ne change aucune fonction.
- Appareil partagé par défaut : rien de privé n'est stocké ; sans réseau, page neutre. Stockage privé seulement après le choix explicite « Cet appareil est personnel », propre au compte qui le fait.
- Copie de lecture sur appareil personnel (IndexedDB, jamais le cache du service worker) : planning des 14 prochains jours (publié seulement), tâches ouvertes, notes que le compte a le droit de lire, opportunités favorites, et fiches publiques consultées. Lecture seule : aucun formulaire, aucun lien d'action, aucun téléchargement hors connexion ; connexion, import et publication exigent le réseau.
- Affichage hors connexion : date de la dernière synchronisation et mention « données potentiellement périmées ».
- Droits réévalués à chaque ouverture en ligne : l'instantané est recalculé par le serveur et remplace la copie ; ce qui n'est plus autorisé disparaît. Sans contrôle pendant 7 jours (CAMPUS_OFFLINE_TTL_DAYS), la copie est effacée à l'ouverture suivante, en ligne comme hors ligne.
- Déconnexion, changement de compte, expiration, retour au mode partagé : effacement de la base IndexedDB, des réglages locaux, de tout cache non public et, à la déconnexion ou au changement de compte, de l'abonnement push de l'appareil. Une réponse du serveur pour un autre compte que celui qui a fait le choix n'est jamais enregistrée.
- Toute réponse servie à un compte connecté porte désormais « Cache-Control: no-store, private » (pages, données, exports).
- La limite est expliquée à l'écran : une révocation distante ne peut pas effacer un appareil déconnecté ; la copie reste lisible jusqu'au retour du réseau ou 7 jours au plus.

Fichiers principaux : public/sw.js, offline.html, offline.js, manifest.webmanifest, icons/ ; resources/js/offline/policy.ts, store.ts, runtime.ts ; resources/js/pages/Offline.tsx ; app/Services/OfflineSnapshot.php ; app/Http/Controllers/OfflineController.php ; app/Http/Middleware/PreventPrivateCaching.php ; tests/Feature/PwaTest.php ; tests/js/sw.test.mjs, offline-policy.test.mjs ; tests/browser/pwa-edge.mjs.
Vérifications réellement exécutées : voir recette-v1.md, section lot 19. 206 tests PHPUnit réussis, 1 ignoré, 7 401 assertions ; 13 tests JavaScript sous Node 24 ; 22 contrôles dans Edge 157 (docs/preuves/lot19-edge.txt) ; Pint, typage, ESLint et build passés. Aucune migration dans ce lot.
Modifications de l'existant : CampusLayout.tsx (enregistrement du service worker, règles de conservation à chaque ouverture, bandeaux de mise à jour et de hors-connexion), Profile.tsx (effacement avant déconnexion), Opportunity.tsx (fiche consultée retenue sur appareil personnel), NotificationSettings.tsx (service worker unique), HandleInertiaRequests (clé opaque du compte), bootstrap/app.php (en-tête anti-conservation), app.blade.php, package.json (script test:js, aucune dépendance ajoutée), NotificationTest.
Limites et non vérifié :
- Navigateurs : seul Edge 157 (Chromium, ordinateur) a été exercé. Firefox, Safari, Chrome sur Android et Safari sur iPhone sont à vérifier ; le comportement d'installation et de stockage y diffère.
- Le contrôle dans Edge s'est fait sans compte connecté : la copie privée lue hors connexion était une copie fictive placée directement dans le navigateur. La synchronisation réelle d'un compte dans un navigateur, la déconnexion et le changement de compte dans un navigateur ne sont prouvés que par les règles testées sous Node et par les tests serveur.
- Le bandeau de mise à jour et l'acceptation de la proposition d'installation n'ont pas été exercés dans un navigateur.
- R44 partiel : toujours aucun téléphone réel. R55 partiel : absence de défilement horizontal et taille des boutons mesurées dans Edge sur les deux écrans de ce lot seulement ; navigation clavier complète et zoom natif à confirmer.
- La version de la coque est une constante à incrémenter à la main quand un fichier de la coque change.
- La page hors ligne n'affiche pas les pièces jointes, les retours de cours ni les démarches : hors périmètre de lecture demandé.
- Brouillons hors connexion et synchronisation des écritures : lot 20.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 20 — Synchronisation des brouillons personnels (22 novembre 2026)
Livré :
- Hors connexion, sur un appareil déclaré personnel : créer une tâche personnelle, modifier une tâche personnelle, écrire le brouillon d'un retour privé après cours. Rien d'autre : notes en lecture seule, devoirs et planning d'établissement, fichiers, administration, publication et connexion exigent le réseau. Sur un appareil partagé, aucun brouillon n'est stocké.
- Deux endroits pour écrire sans réseau : la page hors ligne (ouverture de Campus sans réseau) et les écrans déjà ouverts (Tâches, Retour après cours) quand le réseau tombe ou que l'envoi échoue en route.
- Cinq états affichés en toutes lettres pour chaque brouillon : « Enregistré sur cet appareil », « En attente d'envoi », « Synchronisé », « Refusé », « Conflit ». Panneau « Brouillons et envois de cet appareil » sur /hors-ligne, /taches, l'écran du retour et la page hors ligne ; bandeau de rappel sur toutes les pages.
- Chaque opération porte : identifiant client unique, clé opaque du compte, contexte (« personal » seulement), type, empreinte SHA-256 du contenu, version serveur connue.
- Point d'entrée JSON versionné : POST /synchronisation/v1/operations, sous la session du site et son jeton CSRF (aucun jeton d'API). Une opération par requête.
- Idempotence liée au compte et à l'opération (table sync_operations, sans aucun contenu saisi) : la même opération renvoyée reçoit l'accusé déjà rendu ; la même clé avec un autre contenu est un conflit et ne crée rien ; la même clé chez un autre compte est une autre opération.
- À chaque envoi, le serveur repasse par les services des écrans en ligne : propriétaire, appartenance, droit sur le cours, existence du parent, version. Une version dépassée n'écrase rien : réponse « conflit » avec la version actuelle de l'objet du propriétaire (jamais de donnée du cours ni de l'établissement), différences affichées champ par champ, et deux choix explicites : appliquer mon brouillon à la place, ou garder la version enregistrée. Aucun « dernier arrivé gagne ».
- Refus (droit retiré, élément supprimé, contenu invalide) : le brouillon reste sur l'appareil, lisible par son propriétaire, jusqu'à ce qu'il réessaie ou l'abandonne. Un envoi qui dépendait d'un envoi refusé n'est pas envoyé.
- Un retour reste unique par personne et par cours : deux brouillons écrits sur deux appareils donnent un retour et un conflit visible.
- Synchronisation : à l'ouverture de Campus, au retour du réseau, au retour sur l'onglet, et par le bouton « Synchroniser maintenant ». Aucune dépendance à la synchronisation en arrière-plan du navigateur.
- Déconnexion avec brouillons : avertissement avec la liste, puis trois choix (synchroniser puis se déconnecter, abandonner et se déconnecter, rester connecté). La déconnexion efface ensuite réellement la base locale, les réglages et les caches non publics. Même avertissement avant de repasser en « appareil partagé » et avant « Effacer ces données » sur la page hors ligne.
- Mise à jour : le schéma local passe de la version 1 à la version 2 en ajoutant la file sans rien supprimer ; repli si une version plus récente a déjà fait évoluer la base ; un brouillon écrit par une version plus récente est conservé sans être envoyé ; le service worker ne touche jamais au stockage privé.
- Copie de lecture périmée (7 jours) alors que des brouillons attendent : seule la copie est effacée, les brouillons restent.
- Instantané hors connexion en version 2 : tâches personnelles avec leur version et leurs champs modifiables, cours ouvrant un retour avec l'état et la version du retour (jamais son texte).
- Purge quotidienne des accusés de plus de 90 jours (campus:sync:prune, CAMPUS_SYNC_RETENTION_DAYS).
- Défaut de mise en page corrigé au passage : le bandeau « Vous êtes hors connexion » s'affichait deux fois, dont une avec un texte du lot 02.

Fichiers principaux : app/Services/SyncOperations.php ; app/Http/Controllers/SyncController.php ; app/Support/OperationFingerprint.php ; app/Models/SyncOperation.php ; migration 2026_11_22_090000_create_sync_operations_table ; app/Console/Commands/PruneSyncOperations.php ; public/offline-core.js (cœur commun de la file) ; public/offline.js et offline.html ; resources/js/offline/outbox.ts ; resources/js/components/OutboxPanel.tsx ; tests/Feature/SyncTest.php ; tests/js/offline-core.test.mjs ; tests/js/emit-operations.mjs ; tests/browser/pwa-edge.mjs.
Modifications de l'existant : OfflineSnapshot et Tasks (instantané version 2), routes/web.php, routes/console.php, config/campus.php, .env.example, app.blade.php (chargement du cœur commun), public/sw.js (version v2, cœur ajouté à la coque), store.ts (ouverture par le cœur commun, copie périmée sans perte de brouillon), CampusLayout.tsx, Tasks.tsx, CheckIn.tsx, Profile.tsx, Offline.tsx, VerifyEmail.tsx, app.css, PwaTest, sw.test.mjs.
Vérifications réellement exécutées : voir recette-v1.md, section lot 20. 216 tests PHPUnit réussis, 1 ignoré, 7 582 assertions ; 22 tests JavaScript ; 36 contrôles dans Edge 158 (docs/preuves/lot20-edge.txt) ; Pint, typage, ESLint et build passés. Migration additive (une table) exécutée sur les bases locales de développement et de test.
Limites et non vérifié :
- La synchronisation réelle d'un compte connecté dans un navigateur n'a pas été exercée : les comptes fictifs ont des mots de passe aléatoires. Elle est prouvée en deux moitiés reliées : le vrai code de l'appareil exécuté sous Node contre un serveur simulé, et les opérations écrites par ce vrai code envoyées telles quelles au vrai serveur (SyncTest). Les écrans React du lot (panneau des brouillons dans l'application, mise en file depuis Tâches et Retour après cours, avertissement à la déconnexion) sont typés, compilés et relus, mais non exercés dans un navigateur.
- Un seul navigateur réel : Edge 158 sur ordinateur. Firefox, Safari, Android et iPhone à vérifier ; Safari peut effacer le stockage d'un site non installé après sept jours sans visite, ce qui supprimerait des brouillons non envoyés.
- Un brouillon n'existe que sur l'appareil où il a été écrit : il n'est pas sauvegardé sur le serveur tant qu'il n'est pas synchronisé. Si un autre compte se connecte sur le même appareil, les brouillons du compte précédent sont effacés sans récupération possible (la confidentialité prime). Une session fermée à distance ne déclenche pas l'avertissement : les brouillons restent sur l'appareil jusqu'au retour du même compte ou l'arrivée d'un autre.
- Hors périmètre de ce lot : suppression de tâche, étapes, avancement d'un devoir, « rien à signaler », « plus tard », « ignorer » et création d'une tâche liée au retour restent en ligne. Une tâche créée hors connexion n'a ni matière ni cours lié depuis la page hors ligne.
- Un retour déjà renseigné ne se modifie pas depuis la page hors ligne (son texte n'est pas copié sur l'appareil) ; il se modifie hors connexion seulement depuis son écran déjà ouvert.
- Limite de débit commune par compte (A43) : une file de plus d'une centaine de brouillons s'enverrait en plusieurs passages.
- La version de la coque (public/sw.js) reste à incrémenter à la main quand un fichier de la coque change.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 21 — Accueil et parcours complets (22 novembre 2026)
Livré :
- Accueil de prochaine action pour un compte connecté : cours du jour (dans le fuseau personnel), retours à renseigner, tâches proches, notes et moyennes avec leur couverture (matières comptées sur le total, coefficients manquants), opportunités compatibles avec les critères déclarés. Chaque ligne porte sa provenance « Personnel » ou « Établissement » ; l'en-tête rappelle le contexte actif.
- Rien d'inventé : un bloc sans donnée affiche un état vide ; aucune moyenne sans note ; aucune opportunité suggérée sans critères déclarés. Les textes d'attente de l'ancien accueil (« Campus se prépare », « En construction », « Bientôt disponible ») sont retirés.
- Parcours de démarrage individuel en sept étapes, calculé sur les objets réels : compte, fuseau, matière, cours, tâche, note, opportunité. La première étape restante porte le bouton d'action ; le parcours disparaît une fois terminé.
- Parcours de mise en route d'un établissement, pour son administration seulement, dans le périmètre de son appartenance : référentiels, import analysé, publication, invitation acceptée, cours publiés, signalement d'un enseignant, décision.
- Fuseau horaire personnel réglable et confirmable depuis le profil (PATCH /profil/fuseau) : il n'existait aucun écran pour le changer.
- Page « Aide et support » publique (/aide) : dix réponses courtes, ce qui reste privé, et le contact du support s'il est configuré (CAMPUS_SUPPORT_EMAIL) ; sinon la page le dit.
- Accès depuis l'accueil, le profil, le pied de page et le menu : profil et fuseau, préférences de notification, hors connexion, aide.
- États d'erreur : page d'erreur de Campus pour accès interdit, page introuvable, session expirée, trop de demandes, erreur du serveur et maintenance, avec « Réessayer », « Revenir à l'accueil » et « Aide » ; une action refusée sur un écran qui n'affichait pas l'erreur ramène à cet écran avec une explication et un bouton de rechargement.
- Saisie protégée : avertissement avant de quitter un formulaire commencé (tâche, cours, note, retour de cours, critères d'opportunités) ; bandeau « Le réseau n'a pas répondu » quand un envoi échoue en route, la saisie restant à l'écran.
- Défaut réel corrigé, trouvé par le parcours en navigateur : la fiche d'une opportunité dont la date limite porte une heure précise s'affichait vide (options de format de date incompatibles). Un test automatique contrôle désormais tous les formats de date des écrans.
- Écran « à construire » supprimé (ModulePending) ; un test vérifie que chaque écran déclaré est rendu par le serveur et que chaque lien fixe des écrans mène à une vraie route.
- Commande locale campus:fixtures:access : ouvre ou referme l'accès par mot de passe aux comptes fictifs @example.test, sur le poste de développement et la base hako_ra_campus_dev uniquement. Elle débloque la recette manuelle du porteur.

Fichiers principaux : app/Services/HomeDashboard.php ; app/Http/Controllers/HomeController.php, HelpController.php ; app/Console/Commands/FixtureAccess.php ; resources/js/pages/Home.tsx, Help.tsx, Error.tsx ; resources/js/components/ui/useUnsavedWarning.ts ; tests/Feature/HomeTest.php ; tests/js/intl-options.test.mjs ; tests/browser/journeys-edge.mjs.
Modifications de l'existant : routes/web.php, bootstrap/app.php (page d'erreur, message d'action refusée), ProfileController et Profile.tsx (fuseau), OfflineSnapshot (cours entre deux instants, réutilisé par l'accueil), HandleInertiaRequests (message « problem »), CampusLayout.tsx (liens, bandeaux), Tasks.tsx, CheckIn.tsx, Schedule.tsx, Grades.tsx, OpportunityProfile.tsx (avertissement de saisie), OpportunityDeadline.tsx (correction), config/campus.php, .env.example, app.css, trois tests existants adaptés au nouvel accueil.
Vérifications réellement exécutées : voir recette-v1.md, section lot 21. 224 tests PHPUnit réussis, 1 ignoré, 7 899 assertions ; 23 tests JavaScript ; 35 contrôles des deux parcours dans Edge 158 ; 36 contrôles PWA rejoués ; Pint, typage, ESLint et build passés. Aucune migration.
Anomalies ouvertes et assignées : voir arbitrages-v1.md, « Anomalies du lot 21 » (AN21-01 à AN21-09).
Limites et non vérifié :
- La mesure demandée (« un débutant crée un cours puis une tâche en moins de dix minutes ») n'a pas été faite avec une personne. Le script, qui connaît les écrans, y met 7 à 9 secondes pour 8 saisies ou clics sur 2 écrans : cela borne le nombre de gestes, pas la compréhension d'un débutant.
- Le parcours « compte » n'a été exercé en navigateur que jusqu'au formulaire d'inscription : sans service de courriel, un nouvel inscrit ne peut pas vérifier son adresse. Les parcours ont été faits avec les comptes fictifs existants.
- Les parcours tournent avec la dérogation locale qui ouvre les rôles d'enseignement et d'administration sans second facteur.
- Import : seul le dépôt du modèle fourni par Campus (sans ligne) a été exercé en navigateur ; son rapport est « Invalide : corrections nécessaires », rien n'est appliqué. L'import de lignes réelles et son application restent couverts par les tests du lot 09.
- Au premier passage du parcours individuel, les sept étapes sont passées de « ✓→○○○○○ » à « terminé » ; le passage conservé en preuve part d'un parcours déjà terminé, les données fictives des passages précédents étant restées dans la base de développement.
- Un seul navigateur réel : Edge 158 sur ordinateur, plus une largeur de 360 px simulée.
- Données fictives laissées dans la base de développement par les passages successifs, toutes préfixées « [PARCOURS … ] » : matières, cours, tâches, notes et un favori du compte indépendant ; cours, versions publiées, invitations, signalements et lots d'import de l'établissement fictif A. Le compte indépendant a quitté l'établissement A à la fin : il n'a plus d'appartenance active.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 22 — Droits des personnes et conservation (22 novembre 2026)
Livré :
- Notice de confidentialité publique (/confidentialite), versionnée : chaque version porte une date et une empreinte de son texte ; les anciennes versions restent lisibles. Elle décrit ce que fait réellement l'application et affiche en tête la liste de ce qui reste à compléter avant ouverture : identité du responsable, contact, région d'hébergement, bases légales, prestataires, autorité de contrôle, cadre des mineurs, validation juridique. Aucune personne n'est inventée ; le service n'est qualifié de conforme à aucune réglementation.
- Preuve de lecture : « J'ai pris connaissance de cette notice » enregistre le compte, la version, l'empreinte du texte et la date. Rappel non bloquant tant que la version en vigueur n'a pas été lue.
- Paramètres de confidentialité (/confidentialite/parametres) : notice, choix facultatifs renvoyant à leurs écrans (notifications, critères d'opportunités, copie sur l'appareil), export, demandes, suivi, suppression.
- Demandes suivies : accès/export et suppression traités automatiquement après nouvelle saisie du mot de passe ; rectification et opposition déposées par écrit, traitées par l'administration de la plateforme (en cours, traitée, refusée, avec réponse), annulables par leur auteur. Chaque demande garde sa date et son état.
- Export : fichier JSON privé, propre au compte, téléchargeable par lui seul pendant 7 jours (paramétrable), puis effacé. Colonnes choisies une à une : ni mot de passe, ni jeton, ni empreinte technique, ni adresse de notification ; rien d'un autre compte ; de l'établissement, seulement le rattachement, les groupes et les résultats publiés pour la personne.
- Suppression du compte : écran des conséquences (ce qui est effacé, ce que l'établissement conserve et pourquoi, sauvegardes), rappel de l'export, mot de passe ressaisi et mot de confirmation. Effacés : identité de connexion et tout l'espace individuel, fichiers compris. Conservés chez l'établissement, détachés de l'identité de connexion : historique de rattachement et ce qu'il a publié ou décidé. Révoqués : sessions, jetons, appartenances. Refusée tant que le compte est la seule administration d'un établissement ou de la plateforme.
- Journal des effacements et retraits d'accès : fichier hors base de données, une ligne signée par entrée, avec des identifiants techniques et une date seulement. Commande campus:privacy:reapply-journal à lancer après la restauration d'une sauvegarde : elle efface de nouveau les comptes supprimés, referme les appartenances retirées, puis ferme toutes les sessions et tous les jetons. Les lignes sans signature valide sont ignorées et comptées.
- Conservation paramétrable, purge quotidienne bornée (campus:retention:run) : invitations 30 jours après expiration ou traitement ; notifications et traces d'envoi 90 jours ; audit 12 mois ; exports 7 jours ; demandes closes 12 mois ; journal des effacements 180 jours, jamais moins que la durée des sauvegardes plus 30 jours. Déjà en place et rappelés : corbeille des tâches 7 jours, fichiers bruts d'import 30 jours, copie locale 7 jours. Sauvegardes glissantes : 30 jours annoncés, réalisation au lot 24.
- Inactivité : dernière activité notée à la journée près ; au-delà de 24 mois, les comptes sont comptés pour examen. Aucune suppression automatique.
- Parcours des mineurs : toujours fermé ; il faut à la fois l'activation et la référence d'un cadre validé. Aucun âge ni règle n'est codé.
- Les limites de débit des nouvelles routes ont chacune leur propre compteur, pour ne pas se cumuler avec les autres actions du compte (A43).

Fichiers principaux : app/Support/PrivacyNotice.php ; app/Services/DataExporter.php, AccountEraser.php, ErasureJournal.php, RetentionPolicies.php ; app/Http/Controllers/PrivacyController.php, Admin/PrivacyRequestController.php ; app/Console/Commands/RunRetention.php, ReapplyErasures.php ; migration 2026_11_22_150000_create_privacy_tables ; resources/js/pages/Privacy.tsx, PrivacySettings.tsx, AccountDeletion.tsx, admin/PrivacyRequests.tsx ; tests/Feature/PrivacyTest.php.
Modifications de l'existant : users (dernière activité, date d'effacement, état « erased », contrainte d'identifiant assouplie pour un compte effacé), EnsureAccountIsActive (dernière activité), MembershipAdministration (retrait journalisé), HandleInertiaRequests (rappel de notice), routes, planification, config/campus.php, .env.example, liens dans la mise en page, le profil, l'accueil, l'inscription et l'administration de la plateforme, tests/TestCase.php (journal jetable pour les tests).
Vérifications réellement exécutées : voir recette-v1.md, section lot 22. 232 tests PHPUnit réussis, 1 ignoré, 8 343 assertions ; 23 tests JavaScript ; Pint, typage, ESLint et build passés. Migration additive exécutée sur les bases locales de développement et de test.
Limites et non vérifié :
- La notice est un projet rédigé avec l'application. Elle n'a pas été relue par un juriste, et ses rubriques à compléter interdisent toute ouverture en l'état.
- Les quatre écrans du lot n'ont pas été exercés dans un navigateur : ils sont typés, compilés et couverts par les tests serveur de leurs routes.
- L'export ne contient pas les fichiers joints eux-mêmes, seulement leur liste : ils se téléchargent depuis leurs écrans tant que le compte existe.
- Ce que l'établissement conserve après une suppression (rattachement, matricule, résultats publiés) reste rattaché à une ligne de compte sans identité. Sa durée et sa justification relèvent de chaque établissement : aucune purge n'est codée ici.
- Le journal n'est écrit qu'une fois l'effacement validé en base. Si l'écriture du fichier échouait à cet instant précis, l'effacement serait fait mais absent du journal ; ce cas n'est pas rattrapé automatiquement.
- Les suppressions ordinaires (une tâche, une note) ne sont pas dans le journal : après restauration d'une sauvegarde antérieure, elles réapparaîtraient.
- Sauvegardes et restauration réelles : lot 24. Ici, la restauration est simulée en remettant les lignes en base.
- Le journal doit être placé sur un stockage distinct de la base et sauvegardé séparément (CAMPUS_ERASURE_JOURNAL_PATH) : par défaut il est dans le stockage privé de l'application, ce qui ne suffit pas en production.
- Revue d'inactivité : un nombre est affiché ; la suite à donner (information, clôture) n'est pas définie.
- Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 23 — Durcissement et mesures (22 novembre 2026)
Rapport complet : docs/audit-v1.md (constats, gravité, preuve, correction, retest, risques ouverts).
Défauts démontrés et corrigés :
- S01, élevé : une donnée contenant « <!--<script> » vidait l'écran de toute personne qui la recevait (bloc de données de page mal échappé). Corrigé dans la vue.
- S02 : aucun en-tête de sécurité global. Ajoutés sur toutes les réponses, avec une politique de contenu limitée au site.
- S03 : limite de débit commune à toutes les routes d'un compte (A43). Chaque route a désormais son compteur.
- S04 : nom de site de la requête non restreint. Restreint à APP_URL hors local et tests.
- S05 : dix-neuf vérifications textuelles des tests étaient sans effet (accents échappés). Rendues effectives ; aucune fuite révélée.
- S06 : commande campus:security:check pour contrôler les réglages avant ouverture.
- A01, A02 : champ de fichier trop petit sur mobile ; focus invisible sur les champs de date.
- P02 : deux requêtes de moins par occurrence créée à l'import (158,7 s ramenées à 138,0 s pour 1 000 lignes).
Examiné sans défaut : session et CSRF, requêtes SQL, adresses web, autorisation directe et rôles, exports, fichiers, traitements en file, synchronisation et caches, secrets côté client, nombre de requêtes par écran (aucun « N+1 » sur quinze écrans).
Dépendances : composer audit et npm audit sans alerte au 22 novembre 2026 ; aucune mise à jour.
Mesures : premier chargement à 1,6 Mbit/s en 1,3 à 1,5 s avec compression, 3,0 à 3,2 s sans ; essai de charge court sans erreur ; charge de référence non mesurée.
Accessibilité : 18 écrans à trois largeurs, clavier, contrastes, animations contrôlés automatiquement dans Edge ; aucune conformité WCAG revendiquée.

Fichiers principaux : app/Http/Middleware/SecurityHeaders.php, ThrottlePerRoute.php ; app/Console/Commands/SecurityCheck.php ; resources/views/app.blade.php ; tests/Feature/HardeningTest.php, QueryBudgetTest.php ; tests/browser/audit-edge.mjs ; tests/perf/load.mjs ; docs/audit-v1.md.
Modifications de l'existant : bootstrap/app.php (en-têtes, hôtes de confiance, limiteur), ScheduleManager (liaison des groupes d'une nouvelle occurrence), app.css (focus, champ de fichier), SyncTest (assertions sur le corps décodé).
Vérifications réellement exécutées : 243 tests PHPUnit réussis, 1 ignoré, 9 496 assertions ; 23 tests JavaScript ; 78 contrôles d'audit, 36 contrôles PWA et 35 contrôles de parcours dans Edge 158 ; Pint, typage, ESLint et build passés. Aucune migration.
Limites et non vérifié : voir docs/audit-v1.md, § 7. En résumé : charge de référence et 5 000 comptes non mesurés ; import au-dessus de 2 minutes sur ce poste ; compression et en-têtes des fichiers statiques à la charge du serveur web ; un seul navigateur, aucun téléphone ni lecteur d'écran ; revue interne, sans test d'intrusion indépendant.
Données fictives ajoutées à la base de développement par les essais : tâches « [CHARGE] » de l'essai de charge et nouvelles données « [PARCOURS … ] ». La tâche d'essai « [AUDIT] » est placée dans la corbeille par le script.
Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 24 — Déploiement et restauration (23 novembre 2026)

Résultat : préparation de l'exploitation, sans déploiement. Livré : sauvegarde chiffrée de la base et des fichiers privés avec manifeste signé et conservation de 30 jours ; restauration dans un environnement isolé avec contrôle du nombre de lignes et des empreintes ; finalisation après restauration (effacements et retraits d'accès réappliqués, anciens envois annulés, rappels périmés expirés, rappels encore valides conservés) ; supervision par composant en commande et par sonde externe ; version en service affichée ; contrôle après mise en service ; document docs/exploitation-v1.md et modèles de configuration dans docs/exploitation/.

Fichiers principaux : app/Support/BackupCipher.php, AppVersion.php ; app/Services/Backups.php, HealthChecks.php ; app/Console/Commands/BackupCommand.php, FinalizeRestore.php, HealthCommand.php, ReleaseCheck.php ; app/Http/Controllers/HealthController.php ; tests/Feature/OperationsTest.php ; docs/exploitation-v1.md ; docs/exploitation/*.example.
Modifications de l'existant : config/campus.php (sections backup et monitoring), .env.example, routes/web.php (`/sante/{check?}`), routes/console.php (sauvegarde quotidienne à 2 h si configurée), HandleInertiaRequests et CampusLayout (version en pied de page), SecurityCheck (comptes fictifs, sauvegarde, sonde), .gitignore (`/REVISION`), .github/workflows/ci.yml (tests JavaScript, audits de dépendances), HardeningTest (contrôle avant ouverture).
Commandes : `campus:backup key|create|verify|list|restore|journal`, `campus:restore:finalize`, `campus:health`, `campus:release:check`.

Vérifications réellement exécutées : 251 tests PHPUnit, 250 réussis, 1 ignoré, 9 655 assertions ; 23 tests JavaScript ; Pint, typage, ESLint et build passés. Aucune migration. Exercice réel sur la base de développement (lecture seule), sortie dans docs/preuves/lot24-sauvegarde.txt : sauvegarde de 68 tables et 6 fichiers (414 Ko chiffrés) ; vérification approfondie réussie ; 6 fichiers restaurés dans un dossier isolé et conformes ; base en service refusée comme destination ; autre clé, octet modifié et manifeste retouché refusés.

Non réalisé ou non vérifié :
- Restauration complète de la base dans une base isolée : **non réalisée**. Les comptes MySQL du poste ne peuvent pas créer de base et aucun accès d'administration n'a été fourni (AN24-01).
- Réapplication des effacements et reprise des rappels : éprouvées seulement par les tests automatiques, avec une restauration simulée, pas sur une base réellement restaurée.
- Aucun déploiement ; aucun des modèles (Nginx, PHP, Supervisor, cron, configuration de production) n'a été essayé sur un serveur (AN24-02).
- L'intégration continue n'a jamais tourné : dépôt non envoyé.
- Contrôles « antivirus », « certificat » et « envois par courriel » de la supervision : jamais vus à l'état sain, les services n'étant pas configurés.
- Sauvegarde planifiée à 2 h : condition de planification testée, exécution par un vrai cron non observée.
- RPO 24 h, RTO 8 h ouvrées, disponibilité 99,5 % : objectifs non éprouvés.
- Charge de référence et import de 1 000 lignes sur l'environnement cible (AN23-01, AN23-02) : toujours non mesurés, faute d'environnement cible.
- Rotation de `APP_KEY` : invaliderait les signatures du journal des effacements, sans outil pour les renouveler (AN24-03).

Nettoyage : le dossier temporaire de l'exercice (clé jetable, coffre) a été supprimé ; les huit comptes fictifs sont refermés.
Git : dépôt local sans commit ; envoi vers GitHub en attente d'un accès.

## Lot 25 — Recette et pilote (23 novembre 2026)

Résultat : recette finale R01 à R58 sur le poste de développement, parcours de bout en bout dans Edge pour les cinq profils, contrôle du périmètre, guide de pilote, bilan de livraison et préparation de la version sans publication.

Fichiers créés : tests/browser/recette-edge.mjs ; docs/pilote-v1.md ; docs/bilan-livraison-v1.md ; docs/preuves/lot25-recette-edge.txt, lot25-parcours-edge.txt, lot25-tests-par-scenario.txt, lot25-exploitation-locale.txt.
Modifications de l'existant : app/Support/AppVersion.php (emplacement réglable du fichier de version), tests/TestCase.php et tests/Feature/OperationsTest.php (les tests ne touchent plus le fichier réel), docs/recette-v1.md, docs/arbitrages-v1.md, README.md.

Version recettée : `1.0.0-rc1+8c70dd22c420`, empreinte du code ; fichier `REVISION` local, ignoré par Git.

Vérifications réellement exécutées : PHPUnit 251 tests, 250 réussis, 1 ignoré, 9 655 assertions ; recette-edge 19 contrôles réussis ; journeys-edge 35 contrôles réussis ; planificateur et file exercés sur le poste, supervision saine pour base, file, processus de file, planificateur et disque ; `composer audit` et `npm audit` sans alerte ; Pint passé.

Recette : 40 scénarios passés ; 15 passés en test avec une partie non exécutée ; 2 échoués (R10 second facteur, R54 durée d'import) ; 1 non exécuté (R52 restauration réelle). Recette et production : non exécuté.

Verdict : prêt pour une démonstration fictive ; pas prêt pour la recette, le pilote Fondation ni l'ouverture Opportunités. Bloquants et exigences P0 incomplètes : docs/bilan-livraison-v1.md.

Anomalie corrigée : AN25-01 (un test effaçait le fichier de version réel).

Non fait : aucun commit, aucune étiquette, aucun envoi, aucune publication ; aucune annonce réelle saisie ; aucun essai avec une vraie personne, un téléphone, un service de courriel, un antivirus ou un serveur.
Données fictives ajoutées à la base de développement : éléments « [RECETTE … ] » et « [PARCOURS … ] », dont trois fiches d'essai publiées.

## Second facteur — décision du porteur (23 novembre 2026)

Demande : « pour l'authentification, fais-le avec Google », précisée par le porteur : second facteur par code, et non connexion par compte Google.

Résultat : les comptes d'enseignement, d'administration et d'édition n'accèdent à leurs rôles qu'après avoir activé un second facteur et présenté son code dans la session. R10 passe sans dérogation. Aucune dépendance ajoutée, aucun appel à Google.

Fichiers créés : app/Support/Totp.php ; app/Models/SecondFactor.php ; app/Services/SecondFactors.php ; app/Http/Middleware/EnsureSecondFactorVerified.php ; app/Http/Controllers/SecondFactorController.php, Auth/SecondFactorChallengeController.php ; app/Console/Commands/ResetSecondFactor.php ; resources/js/pages/SecondFactor.tsx, auth/SecondFactorChallenge.tsx ; migration create_second_factors_table ; tests/Feature/SecondFactorTest.php ; tests/browser/second-factor-edge.mjs.
Modifications de l'existant : PrivilegedAccess (règle), EnsurePrivilegedAccess (message), HandleInertiaRequests (rien de privé avant le code), bootstrap/app.php, routes/web.php, ProfileController et Profile.tsx (état et lien), app.tsx, User (relation), ScheduleImporter (droits de l'auteur en traitement de fond), AccountEraser (suppression du facteur), SecurityCheck (libellé), IdentityTest (nom d'un test).

Vérifications réellement exécutées : PHPUnit 258 tests, 257 réussis, 1 ignoré, 9 940 assertions ; 23 tests JavaScript ; second-factor-edge 7 contrôles réussis sur un serveur sans dérogation ; Pint, typage, lint, build passés ; migration appliquée aux deux bases locales (ajout d'une table).

Limites :
- Aucun essai avec une vraie application sur un téléphone : les codes ont été calculés par les tests et le script, selon la RFC 6238.
- Pas de code QR : la clé se saisit à la main, ou s'ouvre par un lien sur le téléphone.
- La première activation repose sur le seul mot de passe : un compte privilégié doit activer son second facteur dès son ouverture (AN26-02).
- Récupération après perte du téléphone et des codes : par une commande de l'exploitation seulement, pas par un écran d'administration.
- Une rotation de la clé de l'application rend les secrets illisibles (AN24-03 étendue).
- recette-edge et journeys-edge non relancés : ils tournent avec la dérogation locale.

Version : `1.0.0-rc1+02e7365af89b`. Le compte fictif `admin-a` est revenu sans second facteur ; comptes fictifs refermés. Aucun commit.

## Connexion par compte Google — décision du porteur (23 novembre 2026)

Demande : « ok ajoute la connexion avec Google aussi ». La connexion par compte Google était exclue de la V1 ; la décision explicite du porteur prévaut.

Résultat : bouton « Continuer avec Google » sur la connexion, création de compte avec la même déclaration que l'inscription, rattachement d'un compte existant, confirmation des actions sensibles pour les comptes sans mot de passe. Le second facteur reste exigé après Google. Aucune dépendance ajoutée. Inactive tant que `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET` ne sont pas renseignés.

Fichiers créés : app/Services/GoogleSignIn.php ; app/Http/Controllers/Auth/GoogleSignInController.php ; app/Models/SocialIdentity.php ; resources/js/pages/auth/GoogleRegistration.tsx ; migration create_social_identities_table ; tests/Feature/GoogleSignInTest.php.
Modifications de l'existant : routes/web.php, config/services.php, .env.example, User (relation), contrôleurs de connexion, d'inscription, de confirmation et de profil (indicateurs), Login.tsx, Register.tsx, ConfirmPassword.tsx, Profile.tsx, app.tsx, AccountEraser et DataExporter (comptes reliés), HardeningTest (exception unique et vérifiée à l'interdiction d'appel sortant ; création de compte Google parmi les écritures ouvertes aux visiteurs).

Vérifications réellement exécutées : PHPUnit 268 tests, 267 réussis, 1 ignoré, 10 277 assertions ; Pint, typage, lint, build passés ; migration appliquée aux deux bases locales ; bouton et départ vérifiés sur un serveur local avec des identifiants fictifs, sans appel à Google.

Non vérifié :
- **Aucune connexion réelle par Google** : il faut un identifiant OAuth créé par le porteur dans la console Google Cloud, avec l'adresse de retour `<APP_URL>/connexion/google/reponse`.
- Écrans non exercés dans un navigateur au-delà du bouton.
- La notice de confidentialité ne mentionne pas encore Google comme prestataire d'identification (AN27-02).
- Pas d'écran pour détacher un compte Google (AN27-03).
- La signature du jeton d'identité n'est pas vérifiée cryptographiquement : le jeton est reçu directement de Google sur une liaison chiffrée, authentifiée par le secret du client (T199).

Version : `1.0.0-rc1+b041ea833cf1`. Aucun commit.

## Nouveau design — demande du porteur (24 novembre 2026)

Demande : appliquer à l'application le design « Magic Schedule — Parcours étudiant et professeur » (56 écrans : élève et enseignant, téléphone et ordinateur), avec les composants et conventions du dépôt.

Résultat : toute l'application adopte le langage visuel du design, sans changer ses règles ni ses données. Police Plus Jakarta Sans hébergée dans l'application ; fond lavande, barre latérale et cartes d'accent bleu nuit, violet d'action, pastilles arrondies ; navigation basse à pastille sur téléphone ; cloche de notifications ; pastille du compte en bas de la barre latérale ; écrans de connexion et d'inscription en deux panneaux ; accueil en tableau de bord (salutation, prochain cours, cours du jour, prochaine action, tâches, notes, opportunités) ; onglets du planning.

Fichiers créés : resources/js/layouts/AuthLayout.tsx ; public/fonts/ (deux fichiers de police et leur licence OFL) ; docs/preuves/design-accueil-ordinateur.png, design-accueil-telephone.png, design-connexion-ordinateur.png.
Modifications de l'existant : resources/css/app.css (réécrite, mêmes classes), Icon.tsx, CampusLayout.tsx, Home.tsx, Login.tsx, Register.tsx, public/offline.html, public/manifest.webmanifest, app.blade.php (couleur de thème), public/sw.js (version v3), PwaTest (couleur de thème).

Vérifications réellement exécutées : PHPUnit 268 tests, 267 réussis, 1 ignoré ; 23 tests JavaScript ; typage, lint, build ; dans Edge 158 : audit d'accessibilité 78 contrôles réussis (contrastes mesurés, cibles de 44 px, focus visible, 1 280, 360 et 320 px), parcours 35 contrôles réussis, PWA 36 contrôles réussis ; captures relues à 1 440 et 390 px.

Écarts volontaires avec le design :
- Nom et textes : « HAKO-RA Campus » et le vouvoiement de l'application sont conservés ; aucune donnée d'exemple du design n'est reprise.
- Bordure des champs plus foncée que dans le design, pour rester visible (contraste d'au moins 3:1) ; bouton de suppression en rouge foncé, pour un texte lisible.
- Bouton « Microsoft » de la connexion non repris : seule la connexion Google existe.
- Carte « Recommandation Magic » remplacée par « Votre prochaine action », tirée seulement de données réelles (tâche en retard, retour en attente).

Écrans du design non construits, parce qu'ils correspondent à des fonctions absentes ou exclues de la V1 : assistant (IA), Premium et paiement, bibliothèque de documents, signalement d'une difficulté et difficultés de la classe, signalement d'absence d'un élève, annonces aux classes, progression hebdomadaire en graphique, recherche globale. Chacun demande une décision du porteur (A57).

Non vérifié : aucun téléphone réel, Safari, Firefox ni lecteur d'écran ; les icônes de l'application installée gardent leur ancienne couleur ; recette-edge et second-factor-edge non relancés (ils s'appuient sur les mêmes libellés, inchangés).

Version : `1.0.0-rc1+7630c2444224`. Rien n'a été validé dans Git pour ce travail.
