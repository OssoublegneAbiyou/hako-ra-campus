# Bilan de livraison — HAKO-RA Campus V1

Lot 25, 23 novembre 2026. Version recettée : `1.0.0-rc1+b041ea833cf1` (empreinte du code du poste ; aucun commit n'existe).

## 1. Verdict

| Niveau | Verdict | Fondé sur |
|---|---|---|
| Prêt pour une démonstration fictive | **Oui**, sur le poste de développement, avec le jeu fictif | 268 tests PHPUnit (267 réussis, 1 ignoré), 23 tests JavaScript, 61 contrôles dans Edge sans échec |
| Prêt pour la recette | **Non** | Aucun environnement de recette n'existe : ni hébergement, ni accès, ni nom de domaine. Le dépôt n'a jamais été envoyé, l'intégration continue n'a jamais tourné |
| Prêt pour le pilote Fondation | **Non** | Bloquants B2 à B8 ci-dessous (B1 levé) |
| Prêt pour l'ouverture Opportunités | **Non** | Bloquants B2 à B8, plus B9 et B10 |

« Code implémenté » ne vaut pas « production validée ». Le code des lots 00 à 24 est écrit et testé sur un poste ; rien n'a été validé sur un serveur, avec de vraies personnes ou avec de vrais services d'envoi.

## 2. Ce qui est établi

- **Cloisonnement et droits.** Aucun accès croisé trouvé. Les scénarios R05 à R09, R41, R50 et R58 passent en tests ; dans Edge, l'administration B ne voit ni le planning ni les devoirs de A, et l'apprenant des deux établissements ne retrouve en B ni le cours ni le devoir de A.
- **Calculs.** 12,67 et 13,60 obtenus en tests sur valeurs non arrondies ; 12,67 affiché dans Edge après saisie réelle de 14/20 (coefficient 1) et 36/60 (coefficient 2).
- **Parcours de bout en bout dans Edge**, version recettée, comptes fictifs : inscription visible, planning personnel, tâche, note, retour après cours, invitation et départ, import Excel valide appliqué puis publié, report d'une occurrence, signalement d'indisponibilité, devoir de groupe, fiche créée, vérifiée et publiée par l'éditeur, favori, recherche enregistrée, alerte reçue dans le centre, tâche écrite sans réseau puis envoyée une seule fois, fichier déposé.
- **Dépendances.** `composer audit` et `npm audit --omit=optional` : aucune vulnérabilité connue ce jour.
- **Périmètre.** Aucune fonctionnalité exclue de la V1 trouvée dans les écrans ni dans les routes. Aucune interface simulée : le seul écran d'exemples (atelier des composants) répond 404 hors poste de développement et tests. Aucun pilote d'envoi ou d'antivirus factice n'est activable : sans configuration, courriel, notifications du navigateur et antivirus sont déclarés indisponibles, pas simulés.

## 3. Bloquants

| N° | Bloquant | Nature | Empêche | Ce qu'il faut |
|---|---|---|---|---|
| B1 | ~~Second facteur non livré~~ — **levé le 23 novembre 2026** : code temporaire à six chiffres livré et vérifié sans dérogation (R10) | sécurité | plus rien, sous réserve d’un essai avec une vraie application sur un téléphone | essai par le porteur avec Google Authenticator |
| B2 | Aucun service de courriel configuré ni essayé | dépendance essentielle non vérifiée | toute inscription réelle : vérification d'adresse et récupération de mot de passe impossibles | choix du service (A19, A34), essai réel |
| B3 | Restauration complète de la base non réalisée (R52, OPS07) | perte de données | tout pilote | une base isolée, puis l'exercice de docs/exploitation-v1.md § 7 |
| B4 | Aucun hébergement : HTTPS, sauvegarde planifiée, supervision, file et planificateur supervisés jamais vus sur un serveur | dépendance essentielle non vérifiée | recette et tout pilote | hébergement, domaine, puis essai de chaque modèle de configuration |
| B5 | Notice de confidentialité incomplète (responsable, contact, région) et non validée | cadre d'accueil | toute donnée réelle | renseignements du porteur, validation par une personne qualifiée |
| B6 | Cadre des mineurs non validé | cadre d'accueil | le lycée, et toute inscription de mineur | validation par des personnes qualifiées (A06) ; le parcours reste fermé dans le logiciel |
| B7 | Support : aucun contact, aucune personne désignée | cadre d'accueil | tout pilote | canal et titulaire (A12) |
| B8 | Capacité non mesurée : charge de référence jamais exécutée ; import de 1 000 lignes en 138 s sur le poste pour une cible proposée de 120 s (R54) | dépendance non vérifiée | montée au-delà d'une petite cohorte ; import réel d'un établissement | mesure sur l'environnement cible |
| B9 | Antivirus non installé (FIL04) | dépendance essentielle non vérifiée | toute pièce jointe téléchargeable, donc les fichiers de devoirs | installation de ClamAV, essai avec le fichier de test normalisé |
| B10 | Aucun responsable éditorial ; aucune annonce réelle vérifiée | cadre d'accueil | l'ouverture du catalogue | désignation (A13), 20 à 30 annonces réelles vérifiées par lui |

B9 gêne aussi Fondation, sans l'empêcher : sans antivirus, un fichier déposé est reçu mais ne peut pas être téléchargé.

Aucun bloquant de type « accès croisé » ou « calcul erroné » n'a été trouvé.

## 4. Exigences P0 incomplètes

Liste explicite. « Incomplet » signifie : non livré, livré mais jamais vérifié dans des conditions réelles, ou écarté par arbitrage.

| Exigence | État | Détail |
|---|---|---|
| IAM07 — second facteur des comptes privilégiés | livré, non vérifié sur téléphone | code temporaire, codes de secours, retrait tracé ; aucune application réelle essayée ; pas de code QR, la clé se saisit à la main |
| SEC11 — revérification pour les changements de rôle privilégié | livré | mot de passe redemandé, second facteur présenté dans la session, audit |
| IAM01, IAM03 — adresse vérifiable, récupération | livré, non vérifié en réel | aucun courriel n'a jamais été reçu |
| NTF03, NTF04 — états et reprises des envois externes | livré, non vérifié en réel | envois externes simulés dans les tests seulement |
| NTF08, NTF09 — notifications du navigateur | livré, non vérifié en réel | aucune clé configurée, aucun appareil réel |
| FIL04 — analyse antivirus | livré, non vérifié en réel | aucun démon installé ; les fichiers restent en quarantaine |
| IAM08 (P1) — connexion par compte Google | livré sur décision du porteur, non vérifié en réel | aucun identifiant Google fourni : aucun échange réel ; notice de confidentialité à compléter avant ouverture |
| CHK06 — agrégation des difficultés | non livré, par arbitrage | A30 : rien n'est construit sans décision |
| GRD05 — moyenne annuelle pondérée | non livré, par arbitrage | T53 : calcul par période seulement tant qu'aucune pondération n'est validée |
| OPP03 — source accessible ou preuve | livré selon arbitrage | vérification par acte humain daté ; Campus n'interroge jamais la source |
| PRV01 — notice complète | incomplet | responsable, contact et région non renseignés ; non validée |
| PRV03 — demandes de droits | livré, non vérifié en réel | écrans non exercés dans un navigateur ; aucun responsable désigné pour les traiter |
| PRV06 — réapplication après restauration | livré, non vérifié en réel | réussi en test avec restauration simulée seulement |
| QUA05 — zoom à 200 % | non vérifié | essais à 320 px seulement ; zoom natif non essayé |
| QUA06 — ressources initiales limitées | partiel | mesuré sur le poste ; compression à la charge du serveur web, non essayée |
| QUA07 — Chrome Android, Safari iOS, Chrome, Edge | incomplet | Edge sur ordinateur seulement |
| SEC01 — HTTPS et cookies protégés | livré, non vérifié en réel | dépend du serveur |
| SEC06 — rotation des secrets | partiel | procédure écrite ; rotation de la clé de l'application non outillée (AN24-03) |
| SEC10, OPS01 — environnements séparés | préparé | seul le poste de développement existe |
| OPS02 — contrôles, compilation et déploiement automatisés | partiel | contrôles et compilation préparés, jamais exécutés à distance ; déploiement manuel documenté, non automatisé |
| OPS03, OPS04 — sauvegarde avant migration, contrôles après déploiement | préparé | commandes livrées, jamais exécutées sur un serveur |
| OPS05, OPS06 — supervision et alertes | préparé | file et planificateur vus sains sur le poste ; aucune sonde externe, aucun contact d'alerte |
| OPS07 — restauration complète isolée avant le pilote | non réalisé | voir B3 |
| OPS08, OPS09 — responsables, incidents, accès de secours | partiel | procédures écrites ; personnes non désignées |

Les autres exigences P0 sont livrées et couvertes par les tests et les parcours cités dans docs/recette-v1.md.

## 5. Recette en chiffres

| | Résultat |
|---|---|
| Scénarios R01 à R58 passés sans réserve, en local | 40 |
| Passés en test avec une partie non exécutée (appareil réel, service réel, navigateur, temps réel) | 16, dont R10 |
| Échoués | 1 : R54 (durée d'import ; charge non exécutée) |
| Non exécutés | 1 : R52 (restauration réelle) |
| En recette et en production | 58 non exécutés : ces environnements n'existent pas |

Détail ligne par ligne : docs/recette-v1.md, section « Lot 25 — recette finale ».

## 6. Préparation de la version, sans publication

Rien n'a été validé dans Git, étiqueté, envoyé ni publié. Tout le travail est dans le dossier local, sans aucun commit.

Proposition, à exécuter seulement sur instruction du porteur :

1. Relire le diff. Le dépôt ne contient aucun secret : `.env` est ignoré ; `REVISION` aussi.
2. Premier commit du dépôt, puis envoi vers le dépôt distant quand l'accès existe. L'intégration continue tournera alors pour la première fois : son résultat est à lire avant toute suite.
3. Étiquette proposée : `v1.0.0-rc1`. Le suffixe « rc » dit que la version est candidate à la recette, pas validée.
4. À la mise en service sur un environnement de recette : fichier `REVISION` portant l'étiquette et l'identifiant du commit, puis la procédure de docs/exploitation-v1.md § 5.

Notes de version proposées :

```text
HAKO-RA Campus v1.0.0-rc1 — candidate à la recette

Contenu : comptes et invitations, contextes par établissement, référentiels, planning personnel
et institutionnel, import Excel, publication versionnée, retours privés après cours, tâches et
devoirs, notes et moyennes indicatives, rappels, pièces jointes, catalogue des opportunités,
recherche, favoris, démarches, alertes, lecture et brouillons hors connexion, droits des
personnes, sauvegarde chiffrée, supervision.

Second facteur des comptes privilégiés : code temporaire à six chiffres (application d'authentification).
Connexion par compte Google : facultative, inactive sans identifiants ; jamais essayée avec Google.
Non vérifié en conditions réelles : courriel, notifications du navigateur, antivirus,
restauration complète, hébergement, charge de référence, téléphones.
Ne pas ouvrir à des personnes réelles en l'état : voir docs/bilan-livraison-v1.md.
```

## 7. Suite proposée, dans l'ordre

1. Décisions du porteur : hébergement et domaine, service de courriel. Essai du second facteur avec une vraie application sur un téléphone.
2. Second facteur : livré le 23 novembre 2026.
3. Base isolée, puis exercice de restauration complète.
4. Environnement de recette : mise en service, essai réel du courriel, de l'antivirus, des sauvegardes planifiées et de la supervision ; mesure de charge.
5. Notice, support, cadre des mineurs.
6. Recette R01 à R58 rejouée sur l'environnement de recette, téléphones compris.
7. Pilote, étape 1 (docs/pilote-v1.md).
