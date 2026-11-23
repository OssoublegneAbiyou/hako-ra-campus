# Audit de durcissement et de mesure — HAKO-RA Campus V1

Lot 23, 22 novembre 2026. Revue corrective ciblée du code réel, mesures locales, contrôles d'accessibilité automatiques.

Ce document rapporte ce qui a été examiné, ce qui a été trouvé, ce qui a été corrigé et retesté, et ce qui reste ouvert. Il ne vaut ni test d'intrusion par un tiers, ni audit d'accessibilité, ni attestation de conformité à une norme ou à une réglementation.

## 1. Périmètre, méthode, environnement

- Code examiné : dépôt local au 22 novembre 2026, lots 00 à 22. Aucun environnement distant, aucun service tiers, aucune production.
- Méthode : lecture ciblée des surfaces (session et CSRF, validation, contenus saisis, requêtes SQL, autorisation directe, invitations, rôles, exports, fichiers, traitements en file, synchronisation, caches) ; chaque défaut est d'abord reproduit, puis corrigé, puis couvert par un test de régression.
- Poste : Windows 10, Intel Core i7-1165G7, 8 Go de RAM ; PHP 8.4.15 ; MySQL 8.4 local ; Node 24.12 ; Edge 158 sans interface (moteur Chromium), piloté par son protocole de débogage.
- Bases : hako_ra_campus_test pour les tests PHPUnit ; hako_ra_campus_dev pour les contrôles en navigateur et l'essai de charge, avec les comptes fictifs @example.test ouverts le temps de chaque passage puis refermés.
- Serveur des essais en navigateur : `php artisan serve` (serveur de développement de PHP : une requête à la fois, aucune compression).

## 2. Constats

Gravité : élevée (atteinte d'autres comptes ou indisponibilité pour autrui), moyenne (protection manquante ou cible non tenue), faible (défaut local, qualité).

| N° | Constat | Gravité | Preuve | Correction | Retest |
|---|---|---|---|---|---|
| S01 | Les données de chaque page étaient insérées dans un bloc de script JSON sans échapper les chevrons. Une donnée contenant `<!--<script>` (titre d'un devoir, d'une fiche du catalogue, nom d'un membre) faisait avaler le reste du document par ce bloc : écran vide pour toute personne recevant cette donnée. Aucune exécution de script, mais une indisponibilité déclenchable par un compte sur les écrans des autres. | Élevée | Reproduit avec l'analyseur HTML5 de PHP (`Dom\HTMLDocument`) : le bloc contenait la suite de la page et l'élément suivant n'existait plus. La directive `@inertia` du paquet n'échappe que la barre oblique. | La vue écrit elle-même le bloc avec `JSON_HEX_TAG` et `JSON_HEX_AMP` (resources/views/app.blade.php). | HardeningTest (balises et séquence de rupture : bloc fermé, conteneur présent, données relues à l'identique) ; Edge : texte affiché tel quel sur l'écran des tâches et l'accueil, page intacte, aucune image ni script créé. |
| S02 | Aucun en-tête de sécurité global : la page pouvait être affichée dans un cadre tiers, aucune politique de contenu, pas de consigne sur le type de contenu ni sur le référent pour les pages. | Moyenne | Lecture des réponses : seul le téléchargement d'une pièce jointe portait une politique. | Middleware SecurityHeaders sur toutes les réponses, pages d'erreur comprises : politique de contenu limitée au site (scripts sans exception en ligne), refus des cadres, `nosniff`, référent restreint, capteurs désactivés, HSTS sur requête sécurisée. | HardeningTest (six types de réponses) ; Edge : aucun refus de la politique sur l’ensemble des chargements du contrôle ; scripts PWA et parcours rejoués sans échec. |
| S03 | Le limiteur de débit identifiait un compte connecté par son seul numéro : toutes les routes limitées partageaient un compteur. Une route à plafond bas (suppression du compte, demandes) était refusée après quelques autres actions du même compte (arbitrage A43). | Moyenne | Rencontré aux lots 17 et 22 ; reproduit : soixante créations de tâches bloquaient ensuite toute autre action limitée. | ThrottlePerRoute : le compteur porte sur le compte et la route. | HardeningTest : la 61e création est refusée, les autres routes du compte et les autres comptes restent ouverts, le compteur repart à la minute suivante. |
| S04 | Le nom de site de la requête (en-tête Host) n'était pas restreint : un lien d'invitation ou de réinitialisation est construit avec ce nom. | Moyenne | Lecture de la configuration : aucun hôte de confiance déclaré. | Hors local et tests, seules les requêtes adressées au nom du site (APP_URL) sont acceptées. | HardeningTest : nom forgé, suffixe forgé et sous-domaine refusés (400), nom exact accepté. |
| S05 | Dix-neuf vérifications textuelles des tests existants étaient sans effet : dans les pages et les réponses JSON, les lettres accentuées étaient échappées, si bien que « ce texte accentué n'apparaît pas » était toujours vrai. Elles étaient le plus souvent doublées d'un contrôle de structure, mais ne prouvaient rien par elles-mêmes. | Moyenne (qualité des preuves) | Relevé automatique des assertions portant sur le corps brut avec un texte accentué. | Les pages écrivent maintenant leur texte tel quel (UTF-8) ; les assertions sur des réponses JSON relisent le corps décodé. | Suite complète rejouée avec des vérifications devenues effectives : aucune fuite révélée. |
| S06 | Rien ne contrôlait les réglages avant ouverture : le fichier d'exemple fixe un cookie de session non réservé à HTTPS et le mode de débogage, valeurs de développement. | Faible | Lecture de .env.example. | Commande `campus:security:check` : quinze réglages bloquants, trois à vérifier, sans afficher aucune valeur. | HardeningTest : échec en configuration de test, réussite en configuration d'ouverture, retour à l'échec si le débogage est réactivé. |
| A01 | Sur mobile, le champ de choix d'un fichier mesurait 24 px de haut (cible tactile trop petite). | Faible | Relevé Edge à 360 px. | Hauteur minimale de 44 px, bouton interne compris. | Edge : aucun contrôle sous 44 px sur 18 écrans. |
| A02 | Au clavier, les champs de date n'affichaient pas de contour de focus (le navigateur ne cerne que le segment interne). | Faible | Parcours au clavier dans Edge : trois champs sans contour. | Contour de focus pour tous les champs, y compris quand le focus est dans leur partie interne. | Edge : 45 tabulations, focus toujours visible. |
| P01 | Premier chargement sur réseau bridé (1,6 Mbit/s, 150 ms) : 3,0 à 3,2 s sans compression, au-dessus de la cible de 3 s. | Moyenne | Mesure Edge, serveur de développement sans compression, 460 à 496 Ko transférés. | Aucune dans le code : la compression relève du serveur web. Mesuré aussi à travers un relais local qui compresse (gzip) : 1,3 à 1,5 s, 143 à 155 Ko. | Voir § 4. La compression du serveur web est une condition d'ouverture (lot 24). |
| P02 | Import de 1 000 lignes : 158,7 s, au-dessus de la cible de 120 s, et plus lent qu'au lot 09 (121,3 s). | Moyenne | Mesure sur demande, base de test, sans débogueur. | Deux requêtes de moins par occurrence créée (liaison des groupes écrite sans relecture). | 138,0 s après correction : cible toujours non tenue sur ce poste. Voir § 4 et risques ouverts. |

### Examiné sans défaut démontré

| Surface | Ce qui a été vérifié | Preuve |
|---|---|---|
| Session et CSRF | Toutes les routes d'écriture (plus de 80) sont dans le groupe « web » ; hors entrées d'identité, toutes exigent une session ; aucune exception CSRF déclarée ; déconnexion par POST ; session régénérée à la connexion, à l'inscription et à l'activation ; cookie inaccessible aux scripts, limité au site, chiffré, réservé à HTTPS en production. | HardeningTest ; lecture du code. |
| Injection SQL | Les requêtes brutes du code : toutes avec des paramètres liés ou des constantes. | Lecture du code. |
| Contenus saisis | Aucun écran n'insère de HTML venu des données ; balises saisies dans un nom, un titre, une matière : enregistrées et affichées comme du texte. | HardeningTest ; Edge. |
| Adresses web | Lien de cours et adresses des fiches : `http` et `https` seulement ; `javascript:`, `data:`, `file:`, identifiants dans l'adresse refusés ; aucun client HTTP sortant dans le code pour ces adresses. | HardeningTest. |
| Autorisation directe (R09, R58) | Sept acteurs (autre apprenant, apprenant et enseignant de A, administrations A et B, éditeur, administration de la plateforme) contre les données privées d'un compte : lecture et écriture directes introuvables, synchronisation refusée sans indice, contenu absent de leurs pages, de leur copie hors connexion et des écrans d'administration. | HardeningTest (test_r58, test_r09). |
| Rôles et invitations (R09) | Une administration d'établissement ne peut donner aucun rôle d'administration, d'édition ou de plateforme ; apprenant et enseignant ne peuvent inviter ; le contexte d'un autre est introuvable ; un numéro d'établissement envoyé par le client n'ouvre rien. | HardeningTest ; InvitationContextTest. |
| Exports | Export des membres : cellules neutralisées contre les formules, périmètre de l'établissement ; rapport d'import : idem ; export personnel : colonnes choisies, propriétaire seul. | Lecture du code ; PrivacyTest. |
| Fichiers (R49, R50) | Types réels, quarantaine, lien sans droit : tests du lot 14 rejoués ; téléchargement avec sa propre politique « rien ne s'exécute ». | AttachmentTest ; HardeningTest. |
| Traitements en file (R43, R53) | Droits revérifiés dans le traitement lui-même ; rejeu sans doublon. Constaté pendant ce lot : l'analyse d'un import s'arrête si les droits d'administration ne sont pas ouverts dans le processus de file. | NotificationTest ; journeys-edge. |
| Synchronisation et caches (R45 à R48) | Tests des lots 19 et 20 rejoués ; réponses « no-store » ; aucune donnée privée en cache partagé. | SyncTest ; PwaTest ; pwa-edge. |
| Secrets côté client | Données partagées avec chaque page : nom et clé opaque seulement ; fichiers servis au navigateur sans clé, mot de passe ni variable du serveur. | HardeningTest. |
| Journaux | Masquage des données sensibles : test du lot 01 rejoué. | ApplicationFoundationTest. |
| Requêtes fréquentes | Quinze écrans (individuels et d'établissement) : le nombre de requêtes ne croît pas quand les lignes passent de 2 à 12 ; plafond de 60 par écran. | QueryBudgetTest. |

Observation sans gravité : supprimer la note d'un autre compte renvoie la même réponse de succès que pour une note déjà supprimée, sans rien supprimer. Le comportement est uniforme et ne révèle rien ; il est couvert par le test R58.

## 3. Dépendances

Outils présents, exécutés le 22 novembre 2026 avec accès au réseau :
- `composer audit` : aucune alerte de sécurité.
- `npm audit --omit=optional` : 0 vulnérabilité.

Versions directes : laravel/framework 13.42.0, inertiajs/inertia-laravel 3.0.0, laravel/tinker 3.1.0 ; en développement : laravel/boost 2.10.4, laravel/pint 1.32.3, fakerphp/faker 1.24.1. Côté navigateur : react 19.3, @inertiajs/react 3.11, vite 8, tailwindcss 4, typescript 5.9. Aucune mise à jour n'a été faite dans ce lot.

Ces audits ne voient que les failles publiées à leur date : ils sont à relancer avant toute ouverture.

## 4. Mesures

Aucune de ces mesures n'est une garantie. Chacune vaut pour l'environnement décrit.

| Cible | Mesuré | Environnement | Verdict |
|---|---|---|---|
| Contenu principal en moins de 3 s sur réseau simulé à 1,6 Mbit/s et 150 ms | Première visite sans cache, réponses compressées : 1,34 à 1,52 s (143 à 155 Ko). Sans compression : 3,01 à 3,17 s (460 à 496 Ko). Visite suivante avec cache : 0,47 à 0,65 s. Sept écrans, visiteur et compte connecté. | Edge 158, réseau bridé par le navigateur, serveur local ; compression par un relais gzip local qui tient lieu de serveur web. | Tenue avec compression ; non tenue sans. |
| Import de 1 000 lignes en moins de 2 minutes | 138,0 s (dépôt et analyse 3,0 s ; application 135,0 s) ; 158,7 s avant correction. 1 000 séries, 8 000 occurrences, 78 Mo. | Poste décrit au § 1, base de test, traitement dans le processus de test, sans débogueur. | Non tenue sur ce poste. |
| 95 % des réponses courantes sous 800 ms ; erreurs techniques sous 1 % | Essai court : 5 utilisateurs virtuels, 60 s, 221 requêtes ; médiane 221 ms, 95e centile 771 ms, 99e centile 1 129 ms ; 0 erreur technique, 0 refus de débit. | Serveur de développement (une requête à la fois), base de développement avec les seuls comptes fictifs. | Essai court seulement : ne vaut pas mesure. |
| Charge mixte de 100 utilisateurs pendant 30 minutes, 5 000 comptes fictifs, 1 000 actifs par jour | Non mesuré. | Demande un environnement proche de la production et un jeu de 5 000 comptes, qui n'existent pas encore. | Non vérifié. |
| Requêtes par écran | 6 à 35 requêtes selon l'écran, constantes quel que soit le nombre de lignes. | Base de test. | Pas de « N+1 » trouvé. |

Outil de charge : `node tests/perf/load.mjs --smoke` (essai court local) ou `--full` (mesure complète). Il refuse une cible distante sans `--allow-remote` et ne doit jamais viser un service tiers ni la production.

## 5. Accessibilité

Contrôles automatiques dans Edge 158, sur 18 écrans (7 pour un visiteur, 11 pour un compte connecté), à trois largeurs : 1 280 px, 360 px, et 320 px (équivalent d'un zoom à 200 %).

| Point | Résultat |
|---|---|
| Défilement horizontal | Aucun, sur les 54 relevés. |
| Libellés des champs, nom des boutons et des liens, texte de remplacement des images | Aucun manque relevé. |
| Structure : langue, titre de page, un seul titre principal, pas de saut de niveau, zone principale | Conforme sur les 54 relevés. |
| Contraste des textes (4,5:1, ou 3:1 pour les grands textes) | Aucun texte sous le seuil parmi ceux mesurés. Non mesurés : textes sur fond en image ou en dégradé, opacité héritée, états survolés ou désactivés. |
| Taille des cibles à 360 px | 44 px au moins partout, après correction du champ de fichier (A01). |
| Clavier | Lien d'évitement en premier ; 33 éléments distincts en 45 tabulations sur l'écran des tâches ; focus toujours visible après correction (A02) ; une boîte de confirmation garde le focus et se ferme avec Échap. |
| Erreurs de saisie | Champ marqué invalide et relié à son message ; saisie conservée. |
| Réduction des animations | Avec la préférence du système, aucune animation ni transition active. |

Limites : ces contrôles ne remplacent ni un essai avec un lecteur d'écran, ni un essai avec des personnes concernées, ni une évaluation complète des critères WCAG. Aucune conformité WCAG AA n'est revendiquée.

### Configurations testées et non testées

| Configuration | État |
|---|---|
| Edge 158 sur ordinateur (Windows 10) | Testé : parcours, PWA, accessibilité, temps de chargement. |
| Chrome sur ordinateur | Non testé : non installé sur ce poste. Même moteur qu'Edge, sans que cela vaille essai. |
| Chrome sur Android | Non testé : aucun appareil. Largeur de 360 px simulée seulement. |
| Safari sur iPhone et iPad | Non testé : aucun appareil. |
| Firefox | Non testé. |
| Lecteurs d'écran (NVDA, VoiceOver, TalkBack) | Non testé. |
| Zoom natif du navigateur à 200 % | Non testé ; seule une largeur équivalente de 320 px l'a été. |

### Limites de la PWA par capacité

| Capacité | Chromium (ordinateur, Android) | Safari (iPhone, iPad) | Firefox |
|---|---|---|---|
| Installation | Proposée par le navigateur ; vérifiée dans Edge. | Par « Sur l'écran d'accueil », sans proposition automatique ; non vérifiée. | Pas d'installation sur ordinateur ; non vérifiée. |
| Page hors ligne et brouillons (service worker, IndexedDB) | Vérifiés dans Edge. | Pris en charge en principe ; le stockage d'un site non installé peut être effacé après sept jours sans visite : des brouillons non envoyés seraient perdus. Non vérifié. | Pris en charge en principe ; non vérifié. |
| Notifications du navigateur | Non configurées (aucune clé) : non vérifiées. | Seulement pour un site installé ; non vérifiées. | Non vérifiées. |
| Synchronisation en arrière-plan | Non utilisée par Campus : l'envoi se fait à l'ouverture, au retour du réseau ou sur demande. | Idem. | Idem. |

## 6. Scénarios de recette rejoués

| Scénario | Preuve au lot 23 | État |
|---|---|---|
| R09 | HardeningTest (rôles, contexte, objets du second établissement) ; InvitationContextTest rejoué | Réussi en tests |
| R43 | NotificationTest, OpportunityAlertTest rejoués | Réussi en tests |
| R44 | NotificationTest, tests du service worker, pwa-edge rejoués | Partiel : aucun téléphone réel |
| R45, R46, R47, R48 | SyncTest, PwaTest, tests JavaScript, pwa-edge rejoués | Réussis en tests ; R46 et R47 partiels en navigateur (voir lot 20) |
| R49, R50 | AttachmentTest rejoué ; politique du téléchargement vérifiée | Réussis en tests ; antivirus réel non vérifié |
| R54 | Import 138,0 s ; essai de charge court ; charge de référence non mesurée | Partiel : cibles non tenues ou non vérifiées |
| R55 | 18 écrans à trois largeurs, clavier, contrastes, animations dans Edge | Partiel : un seul navigateur, pas de lecteur d'écran |
| R57 | TaskTest, SyncTest, parcours Edge rejoués (envois répétés, saisie conservée) | Partiel : coupure de réseau pendant un envoi non exercée en navigateur |
| R58 | HardeningTest (test_r58) : toutes les données privées, sept acteurs dont la plateforme | Réussi en tests |

Suite complète au 22 novembre 2026 : 243 tests PHPUnit réussis, 1 ignoré (mesure sur demande), 9 496 assertions ; 23 tests JavaScript ; 78 contrôles d'audit, 36 contrôles PWA et 35 contrôles de parcours dans Edge.

## 7. Risques encore ouverts

| N° | Risque | Gravité | Suite |
|---|---|---|---|
| O01 | Second facteur non livré : les rôles d'enseignement, d'administration et d'édition ne peuvent pas être ouverts hors dérogation locale. | Bloquant pour tout pilote privilégié | Décision du porteur (lot dédié). |
| O02 | Charge de référence non mesurée (100 utilisateurs pendant 30 minutes, 5 000 comptes). | Élevée avant ouverture | Lot 24 : environnement décrit, jeu de 5 000 comptes fictifs, `load.mjs --full`. |
| O03 | Import de 1 000 lignes au-dessus de 2 minutes sur le poste de mesure (138 s). | Moyenne | À remesurer sur l'environnement cible ; si la cible n'est toujours pas tenue, regrouper le contrôle des chevauchements par série. |
| O04 | La compression des réponses et les en-têtes de sécurité des fichiers statiques (service worker, page hors ligne, fichiers de build) dépendent du serveur web, pas de l'application. Sans compression, le premier chargement dépasse 3 s. | Moyenne | Lot 24 : configuration du serveur web, puis nouvelle mesure. |
| O05 | La politique de contenu autorise les styles en ligne (`style-src 'unsafe-inline'`), nécessaires aux attributs de style des écrans. Les scripts en ligne restent interdits. | Faible | À resserrer si les styles en ligne sont retirés des écrans. |
| O06 | Aucun essai sur téléphone, sur Safari, sur Firefox, ni avec un lecteur d'écran. | Moyenne | Recette manuelle avant ouverture. |
| O07 | Antivirus, envoi de courriels et notifications du navigateur non configurés : jamais vérifiés avec un service réel. | Moyenne | Choix et configuration par le porteur. |
| O08 | Notice de confidentialité à compléter et à faire valider (lot 22). | Bloquant pour l'ouverture | Porteur, avec un conseil juridique. |
| O09 | Le journal de navigateur de l'outil de développement Laravel Boost injecte un script en ligne en local ; la politique de contenu le refuse. Sans effet sur l'application ; l'outil n'existe pas hors développement. | Sans gravité | Désactivable par BOOST_BROWSER_LOGS_WATCHER=false. |
| O10 | Cette revue est interne. Aucun test d'intrusion indépendant n'a été fait. | À apprécier | Décision du porteur avant ouverture. |

## 8. Comment rejouer

```
php artisan test --compact
npm run test:js
node tests/browser/audit-edge.mjs        # accessibilité, contenu saisi, temps de chargement
node tests/browser/pwa-edge.mjs          # PWA et hors connexion
node tests/browser/journeys-edge.mjs     # parcours complets
node tests/perf/load.mjs --smoke         # essai de charge court, local
CAMPUS_MEASURE=1 php artisan test --filter=measured_import
composer audit ; npm audit --omit=optional
php artisan campus:security:check        # réglages avant ouverture
```

Preuves conservées : docs/preuves/lot23-audit-edge.txt, docs/preuves/lot23-charge-essai-court.txt.
