# Recette V1

Source : cahier v1.0 chapitre 23, pages 29–31 ; référence intégrale paginée dans reference-cahier-v1.txt. R58 adapté à la décision récente : aucun accès de support privé. Les autres situations et attendus sont repris de la source.
Lots responsables réalignés le 15 novembre 2026 sur la matrice du cahier de prompts (docs/cahier-prompts-v1.md, section 6), désormais présent dans le dépôt. Les statuts de chaque ligne restent ceux des preuves réellement exécutées.

## Jeu fictif et preuve
Deux établissements Campus Alpha/Bêta, un indépendant, un membre des deux, un enseignant affecté à un seul groupe, un administrateur par établissement et un éditeur. Adresses example.test ; aucune identité réelle.
Préparer note manquante, cours reporté, conflit, import identique, annonce expirée et brouillon en attente.
Chaque preuve future indique version, environnement, acteur/contexte, préconditions, étapes, attendu/obtenu, commande ou capture expurgée, anomalie et retest.

## Scénarios

La matrice finale, scénario par scénario, avec étapes, résultat observé, preuve et statut, est en fin de document : « Lot 25 — recette finale ». Le tableau ci-dessous garde l'historique lot par lot.

| ID | Situation et résultat attendu | Lot responsable | Preuve | Local | Recette | Production |
|---|---|---|---|---|---|---|
| R01 | Inscription puis vérification du contact Compte utilisable selon la méthode validée | 04 | I01–I03 ; IdentityTest ; parcours HTTP réel | réussi en tests automatisés ; réception réelle d'un courriel non vérifiée (aucun service d'envoi configuré) | non exécuté | non exécuté |
| R02 | Mot de passe incorrect répété et récupération Limitation appliquée sans révélation de comptes | 04 | I04–I07 ; IdentityTest | réussi en tests automatisés | non exécuté | non exécuté |
| R03 | Invitation avec compte déjà existant Rattachement sans seconde identité | 05 | K01 ; InvitationContextTest | réussi en tests automatisés | non exécuté | non exécuté |
| R04 | Code d’invitation expiré ou révoqué Refus sans ajout d’appartenance | 05 | K02 ; InvitationContextTest | réussi en tests automatisés | non exécuté | non exécuté |
| R05 | Matricule identique dans deux établissements Aucun mélange de personnes ni d’accès | 05 ; socle 03 | K03 ; InvitationContextTest ; M02 | réussi en tests automatisés | non exécuté | non exécuté |
| R06 | Même compte dans deux établissements Changement de contexte explicite et données séparées | 05 ; socle 03 | K04–K05 ; InvitationContextTest | réussi en tests automatisés ; données d'étude par contexte à retester aux lots 07–12 | non exécuté | non exécuté |
| R07 | Enseignant tente de devenir administrateur Attribution refusée et événement sensible tracé | 05 | K06 ; InvitationContextTest | réussi en tests automatisés | non exécuté | non exécuté |
| R08 | Retrait d’une appartenance Accès retiré, espace personnel conservé | 05 ; socle 03 | K07–K08 ; InvitationContextTest ; M09 | réussi en tests automatisés | non exécuté | non exécuté |
| R09 | Consultation d’un objet du second établissement par URL Refus côté serveur et absence de fuite | 05, 23 ; socle 03 | K09–K10 ; InvitationContextTest ; HardeningTest (test_r09, test_r58) | réussi en tests, retesté au lot 23 : rôles non élevables, contexte d'un autre introuvable, numéro d'établissement envoyé par le client sans effet, données privées inaccessibles par adresse directe | non exécuté | non exécuté |
| R10 | Connexion d’un compte privilégié Second facteur exigé et récupération contrôlée | 04, puis décision du 23/11/2026 | SecondFactorTest (7 tests) ; IdentityTest (test_r10) ; preuves/lot25-second-facteur-edge.txt | réussi en tests et dans Edge sans dérogation : code temporaire exigé après le mot de passe, rejeu refusé, codes de secours à usage unique, retrait tracé par l’exploitation ; application réelle sur un téléphone non essayée | non exécuté | non exécuté |
| R11 | Création manuelle d’un cours récurrent Occurrences exactes sur la validité choisie | 07 | S07-01 à S07-04 ; ScheduleTest ; navigateur | réussi localement sur récurrence bornée et fuseaux | non exécuté | non exécuté |
| R12 | Import avec heure de fin antérieure au début Erreur localisée, aucune publication | 09 | X09-02 ; ScheduleImportTest | réussi en tests automatisés | non exécuté | non exécuté |
| R13 | Import avec groupe inconnu et lignes valides Lot refusé tant que l’erreur bloque | 09 | X09-03 ; ScheduleImportTest | réussi en tests automatisés | non exécuté | non exécuté |
| R14 | Même fichier soumis deux fois Aucun cours en double | 09 | X09-05 ; ScheduleImportTest | réussi en tests automatisés | non exécuté | non exécuté |
| R15 | Chevauchement groupe, salle ou enseignant Conflit visible et circuit de confirmation | 07, 09 | S07 (lot 07) ; X09-06 ; ScheduleImportTest | réussi en tests automatisés, saisie manuelle et import | non exécuté | non exécuté |
| R16 | Publication puis retour à une version antérieure Planning cohérent et rappels futurs recalculés | 08, 13 | P08-01 à P08-03, P08-06 ; X09-07 ; SchedulePublicationTest, ScheduleImportTest | partiel : publication, retour arrière et événements réussis en tests automatisés ; recalcul des rappels au lot 13 | non exécuté | non exécuté |
| R17 | Report d’une seule occurrence Série inchangée et identifiant du cours conservé | 07 | S07-05 ; ScheduleTest ; navigateur | réussi localement ; liens aux futures tâches/retours à retester | non exécuté | non exécuté |
| R18 | Annulation d’un cours avec rappel prévu Aucune nouvelle alerte post-cours inutile | 07, 13 | S07-06 ; L10-06 ; NotificationTest (test_r18_r22) | réussi en tests : cours annulé avant sa fin, aucun rappel créé ; annulé après programmation, rappel annulé au recontrôle | non exécuté | non exécuté |
| R19 | Élève tente de modifier un horaire officiel Refus ; ajout personnel possible séparément | 07, 08 | S07-11 ; ScheduleTest | partiel : écriture institutionnelle refusée, personnel possible ; publication à tester au lot 08 | non exécuté | non exécuté |
| R20 | Enseignant signale son indisponibilité Signalement en attente avant publication admin | 08 | P08-04 ; SchedulePublicationTest | réussi en tests automatisés | non exécuté | non exécuté |
| R21 | Fin d’un cours et délai de 5 minutes Un rappel logique est créé selon préférences | 10, 13 | L10-01 ; NotificationTest (test_r21) | réussi en tests : rappel programmé à fin + 5 minutes, ou au délai choisi, remis dans le centre par le cycle de la file | non exécuté | non exécuté |
| R22 | Retour déjà enregistré avant le rappel Rappel futur supprimé ou non envoyé | 10, 13 | L10-05 ; NotificationTest (test_r18_r22) | réussi en tests : rappel programmé annulé par l'événement « retour enregistré » ; rappel déjà remis retiré du centre | non exécuté | non exécuté |
| R23 | Retour hors connexion puis envoi répété Un seul retour final et état de synchronisation clair | 20 | SyncTest (test_r23) ; tests/js/offline-core.test.mjs ; preuves/lot20-edge.txt | réussi en tests : un retour par personne et par cours après quatre envois, accusé rejoué, second appareil en conflit visible ; brouillon écrit hors connexion dans Edge ; envoi réel d'un compte connecté dans un navigateur non exercé | non exécuté | non exécuté |
| R24 | Devoir collectif terminé par un apprenant État des autres apprenants inchangé | 11 | TaskTest (test_r24) ; T11-04 | réussi en tests : deux apprenants du même groupe, états distincts, devoir inchangé | non exécuté | non exécuté |
| R25 | Tâche terminée après programmation du rappel Rappel annulé au contrôle d’envoi | 11, 13 | TaskTest (test_r25) ; NotificationTest (test_r25, N13-03) | réussi en tests : rappel annulé à la remise et, pour un envoi différé, juste avant l'envoi externe | non exécuté | non exécuté |
| R26 | Notes 14 sur 20 et 36 sur 60 avec coefficients 1 et 2 Moyenne matière affichée 12,67 | 12 | GradeTest (test_r26_r27, deux tests) ; G12-02 | réussi en tests : 12,67 par le calculateur, par les notes personnelles et par les résultats publiés | non exécuté | non exécuté |
| R27 | Moyennes et coefficients de l’exemple du chapitre 9 Moyenne générale affichée 13,60 | 12 | GradeTest (test_r26_r27, deux tests) ; G12-02 | réussi en tests : 13,60 sur valeurs non arrondies (fraction exacte 68/5) | non exécuté | non exécuté |
| R28 | Note manquante, dispense et barème nul Statuts distincts ; barème nul refusé | 12 | GradeTest (test_r28) ; G12-02 ; test_r30 | réussi en tests : cinq états sans valeur distincts de zéro, zéro réel compté, barème nul refusé par la validation et par MySQL | non exécuté | non exécuté |
| R29 | Apprenant tente de remplacer une note publiée Refus et provenance visible | 12 | GradeTest (test_r29) | réussi en tests : 403 ou 404 sur toutes les écritures, résultat inchangé, provenance portée par chaque note, sources jamais fusionnées | non exécuté | non exécuté |
| R30 | Note d’une période ajoutée dans une autre année Validation de rattachement refusée | 12 | GradeTest (test_r30) | réussi en tests : période d'une autre année ou d'un autre établissement refusée par la validation et par MySQL (1452) | non exécuté | non exécuté |
| R31 | Publication sans source ou sans organisateur Publication bloquée | 15 | OpportunityTest (test_r31) | réussi en tests : source ou organisateur absent refusé dès la saisie ; sans conditions ou sans acte de vérification, publication refusée, y compris par MySQL | non exécuté | non exécuté |
| R32 | Recherche niveau, pays, domaine et modalité Résultats conformes et filtres visibles | 16 | OpportunitySearchTest (test_r32, test_search_parameters) | réussi en tests : filtres seuls et combinés, recherche, trois tris, filtres renvoyés pour affichage et réinitialisation, pagination, paramètres invalides refusés | non exécuté | non exécuté |
| R33 | Profil incomplet face à une condition d’éligibilité Mention à vérifier, aucune certitude inventée | 16 | OpportunitySearchTest (test_r33) | réussi en tests : information manquante = « à vérifier », jamais « non satisfait » ; seul un critère déclaré et contraire donne « non satisfait » | non exécuté | non exécuté |
| R34 | Offre avec date sans heure ou clôture continue Précision d’origine conservée | 15, 16 | OpportunityTest (test_r34) ; OpportunitySearchTest (test_r34) | réussi en tests : précision conservée à la saisie, sur la fiche, dans la liste et dans les filtres de clôture ; fiches sans date jamais closes | non exécuté | non exécuté |
| R35 | Clôture avec fuseau différent d’Abidjan Conversion cohérente et archivage au bon instant | 15 | OpportunityTest (test_r35) | réussi en tests : Paris hiver et été, New York, Abidjan ; archivage à l'instant exact de chaque fuseau | non exécuté | non exécuté |
| R36 | Nouvelle édition d’un programme déjà publié Nouvelle campagne sans perte d’historique | 15 | OpportunityTest (test_r36) | réussi en tests : fiche distincte, historique de l'ancienne intact, doublons refusés | non exécuté | non exécuté |
| R37 | Prolongation de clôture d’un favori Date et rappels mis à jour sans doublon | 15, 18 | OpportunityTest (test_r37) ; OpportunityFollowUpTest (test_r37_r38) ; OpportunityAlertTest (test_r37) | réussi en tests : rappels de l'ancienne date annulés, suiveurs prévenus une fois, rappels recalculés sur la nouvelle date, sans doublon | non exécuté | non exécuté |
| R38 | Offre signalée puis suspendue Retrait du catalogue ouvert et information utile | 15, 18 | OpportunityTest (test_r38) ; OpportunityFollowUpTest (test_r37_r38) ; OpportunityAlertTest (test_r38) | réussi en tests : retrait immédiat, suiveurs prévenus une fois, renvoi au suivi personnel, jamais présentée comme offre ouverte ni annoncée dans une synthèse | non exécuté | non exécuté |
| R39 | Même favori créé depuis deux appareils Un seul favori | 17 | OpportunityFollowUpTest (test_r39) | réussi en tests : trois ajouts, un favori ; doublon refusé par MySQL ; retrait répété sans erreur | non exécuté | non exécuté |
| R40 | Clic sur un lien de candidature Ouverture externe sans statut envoyée automatique | 16, 17 | OpportunitySearchTest (test_r40) ; OpportunityFollowUpTest (test_r40) | réussi en tests : avec une démarche en cours, consultations répétées et adresses de clic inventées ne changent ni l'état, ni la version, ni les dates ; l'état ne change que par le formulaire | non exécuté | non exécuté |
| R41 | Autre utilisateur demande le suivi d’une candidature Refus et aucune exposition privée | 17 | OpportunityFollowUpTest (test_r41) | réussi en tests : sept profils, huit routes, lecture et écriture ; rien d'exposé, rien de modifié | non exécuté | non exécuté |
| R42 | Désabonnement à une recherche enregistrée Arrêt des alertes et favoris conservés | 18 | OpportunityAlertTest (test_r42) | réussi en tests : synthèse en attente retirée, plus aucune annonce, favoris intacts, reprise sans rattrapage | non exécuté | non exécuté |
| R43 | Worker rejoue un même envoi après interruption Pas de seconde notification métier ; état contrôlé | 13, 18 | NotificationTest (test_r43) ; OpportunityAlertTest (test_r43, A18-01) | réussi en tests : cycle rejoué sans seconde synthèse ni seconde annonce ; doublons refusés par MySQL ; envois externes simulés seulement | non exécuté | non exécuté |
| R44 | Notification refusée sur le téléphone Application utilisable et centre disponible | 13, 19 | NotificationTest (test_r44) ; tests/js/sw.test.mjs ; preuves/lot19-edge.txt | partiel : centre disponible sans push ; refus d'installation sans effet ; réseau coupé donnant une page neutre, réussis en tests et dans Edge 157 sur ordinateur ; aucun téléphone réel | non exécuté | non exécuté |
| R45 | Deux appareils modifient une tâche hors connexion Conflit visible et aucune perte silencieuse | 20 | SyncTest (test_r45) ; tests/js/offline-core.test.mjs | réussi en tests : version dépassée refusée, rien d'écrasé, différences rendues, brouillon gardé, décision explicite ; deux appareils réels non exercés | non exécuté | non exécuté |
| R46 | Déconnexion avec brouillon local en attente Avertissement puis purge privée après décision | 20 | tests/js/offline-core.test.mjs ; preuves/lot20-edge.txt | partiel : liste des brouillons non synchronisés testée sous Node ; avertissement, refus sans perte et effacement confirmé constatés dans Edge sur la page hors ligne ; l'avertissement de l'écran Profil à la déconnexion n'a pas été exercé dans un navigateur | non exécuté | non exécuté |
| R47 | Deux comptes utilisent successivement le même appareil Aucun cache privé partagé | 19, 20 | PwaTest (test_r47) ; SyncTest (test_r48, compte différent) ; tests/js/offline-policy.test.mjs ; tests/js/offline-core.test.mjs ; tests/js/sw.test.mjs ; preuves/lot19-edge.txt | réussi en tests : aucune page ni donnée dans le cache partagé, réponses « no-store », copie du premier compte effacée au changement de compte ; brouillon d'un autre compte jamais listé ni envoyé par l'appareil, et refusé par le serveur sans rien enregistrer ; deux connexions réelles successives dans un navigateur non exercées | non exécuté | non exécuté |
| R48 | Droit retiré avant synchronisation d’un brouillon Opération refusée ou brouillon récupérable hors partage | 20 | SyncTest (deux tests test_r48) ; tests/js/offline-core.test.mjs | réussi en tests : appartenance retirée avant l'envoi, opération refusée sans aucune donnée du cours, brouillon conservé sur l'appareil, nouvelle tentative seulement sur demande ; retour déjà existant toujours modifiable par son seul propriétaire | non exécuté | non exécuté |
| R49 | Fichier non autorisé, trop gros ou infecté Dépôt refusé ou mis en quarantaine | 14 | AttachmentTest (deux tests test_r49) ; N14-01 | réussi en tests : dix-huit fichiers non autorisés refusés avant stockage ; fichier en quarantaine non téléchargeable ; détection simulée : refusé et retiré ; antivirus réel non vérifié | non exécuté | non exécuté |
| R50 | Lien privé transmis à un tiers sans droit Téléchargement refusé ou lien expiré | 14 | AttachmentTest (test_r50) ; N14-02 | réussi en tests : lien d'un fichier personnel ou de groupe introuvable pour toute personne sans droit, y compris après retrait d'appartenance | non exécuté | non exécuté |
| R51 | Export puis demande d’effacement Accès vérifié, traitement et preuve documentés | 22 | PrivacyTest (deux tests test_r51, demandes, notice) | réussi en tests : export après nouvelle saisie du mot de passe, limité au demandeur, lien privé expirant à 7 jours ; suppression après mot de passe et mot de confirmation, espace individuel effacé, accès révoqués, preuve sans contenu ; écrans non exercés en navigateur | non exécuté | non exécuté |
| R52 | Restauration d’une sauvegarde complète Application utilisable et révocations réappliquées | 22, 24 | PrivacyTest (test_r52_preparation) | préparé au lot 22 : après une restauration simulée en base, le journal réapplique effacements et retraits, ferme les sessions, ignore une ligne falsifiée ; sauvegarde et restauration réelles au lot 24 | non exécuté | non exécuté |
| R53 | Reprise d’une file après une panne prolongée Rappels périmés expirés et alertes utiles conservées | 13, 24 | NotificationTest (test_r53) | réussi en tests : après trois jours d'arrêt simulés, rappels périmés expirés, trois rappels encore utiles remis, aucun courriel tardif ; panne réelle d'un serveur au lot 24 | non exécuté | non exécuté |
| R54 | Charge de référence et import de 1 000 lignes Résultats mesurés contre les cibles du chapitre 21 | 23 | docs/audit-v1.md § 4 ; preuves/lot23-charge-essai-court.txt ; preuves/lot23-audit-edge.txt ; preuves/lot09-mesure-1000-lignes.txt | partiel : import mesuré à 138,0 s sur le poste de développement (cible de 120 s non tenue) ; essai de charge court sans erreur (5 utilisateurs, 60 s) ; premier chargement en 1,3 à 1,5 s avec compression ; charge de référence (100 utilisateurs, 30 minutes, 5 000 comptes) non mesurée | non exécuté | non exécuté |
| R55 | Navigation clavier, zoom et écran mobile : actions principales accessibles et texte lisible | 02, 19, 21, 23 | C04–C08 ; preuves/lot02-navigateur.txt ; preuves/lot19-edge.txt ; preuves/lot20-edge.txt ; preuves/lot21-parcours-edge.txt ; preuves/lot23-audit-edge.txt | partiel : au lot 23, 18 écrans à 1 280, 360 et 320 px sans défilement horizontal, libellés, contrastes mesurés, cibles de 44 px, clavier avec focus toujours visible, animations réduites, dans Edge 158 ; zoom natif, téléphones, Safari, Firefox et lecteur d'écran non testés ; aucune conformité WCAG revendiquée | non exécuté | non exécuté |
| R56 | Mise à jour PWA avec opérations locales en attente Migration réussie sans suppression silencieuse | 20 | tests/js/offline-core.test.mjs ; preuves/lot20-edge.txt | partiel : dans Edge, base locale du lot 19 (version 1) passée en version 2 avec sa copie conservée ; sous Node, repli et brouillon d'une version future conservé, service worker sans accès au stockage privé ; remplacement réel d'un service worker par un autre avec des envois en attente non exercé | non exécuté | non exécuté |
| R57 | Échec réseau lors d’un formulaire puis reprise Saisie conservée et création unique | 11, 20, 21 | TaskTest (test_r57) ; SyncTest (test_r57) ; tests/js/offline-core.test.mjs ; preuves/lot21-parcours-edge.txt | partiel : création unique après réseau coupé, réponse perdue puis reprise, réussie en tests ; dans Edge : trois envois du même formulaire donnent une seule tâche, avertissement avant d'abandonner une saisie, saisie conservée après un refus ; coupure de réseau pendant un envoi dans l'application non exercée en navigateur | non exécuté | non exécuté |
| R58 | Accès administrateur à un retour privé : refus systématique en V1, sans exception de support (décision récente primant sur le PDF). | 05, 10, 12, 23 | K12 ; L10-08 ; CourseCheckInTest ; GradeTest (test_r58) ; OpportunityFollowUpTest ; HardeningTest (test_r58) | réussi en tests, retesté au lot 23 pour toutes les données privées (tâche, note, retour, pièce jointe, export, demande) et sept acteurs dont l'administration de la plateforme : lecture, écriture, synchronisation et écrans d'administration | non exécuté | non exécuté |

## Exigences complémentaires
La matrice source chapitre 28 p.37 relie les familles aux R ; l'index specification-v1.md lie familles et lots. R01–R58 ne sont pas exhaustifs.
Ajouter les cas ciblés de chaque lot : IAM02 sans email/récupération sûre ; IAM05 préférences ; CHK06 agrégats ; TSK08 restauration ; GRD05 annuel ; SEC01–12 ; PRV01–06 ; OPS01–09. Tous non exécutés. Ne pas réattribuer les identifiants R.

## D00 — contrôle documentaire
Attendu : cinq fichiers autonomes, référence complète identifiée, 26 lots avec statut/dépendances/recette, R01–R58 uniques et aucun succès anticipé, exclusions absentes du backlog fonctionnel, consignes communes sans écrasement.
Résultat : contrôle structurel après rédaction consigné dans etat-v1.md ; réception du porteur non réalisée. Aucun scénario métier validé.

## Preuves historiques du lot 00 — 15 novembre 2026
- git status --short : échec « not a git repository ».
- composer show --direct : succès ; versions dans etat-v1.md.
- node --version / npm --version : 24.12.0 / 11.6.2.
- npm ls --depth=0 : code 0 ; multiplex optionnel absent et packages wasm extraneous.
- PHP 8.4 artisan test --compact : 2 tests réussis, 2 assertions, 530 ms. Un assertTrue et un GET / ; aucun R couvert.
- PHP 8.4 artisan route:list --except-vendor : GET/HEAD / seulement ; /up est la santé framework.
- PHP 8.4 artisan list --raw : dev, queue:work, schedule:run et migrations disponibles ; aucun job métier exécuté.
- npm run build : succès, 3 modules, 234 ms, avertissement fontaine optionnel. TLS Bunny précédent non reproduit ; résolution durable sur tous réseaux non démontrée.
Préparation antérieure : Boost 2.10.4 installé, instructions générées et 2 tests réussis, conservés.
Aucun test MySQL/navigateur/antivirus/SMTP/Push/charge/restauration ; aucun serveur manuel ou migration exécuté.

## Parcours manuel proposé — non exécuté
1. Lire specification-v1.md et architecture-v1.md sans chat : retrouver produit, exclusions et propriété des données.
2. Dans état, retrouver lot 09, dépendance 08 et R12/R13/R14/R54 ; tous non commencés/non exécutés.
3. Dans arbitrages, retrouver budget, mineurs, conservation, calendrier et refus de support privé.
4. Facultatif : avec PHP 8.4, artisan serve puis http://127.0.0.1:8000 ; attendu welcome Laravel, aucun parcours Campus. Aucune donnée personnelle nécessaire.

## Réception
Chaque phase Fondation puis Opportunités exige ses P0, scénarios réussis, anomalies bloquantes corrigées, preuve liée à une version et décision de réception. Défaut de droits, fuite, perte, calcul erroné ou absence de sauvegarde restaurable bloque l'ouverture. Démonstration fictive si conditions non remplies.


## Lot 01 — vérifications du socle, distinctes de R01–R58
Les résultats ci-dessous concernent le poste local, pas une réception de phase ou un runner GitHub.
| Cas | Exigence / attendu | Preuve exécutée | Résultat |
|---|---|---|---|
| B01 | Installation verrouillée reproductible | composer install, composer validate --strict et npm ci final | réussi ; lock npm optionnel réparé |
| B02 | Accueil français Inertia et assets compilés | test home + HTTP réel / et CSS/JS ; /up | 200 ; locale fr et données de page correctes |
| B03 | Dates cohérentes | test UTC, fuseau IANA Africa/Abidjan et traduction validation | réussi |
| B04 | Queue database réellement traitée | job RuntimeHeartbeat dispatché, job présent puis queue:work --once et cache écrit | réussi sur MySQL test |
| B05 | Scheduler réellement exécuté | schedule:run crée sonde et job, consommé par worker | réussi sur MySQL test |
| B06 | Stockage privé non desservi publiquement | fichier fictif écrit sur disque local puis GET /storage/foundation-probe.txt | 404 ; fichier nettoyé |
| B07 | Masquage logs | secret/jeton fictifs imbriqués et exception transformés par processeur | réussi ; pas preuve de détection universelle de texte privé |
| B08 | CI et qualité | YAML parsé ; typage, ESLint, Pint, build et audits locaux | réussi localement ; CI distante non exécutée |

### Commandes et résultats exacts
- Migrations non destructrices sur dev et test : trois migrations chacune ; migrate:status : Ran pour users/cache/jobs.
- artisan test --compact sous PHP 8.4 : 8 tests réussis, 37 assertions, 2 873 ms, connexion MySQL dédiée (pas SQLite).
- npm ci final avec npm 11.6.2 : 199 packages installés, 206 audités, aucune vulnérabilité signalée.
- npm run typecheck / npm run lint : codes 0.
- Pint --dirty : échec attendu sans dépôt Git ; Pint complet puis pint --test : succès.
- Build final : 582 modules, 343 ms ; CSS 38,46 Ko, JS 360,78 Ko avant gzip ; ce ne sont pas des mesures de performance sur téléphone.
- composer validate --strict : valide ; composer audit / npm audit : aucune vulnérabilité signalée.
- schedule:list : campus-runtime-heartbeat chaque minute ; YAML CI valide, service MySQL présent.
- HTTP local : page, assets et santé 200. Le premier contrôle textuel HTTP a échoué parce que le slash du fuseau était échappé en JSON ; contrôle corrigé, aucune règle contournée.
Échecs corrigés : MyISAM/index de migration ; route privée automatique désactivée (premier refus 403 devenu 404 sans desserte) ; encodage UTF-8 du test ; version ESLint EOL ; résolution minimatch et lockfile npm optionnel. Tables partielles vides conservées, aucun autre projet affecté.

### Contrôle manuel proposé avec données fictives — non exécuté en navigateur
1. Sélectionner PHP 8.4 puis composer run dev.
2. Ouvrir http://127.0.0.1:8000 : accueil Campus en français, aucun écran d'inscription, date et Africa/Abidjan affichés.
3. Réduire à 360 px, zoomer à 200 %, naviguer au clavier : texte lisible, pas de débordement, lien Aller au contenu et focus visibles. Résultat à confirmer ; ne valide pas R55 à ce stade.
4. Couper le réseau sur l'onglet déjà chargé : message hors connexion ; aucun engagement de cache PWA ou de synchronisation. Revenir en ligne : message de préparation.
5. Dans la base fictive hako_ra_campus_test uniquement, exécuter les tests selon README ; aucun compte réel requis.
6. schedule:run puis queue:work database --stop-when-empty : sonde technique consommée ; ne pas l'assimiler à un rappel utilisateur.

### Configurations non vérifiées
Runner GitHub, rendu navigateur (tentative Edge sans DOM exploitable), clavier/mobile/zoom, Windows service ou superviseur de production, SMTP, Web Push, antivirus, sauvegarde et restauration. Aucun R métier reçu ou coché par anticipation.


## Lot 02 — navigation et composants communs
Le Prompt 02 reçu concerne l'interface et remplace l'affectation provisoire à l'identité. R01/R02/R10 restent non exécutés et leur lot proposé devient 03. Aucun succès métier anticipé.
| Cas | Exigence / résultat attendu | Preuve locale | Résultat |
|---|---|---|---|
| C01 | Cinq destinations mobiles, Notes, contexte explicite | NavigationTest et navigateur ; 5 liens mobiles, Notes depuis Accueil/études | réussi pour le shell visiteur |
| C02 | Routes provisoires et exemples réservés au développement | NavigationTest sous environnement production : 6 routes 404 ; href métier null | réussi |
| C03 | Administration seulement si permission ET route | Test négatif route absente, permission refusée ; positif avec utilisateur fictif en transaction | réussi ; ne teste pas une policy métier future |
| C04 | Labels, erreurs et saisie conservée | Navigateur : champ invalide ciblé, aria-invalid, résumé ; valeurs inchangées après erreur/hors réseau | réussi dans l'atelier local |
| C05 | Dialogue accessible | Navigateur : focus Annuler, cycle Tab/Maj+Tab, Échap, retour déclencheur, confirmation/succès | réussi après correction |
| C06 | Liste/table, filtres et pagination | Navigateur : recherche sans résultat, reset, provenance et passage page 2 | réussi ; données fictives de développement |
| C07 | États textuels, succès et mouvement réduit | Navigateur : erreur illustrative, offline, succès fermable et animation désactivée | réussi ; aucune livraison distante simulée |
| C08 | 360 px, ordinateur, focus et repli 200 % | 21 pages, 28 Tab mobiles, lien d'évitement, zoom CSS et viewport équivalent 200 % | réussi pour ces modes ; zoom menu natif et Safari non testés |
| C09 | Non-régression et qualité | 13 tests MySQL/188 assertions ; TypeScript, ESLint, Pint, build 591 modules | réussi localement |

### Preuves consultables
- [Rapport navigateur](preuves/lot02-navigateur.txt) : Edge 157.0.4341.60, script temporaire externe au dépôt.
- [Capture mobile](preuves/lot02-mobile.png) et [capture ordinateur](preuves/lot02-desktop.png), relues.
- Backend : php artisan test --compact : 13 tests, 188 assertions, 2 197 ms.
- Frontend : npm run typecheck et npm run lint : codes 0 ; npm run build final : 591 modules, 362 ms.
- Pint --format agent puis pint --test : succès ; --dirty ne peut fonctionner sans Git.
Le zoom natif par raccourci a été essayé sans effet (viewport 1440 inchangé), donc non déclaré validé. La simulation 200 % porte sur zoom CSS et repli équivalent, pas sur une validation universelle des navigateurs.

### Parcours manuel reproductible — données fictives seulement
1. Lancer composer run dev selon README, ouvrir / sur téléphone ou fenêtre 360 px. Attendu : contexte visible, navigation à cinq entrées, Accueil actif, pas de défilement horizontal ; Notes accessible dans Espace d'études.
2. Parcourir Planning/Tâches/Opportunités/Profil/Notes : en développement, chaque écran indique qu'il reste à construire, sans donnée personnelle ; retour Accueil possible.
3. Ouvrir /developpement/composants : soumettre vide, attendre erreur reliée au champ ; saisir « Exemple fictif », conserver une précision, ouvrir la confirmation.
4. Vérifier Annuler au focus, Tab/Maj+Tab dans le dialogue, Échap puis retour sur Vérifier ; confirmer et fermer le message de succès.
5. Rechercher un texte absent, réinitialiser, filtrer par Personnel, passer page 2. Attendu : liste/table adaptée, vide expliqué, page visible ; données purement fictives.
6. Passer hors réseau sur l'onglet chargé : message explicite et saisie inchangée ; aucun enregistrement/sync garanti.
7. Sur ordinateur, tester clavier, zoom natif du menu à 200 % et mouvement réduit. Attendu : libellés lisibles, focus visible/non masqué et aucun débordement ; zoom natif à confirmer manuellement.
8. Sur un environnement de recette isolé configuré production, vérifier routes de développement en 404 et absence de liens d'administration sans permission. Les tests automatisés réalisent déjà ces contrôles serveur sans toucher à une production réelle.

### Limites et couverture
R55 partiel uniquement sur le shell/composants existants ; R57 non exécuté car l'atelier ne simule pas une sauvegarde serveur. Contexte institutionnel réel et choix de contexte dépendront des appartenances et autorisations futures. Aucun écran de connexion, aucune statistique ou candidature réelle.


## Lot 03 — contraintes MySQL et frontières
Migrations test/dev additives, aucun scénario utilisateur reçu automatiquement. Les cas R05/R06/R08/R09 ont une preuve partielle modèle/service ; leurs parcours d'identité/écrans/URL restent à compléter. R58 sur retour privé n'est pas déclaré validé par un test d'espace de préférences.
| Cas | Attendu et périmètre | Preuve exécutée | Résultat local |
|---|---|---|---|
| M01 | Fixtures répétables, identité unique et rôles portés par appartenances | Test fixtures, indépendant sans membre et polyvalent learner A/teacher B | réussi |
| M02 | Matricule local autorisé dans A et B, refus du doublon courant local | Inserts SQL et remplacement après clôture | réussi |
| M03 | Aucun double rôle courant, y compris suspendu et scope plateforme à identifiants nuls | Index uniques réellement sollicités en SQL | réussi |
| M04 | Scope/role compatibles et propriétaire privé non nul | CHECK et NOT NULL sollicités sans validation Eloquent | réussi |
| M05 | Groupe/membre ne peuvent référencer une année étrangère | FK composites MySQL | réussi |
| M06 | Inscription même utilisateur/rôle learner/groupe/année/institution, historique conservé | Inserts incohérents refusés ; clôture et recréation sans double courant | réussi |
| M07 | Affectation teacher/matière/groupe cohérente, clôture future datée | FK/CHECK et service d'accès aux affectations | réussi |
| M08 | Accès selon contexte courant, groupe assigné, dates, suspension et archivage | Enseignant A/G1 sans A/G2/B ; admin A sans B ; compte suspendu relu en base | réussi |
| M09 | Retrait A conserve privé et B ; aucun bypass plateforme/éditeur | Préférences privées inchangées, B accessible, accès étranger refusé | réussi |
| M10 | Références utilisées non supprimables et contexte non déplaçable | RESTRICT sur groupe/utilisateur ; archivage avec historique | réussi |
| M11 | Dates/poids et parents cohérents | CHECK des dates/poids ; FK parent de période ; validation d'inclusion et réduction parentale refusée | réussi |
| M12 | Seeder fermé en production et sur base étrangère avant connexion | Garde interne testé directement, aucune autre base réellement contactée | réussi |
| M13 | Factories génèrent des relations compatibles | Enrollment, TeachingAssignment, Membership plateforme et Period | réussi |

### Résultats et preuves
- php artisan migrate --env=testing --no-interaction puis migrate local : succès ; statut quatre migrations Ran sur chaque base.
- php artisan db:seed --class=CampusDemoSeeder --env=testing --no-interaction : succès après correction de génération.
- Seeder dev deux fois : succès, état stable ; 2 institutions, 8 espaces privés, 9 appartenances, 3 inscriptions et 2 affectations.
- php artisan test --compact --filter=CampusFoundationTest : 20 tests réussis, 77 assertions, 4 151 ms.
- php artisan test --compact : 33 tests réussis, 265 assertions, 5 535 ms.
- Pint complet puis pint --test : succès ; --dirty indisponible sans Git.
- [Schéma MySQL réel](preuves/lot03-schema-mysql.txt) ; [compte-rendu des commandes](preuves/lot03-verifications.txt).
Les tests utilisent des transactions sur la base dédiée hako_ra_campus_test, des données fictives et des tentatives SQL volontairement refusées. Aucun rollback de migration, suppression de table ou désactivation de contrainte n'a été utilisé pour faire passer un test.

### Parcours manuel reproductible — non exécuté comme parcours utilisateur
1. Dans le dépôt, sélectionner PHP 8.4 puis exécuter php artisan migrate et php artisan db:seed --class=CampusDemoSeeder sur la base locale Campus dédiée. Attendu : références A/B fictives créées ; le second seeding ne crée pas de doubles.
2. Lire en SQL les membres de apprenant-double@example.test : deux appartenances learner, même MAT-001 local, institutions distinctes. Lire polyvalent@example.test : learner A et teacher B sur le même compte.
3. Exécuter le test ciblé CampusFoundationTest. Attendu : contraintes croisées refusées, plateforme sans accès à un autre espace privé, A retiré sans effacement du privé et sans retirer B.
4. Les tentatives de retrait/recréation sont transactionnelles dans les tests : ne pas modifier à la main une base réelle pour réaliser cette recette.
5. Ouvrir l'accueil existant : il reste une visite sans connexion ; aucune fixture n'est affichée automatiquement comme un compte connecté.

### Ce qui reste non vérifié / non livré
Auth/session/MFA/invitations, provisionnement sans email et récupération sûre, contextes choisis en interface, permissions de mutation et audit complet des futurs endpoints, notes/retours/démarches et leurs politiques de conservation effectives. Les contrats de ces objets ne sont pas des intégrations opérationnelles.


## Lot 04 — comptes et sessions
Cas ciblés du lot, exécutés sur MySQL hako_ra_campus_test avec données fictives. R10 n'est pas validé : seul le refus par défaut des actions privilégiées est prouvé.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| I01 | Inscription email : espace privé créé, aucun rôle ni établissement accepté, vérification exigée | IdentityTest r01_registration ; champs role/institution_id/status envoyés et ignorés | réussi |
| I02 | Lien de vérification signé, expirant après 60 minutes, non falsifiable, propre au compte | Lien falsifié 403, lien expiré 403, lien d'un autre compte 403, lien valide ouvre le profil | réussi |
| I03 | Parcours mineurs fermé sans cadre validé, sans seuil d'âge codé | Déclaration exigée ; activation seule insuffisante sans référence du cadre | réussi |
| I04 | Limitation des tentatives sans révéler l'existence d'un compte | 5 échecs puis blocage, même message pour compte existant et inconnu ; reprise après 61 s | réussi |
| I05 | Compte suspendu : réponse générique et session ouverte fermée | Statut modifié en base, requête suivante déconnectée | réussi |
| I06 | Récupération non énumérante | Même réponse pour adresse connue, inconnue et fréquence dépassée ; un seul envoi | réussi |
| I07 | Lien de réinitialisation expirant, à usage unique, fermant toutes les sessions | Jeton expiré et inventé refusés ; ancienne session réellement déconnectée ; rejeu refusé | réussi |
| I08 | Sessions consultables et révocation effective | Trois appareils simulés par cookies distincts ; réauthentification exigée ; session d'un tiers 404 ; appareil révoqué redirigé vers la connexion | réussi |
| I09 | Déconnexion et renouvellement de l'identifiant de session à la connexion | Identifiant anonyme inutilisable après connexion ; session fermée après déconnexion | réussi |
| I10 | Actions privilégiées fermées tant que le second facteur n'existe pas | 403 par défaut ; 403 en environnement production même avec dérogation | réussi ; ne vaut pas MFA |
| I11 | Provisionnement individuel sans email (IAM02) | ProvisionedAccountTest : identifiant HK- distinct du matricule, rattachement apprenant au périmètre de l'administrateur, secret haché, trace sans secret | réussi |
| I12 | Réauthentification, attestation et matricule libre | Redirection vers la confirmation du mot de passe ; attestation et doublon refusés ; même matricule accepté dans A et B | réussi |
| I13 | Aucun autre rôle ni autre établissement ne provisionne | Admin A sur B 404 ; enseignant et apprenant 403 ; administrateur plateforme 404 ; visiteur redirigé | réussi |
| I14 | Le secret temporaire ne sert jamais de mot de passe ; activation à usage unique | Connexions avec secret ou matricule refusées ; activation puis rejeu refusé | réussi |
| I15 | Secret expirant et devinette limitée | 5 essais puis blocage ; expiration après 72 h | réussi |
| I16 | Récupération assistée avec contrôle d'identité et trace limitée | Contrôle et confirmation exigés ; admin B 404 ; compte avec email 404 ; ancien accès et sessions invalidés ; journal sans secret | réussi |
| I17 | Un compte a un email ou un identifiant unique | CHECK et index unique MySQL sollicités | réussi |

### Résultats exacts
- php artisan migrate --env=testing puis migrate (dev) : migration 2026_11_15_213000 exécutée, cinq migrations Ran sur chaque base. Aucun rollback.
- php artisan test --compact : 54 tests réussis, 614 assertions, 14 621 ms (33 tests hérités + 21 nouveaux).
- vendor/bin/pint --format agent : corrigé puis passé ; npm run typecheck et npm run lint : codes 0 ; npm run build : succès en 449 ms.
- Parcours HTTP réel sur serveur local temporaire (port 8011, base dev, sessions en base) : pages publiques 200 ; /profil visiteur redirigé vers /connexion ; POST sans jeton CSRF 419 ; inscription redirigée vers /verification-email ; profil refusé tant que l'adresse n'est pas vérifiée ; déconnexion puis connexion réussies. Cookie de session httponly et samesite=lax. Un compte fictif smoke-lot04-31960@example.test reste dans la base dev.
- Premier passage des tests : 7 échecs dus au harnais, qui réutilisait la session et le garde d'authentification en mémoire entre utilisateurs. Correction dans tests/TestCase.php (freshDevice) ; aucune règle applicative assouplie.

### Parcours manuel proposé — données fictives
1. composer run dev, ouvrir /inscription, créer un compte fictif en example.test. Attendu : écran de vérification indiquant que l'envoi de courriels n'est pas configuré.
2. Tenter /profil : attendu, retour à la vérification. Sans service d'envoi, le lien ne peut pas être reçu ; configurer un service local pour poursuivre.
3. Se déconnecter, saisir 5 mauvais mots de passe : attendu, message de blocage temporaire identique pour une adresse inconnue.
4. /mot-de-passe-oublie avec une adresse inconnue : attendu, même message que pour une adresse connue.
5. Avec CAMPUS_PRIVILEGED_WITHOUT_MFA=true en local et un administrateur fictif dont le mot de passe est connu, ouvrir Profil puis « Comptes sans adresse électronique », créer un compte, noter identifiant et secret, activer sur /activation.

### Non vérifié
Réception réelle d'un courriel (MAIL_MAILER=array) ; second facteur (non livré) ; rendu navigateur des nouveaux écrans (360 px, clavier, zoom) ; équivalence de durée de réponse entre adresse connue et inconnue lors de la récupération ; CI distante.


## Lot 05 — invitations et isolation des contextes
Cas exécutés sur MySQL hako_ra_campus_test, données fictives. Toutes les routes d'administration restent fermées hors dérogation locale, faute de second facteur (R10 bloqué).
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| K01 | Compte existant rattaché sans seconde identité ; rôle et périmètre pris dans l'invitation seulement (R03) | Champs role/institution_id/scope envoyés à l'acceptation et ignorés ; nombre de comptes inchangé ; secret stocké haché ; consommation unique | réussi |
| K02 | Invitation expirée, révoquée ou inconnue : aucune appartenance, réponse identique (R04) | Trois cas, même message, 404, aucune appartenance | réussi |
| K03 | Même matricule dans A et B sans mélange ; conflit dans un même établissement laissé en attente (R05) | Deux comptes distincts ; conflit de matricule et adresse différente en attente de contrôle ; approbation refusée tant que le conflit existe ; décision d'un autre établissement 404 | réussi |
| K04 | Contexte explicite, vérifié côté serveur (R06) | Bascule A puis B ; appartenance d'un tiers ou inexistante 404 ; valeur de session falsifiée ramenée à l'espace individuel | réussi |
| K05 | Pages d'administration et export limités au périmètre actif (R06, R09) | Paramètres institution_id et membership de B ignorés ; export sans donnée de B ni adresse électronique ; cellule de formule neutralisée | réussi |
| K06 | Escalade de rôle refusée et tracée (R07) | Enseignant, admin d'établissement et plateforme : 403, aucune invitation créée, 7 entrées privilege.escalation_denied | réussi |
| K07 | Retrait immédiat, compte et espace individuel conservés (R08) | Réauthentification exigée ; contexte retombé en individuel à la requête suivante ; B toujours utilisable ; administrateur retiré : page, export et mutation 403 | réussi |
| K08 | Départ volontaire limité à sa propre appartenance (R08) | Appartenance d'un tiers 404 ; affectations closes ; trace | réussi |
| K09 | Mutation depuis A visant B introuvable (R09) | Retrait, révocation et décision sur identifiants de B ou inexistants : 404 identiques ; groupe de B refusé ; invitation créée dans A malgré institution_id de B | réussi |
| K10 | Job revérifiant les droits à l'exécution (R09) | Job chiffré ; émetteur de A sur invitation de B, appartenance d'autrui, secret faux et émetteur retiré : aucun envoi, refus tracé | réussi |
| K11 | Seule la plateforme crée un établissement et habilite son administration | Création avec première année ; invitation privilégiée réauthentifiée et tracée ; l'administrateur plateforme n'accède pas aux pages d'établissement | réussi |
| K12 | Aucun rôle ne lit l'espace privé d'autrui, aucun passe-droit global (R58 partiel) | Policy PersonalSpace refusée à plateforme, admin, enseignant, éditeur ; aucun Gate::before ; aucune route d'impersonation ou de support | réussi ; partiel pour R58 |
| K13 | Administration fermée et masquée sans second facteur | 403 et navigation vide sans dérogation | réussi |
| K14 | Les données d'une page ne sont jamais écrites dans le cache de vues | ApplicationFoundationTest : marqueur absent du cache, nombre de fichiers stable | réussi après correction |

### Résultats exacts
- Migration 2026_11_15_230000_create_invitations_table sur test puis dev : succès, six migrations Ran sur chaque base. Aucun rollback.
- php artisan test --compact : 68 tests réussis, 1 010 assertions, 19 424 ms (54 hérités + 14 nouveaux). Suite rejouée quatre fois de suite sans échec après correction.
- Pint : deux fichiers reformatés puis passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 426 ms.
- HTTP réel sur serveur local temporaire (port 8012) : accueil 200 avec bloc de page ; invitation inconnue 404 ; routes d'administration redirigées vers la connexion pour un visiteur ; POST /contexte sans jeton CSRF 419.

### Anomalie corrigée pendant le lot (héritée du lot 01)
Constat : échec intermittent d'un test (1 passage sur 3). Cause établie par capture de l'exception : la vue racine utilisait les composants <x-inertia::head /> et <x-inertia::app />, pour lesquels le framework écrit un fichier par contenu de page dans storage/framework/views. 313 fichiers contenaient des données de page en clair, dont 9 un secret temporaire fictif ; Windows refusait par moments leur renommage.
Correction : directives @inertiaHead et @inertia dans resources/views/app.blade.php ; cache vidé par php artisan view:clear ; test de non-régression K14. Retest : quatre suites complètes sans échec, cache stable à 4 fichiers sans donnée de page.
Gravité : élevée si elle avait atteint un environnement réel (données privées et secrets écrits sur disque, croissance illimitée du cache).

### Parcours manuel proposé — données fictives
1. Mettre CAMPUS_PRIVILEGED_WITHOUT_MFA=true dans .env local, se connecter avec un administrateur d'établissement fictif dont le mot de passe est connu, choisir son contexte dans l'en-tête.
2. Ouvrir Administration puis Établissement, créer une invitation d'apprenant sans adresse : le lien s'affiche une seule fois.
3. Dans une fenêtre privée, ouvrir le lien : l'écran propose de se connecter avec un compte existant. Se connecter avec un compte individuel fictif, accepter : le profil montre la nouvelle appartenance et le contexte actif.
4. Revenir côté administration, retirer l'appartenance : au rechargement, le compte rattaché est revenu en espace individuel et son profil reste accessible.

### Non vérifié
Rendu navigateur des nouveaux écrans et du sélecteur de contexte (360 px, clavier, zoom) ; envoi réel du courriel d'invitation ; acceptation concurrente par deux requêtes simultanées (verrou et contrainte unique en place, test séquentiel seulement) ; R58 sur les retours de cours, notes et démarches, qui n'existent pas encore.


## Lot 06 — référentiels académiques et vérification 04/05
16 novembre 2026, PHP 8.4.15, MySQL local 8.4, données exclusivement fictives, bases Campus dev/test dédiées.

| Cas | Attendu | Preuve locale | Résultat |
|---|---|---|---|
| A06-01 | Admin A crée/modifie/recherche sans toucher B ; version périmée refusée | AcademicAdministrationTest admin_creates_updates_searches_and_cannot_touch_b | réussi |
| A06-02 | Enseignant seulement sur groupes affectés ; mutations refusées | teacher_sees_only_assigned_groups_and_cannot_modify | réussi |
| A06-03 | Matières privées sans institution, même pour un membre ; départ sans perte | personal_references_are_private_without_institution_and_survive_departure | réussi |
| A06-04 | Types contextualisés, parent explicite, aucun chevauchement de frères | period_overlap_parent_and_types_are_scoped | réussi |
| A06-05 | Périodes personnelles, parent étranger introuvable | personal_nested_periods_and_foreign_parents_are_protected | réussi |
| A06-06 | Doublon d'inscription refusé ; changement de groupe atomique et historisé | enrollment_duplicate_and_transfer_keep_history_atomically | réussi |
| A06-07 | Affectation au bon rôle/matière ; doublon refusé, clôture gardée | assignment_wrong_role_and_duplicate_refused_and_close_preserved | réussi |
| A06-08 | Reprise limitée aux références cochées et nouveau contexte d'administration | new_year_copies_selected_references_only_and_has_own_admin_context | réussi |
| A06-09 | Année archivée protégée sans effacement du privé | archive_year_revokes_context_and_blocks_further_changes | réussi |
| A06-10 | Administration fermée sans MFA ; fuseau IANA et version contrôlés | privileged_default_refusal_timezone_and_version | réussi ; ne vaut pas MFA |
| A06-11 | Contraintes MySQL de propriétaire/type/parent et factories cohérentes | mysql_personal_scope_constraints_and_factories | réussi |
| A06-12 | Reprise étrangère annulée entièrement ; archive conserve inscriptions | new_year_copy_failure_rolls_back_everything_and_archive_is_not_deletion | réussi |
| A06-13 | Pagination et sélection recherchable limitées à A | lists_paginate_and_selector_search_never_includes_b | réussi |
| A06-14 | Instants convertis au fuseau de l'établissement, sortie datée | instants_use_institution_timezone_and_withdrawal_dates_history | réussi |
| A06-15 | Erreurs JSON stables et françaises, aucune fuite inter-périmètre | json_errors_have_stable_codes_french_messages_and_no_foreign_resource | réussi |

Commandes et résultats : [preuve des vérifications](preuves/lot06-verifications.txt). Avant développement, 68 tests hérités passés, 1 012 assertions. Migration additive et migrate:status exécutés sur test/dev, sept migrations Ran sur chacune. Aucun down, migrate:fresh ou suppression de table.
Corrections pendant la recette : pagination frontend alignée sur le composant existant avec total réel ; avertissement de dépendance React supprimé ; test hérité de réinscription corrigé car il réutilisait la sortie de l'inscription clôturée. Les refus serveur n'ont pas été assouplis. Les premiers scripts navigateur supposaient à tort que le changement de contexte restait sur la page et que les messages d'état étaient des titres ; sélecteurs corrigés selon le comportement existant.

### Navigateur réellement exécuté
Edge 157.0.4341.60, serveur local temporaire 127.0.0.1:8016, dérogation privilégiée limitée au processus de développement. Connexion réelle, création d'une matière personnelle réelle, choix du contexte A et sept écrans à trois largeurs (360/768/1440) : 21 contrôles sans débordement horizontal. Dialogue annulé par Échap, recherche vide, erreur de validation avec saisie préservée, mode hors ligne avec saisie préservée, aucune erreur JavaScript. Zoom CSS 200 % vérifié ; cela ne valide pas le zoom natif du navigateur. [Rapport](preuves/lot06-navigateur.txt), [mobile](preuves/lot06-mobile.png), [ordinateur](preuves/lot06-desktop.png), images relues. Compte de recette fictif dédié, secret hors dépôt ; désactivé après contrôle.

### Parcours manuel reproductible avec données fictives
1. Lancer l'application locale ; connecter un compte fictif vérifié. Ouvrir /etudes/referentiels en espace individuel, créer « Mathématiques personnelles » et une période : attendu, aucune institution ni matricule requis. Un autre compte ne voit pas ces lignes.
2. Avec la dérogation locale existante et un administrateur A fictif, sélectionner le contexte A ; créer un groupe et un type, puis une sous-période dans S1. Attendu : création acceptée ; période sœur recouvrante refusée, saisie gardée.
3. Dans Inscriptions, rechercher l'apprenant, tenter une seconde inscription recouvrante : refus. Utiliser Changer de groupe et une date déjà effective : ancienne ligne clôturée, nouvelle ligne active, historique visible.
4. Dans Affectations, choisir un enseignant, un groupe A et une matière A. Attendu : acceptation ; identifiants de B ou rôle apprenant refusés côté serveur. Enseignant connecté : consultation limitée aux groupes affectés, aucun formulaire de modification.
5. Créer une nouvelle année, cocher un groupe et ses périodes avec leurs parents. Attendu : nouvelles références seulement ; sélectionner ensuite le nouveau contexte dans l'en-tête. Aucun élève, note ou tâche privée recopié.
6. Archiver une référence utilisée puis une année de recette après préparation d'un autre contexte. Attendu : historique conservé, édition refusée et ancien contexte retiré. Départ d'un apprenant : matières personnelles toujours présentes.
7. Tester 360 px, clavier et zoom natif 200 %. Attendu : labels et focus visibles, aucun défilement horizontal ; zoom natif/lecteur d'écran restent à confirmer.

Portée : R08/R09 étendus aux références et données personnelles existantes ; R55 partiel sur ces écrans. Ni R10, ni R58 sur les futures notes/retours/démarches ne sont déclarés validés.

### Résultat final du lot 06
`php artisan test --compact` : **83 tests réussis, 1 187 assertions, 28 330 ms**. Les 15 nouveaux tests portent sur le lot 06 et le contrat JSON ; les 68 tests hérités sont inclus. Typage, ESLint sans avertissement, Pint et build passent (605 modules, 458 ms). Diff vérifié sans erreur de blancs. Réauthentification exigée avant création d'année et habilitation de continuation auditée ; conflit de version Inertia rendu en erreur de formulaire, JSON conservé en 409.
Compte fictif de recette désactivé, appartenance retirée, sessions fermées, secret temporaire supprimé et serveur de recette arrêté. Références fictives conservées en dev pour traçabilité. Aucune réception distante ou production validée.


## Lot 07 — cours et occurrences
16 novembre 2026, données fictives, MySQL hako_ra_campus_test/dev, PHP 8.4.15. Aucune réception métier/production acquise.

| Cas | Test ou preuve | Résultat local |
|---|---|---|
| S07-01 | Génération hebdomadaire limitée, renouvellement et rejeu sans doublon | réussi |
| S07-02 | Samedi/dimanche, vacances, exclusions, jours non travaillés et semaines actives | réussi |
| S07-03 | UTC et Europe/Paris avant/après transitions de printemps et automne 2027 | réussi |
| S07-04 | Heures sautées/ambiguës refusées sans écriture partielle | réussi |
| S07-05 | Report conservant UUID/date d'origine/série/historique et autre occurrence inchangée | réussi |
| S07-06 | Annulation conservée après régénération, sans suppression | réussi ; rappels non couverts |
| S07-07 | Révision du futur avec exceptions préservées et version périmée refusée | réussi |
| S07-08 | Changement de jour réconcilié jusque dans les fenêtres déjà générées éloignées | réussi |
| S07-09 | Chevauchement motivé, doublon exact toujours interdit | réussi |
| S07-10 | Groupe/matière étrangers et enseignant non affecté refusés | réussi |
| S07-11 | Apprenant sans mutation institutionnelle, ajout personnel du week-end possible | réussi ; publication au lot 08 |
| S07-12 | Prévisualisation enseignant limitée à ses affectations, sans écriture | réussi |
| S07-13 | Personnel interdit aux autres rôles, A/B isolés, plateforme sans passe-droit | réussi |
| S07-14 | Retrait immédiat d'accès institutionnel sans perte des occurrences privées | réussi |
| S07-15 | Nonce de création et version contrôlés, garde MFA fermé par défaut | réussi ; ne vaut pas MFA |
| S07-16 | Validité excessive, mauvais protocole de lien, minuit et semaine invalide refusés | réussi |
| S07-17 | FK MySQL, suppression restreinte et révisions non réécrites | réussi |
| S07-18 | Vues jour/semaine/liste bornées, recherche et date validées | réussi |
| S07-19 | Conflits de groupe, enseignant et salle éprouvés séparément | réussi |
| S07-20 | Révision en conflit entièrement annulée, versions et occurrences intactes | réussi |
| S07-21 | Enseignant révoqué et année archivée empêchant la nouvelle génération | réussi |
| S07-22 | Conflit d'agenda personnel et matière privée étrangère refusés | réussi |
| S07-23 | Pagination de plus de 50 occurrences et plus de 20 révisions | réussi |
| S07-24 | Dernière révision du futur prioritaire sur une ancienne révision différée | réussi |
| S07-25 | Même occurrence visible de part et d’autre de minuit/semaine après conversion de fuseau | réussi ; backend et navigateur |

Commandes et résultats : [preuves du lot](preuves/lot07-verifications.txt). Suite finale : **108 tests réussis, 1 503 assertions, 40 218 ms** (83 hérités + 25 nouveaux). Typage, lint sans avertissement, Pint et build réussis, 607 modules, 478 ms. Trois migrations additives appliquées sur test puis dev ; dix migrations Ran sur chacune, aucun down/rollback/fresh.
Anomalies corrigées : AuditLog ne pouvait référencer un UUID, ajout de subject_uuid sans convertir les données existantes ; fixture de pagination qui mutait son début en calculant sa fin, corrigée avec copie de date ; priorité des révisions futures explicitée et couverte. Aucun contrôle assoupli.

### Navigateur exécuté
Edge 157.0.4341.60, serveur local 8017 et compte fictif temporaire distinct, vrai backend et vraie base dev. Création hebdomadaire/personnelle, événement du samedi, report gardant le lien d'historique, annulation, trois révisions, modification du futur ; institutionnel explicitement en brouillon et enseignant sans boutons de mutation. Jour/semaine/liste aux largeurs 360/768/1440 : neuf contrôles sans défilement horizontal. Saisie d'exclusions et vacances réellement vérifiée ; erreur de doublon conservant la saisie ; hors réseau conservant le formulaire ; zoom CSS 200 % sans débordement. Quarante focus clavier mobiles visibles et non masqués. Aucune erreur JavaScript.
[Rapport](preuves/lot07-navigateur.txt), [planning ordinateur](preuves/lot07-desktop.png), [mobile](preuves/lot07-mobile.png), [cours mobile](preuves/lot07-mobile-cours.png), captures relues. Zoom natif, lecteur d'écran et Safari/iOS restent non vérifiés. Le hors-réseau ne prouve aucune sauvegarde ou synchronisation PWA.

### Parcours manuel reproductible — uniquement données fictives
1. Avec un compte vérifié dans une recette isolée, ouvrir /planning en espace individuel. Créer une série « Révision fictive », lundi/mercredi, 09:00–10:00, validité de plusieurs mois. Attendu : au plus les 56 premières dates locales sont examinées ; vue semaine adaptée au mobile, source personnelle lisible.
2. Ajouter un événement ponctuel le samedi, puis une exclusion et une plage de vacances à une série. Attendu : dates exclues non créées, week-end personnel autorisé. Une fenêtre supplémentaire se génère depuis la liste des séries, limitée à 56 jours ; rejeu sans nouvelles occurrences.
3. Modifier une occurrence et noter le lien Historique avant/après. Attendu : même UUID, date d'origine gardée, nouvelle heure visible ; annuler puis régénérer la fenêtre : l'annulation reste conservée.
4. Modifier le futur avec un motif et une date d'effet : attendu, autres occurrences réconciliées jusqu'à l'horizon déjà généré, exceptions manuelles préservées. Version périmée refusée avec formulaire conservé.
5. Avec un administrateur A fictif et la dérogation locale existante, choisir matière/groupe/enseignant affecté. Attendu : brouillon institutionnel ; groupe de B ou enseignant non affecté refusé. Chevauchement exige confirmation motivée, doublon exact jamais accepté.
6. Avec l'enseignant affecté, consulter sans modifier ; avec l'apprenant, aucun brouillon consultable ni mutation institutionnelle, mais contexte individuel toujours utilisable. Ne pas prendre ce scénario pour une publication officielle : lot 08 requis.
7. Vérifier 360 px, clavier, zoom natif 200 % et autre navigateur. Attendu : focus/labels lisibles, aucune action reposant seulement sur la couleur ; ces contrôles complémentaires ne sont pas déclarés réussis d'avance.

### Portée et limites
R11/R15/R17 couverts sur la saisie manuelle existante ; R18 partiel sans rappels et R19 partiel sans publication. Les futures FK de tâches/retours doivent cibler l'UUID stable en RESTRICT, mais ces intégrations ne sont pas livrées. Concurrence simultanée/charge non éprouvées ; verrous, contraintes et rejeux séquentiels testés. Pas de CI distante ni production, MFA absent et courriels réels non testés.
Recette terminée : cours institutionnel fictif annulé via service métier, compte fictif désactivé, appartenances et sessions fermées, secret temporaire supprimé, serveur arrêté ; données et historique conservés.

Complément final fuseaux : cours du 16/11/2026 00:00–02:00 Europe/Paris affiché en 15/11 23:00–16/11 01:00 Africa/Abidjan. La même occurrence est présente dans les deux vues journalières et dans la semaine du 16, sans recréation. [Capture fuseaux](preuves/lot07-fuseau.png) relue ; dates complètes affichées quand la conversion traverse minuit. Second compte fictif de ce contrôle également désactivé, sessions fermées et secret supprimé.


## Lot 08 — publication et révision du planning institutionnel
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, date de test fixée au 16 novembre 2026. Publication et décisions restent fermées hors dérogation locale, faute de second facteur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| P08-01 | Prévisualisation puis publication transactionnelle d'une version immuable, vue par les seuls membres concernés (R16) | 8 cours ajoutés prévisualisés ; empreinte ou version périmée 409 sans écriture ; version 1 avec auteur, date, motif, résumé ; apprenants de A-G1 voient le cours, contexte B et espace individuel non ; modification et suppression d'une version refusées | réussi |
| P08-02 | Publication rejouée sans version ni événement en double | Même clé de demande : même version ; brouillon identique : refus « aucune différence » ; 9 événements inchangés | réussi |
| P08-03 | Comparaison avant/après, retour arrière en nouvelle version, identifiants et historique conservés (R16) | Annulation et report publiés en version 2 avec avant/après ; retour à la version 1 = version 3 ; mêmes UUID, date d'origine intacte, deux révisions d'historique ajoutées ; version inconnue ou courante 404 ; rejeu idempotent ; aucune différence résiduelle | réussi |
| P08-04 | Signalement d'un enseignant en attente jusqu'à publication de la décision (R20) | Apprenant 403, enseignant d'un autre établissement 404, doublon refusé ; planning inchangé après signalement puis après acceptation ; enseignant ne peut ni décider ni publier ; cours annulé pour les apprenants seulement après publication ; événement « cancelled » ; destinataires = inscrits effectifs et enseignant, apprenant retiré exclu | réussi |
| P08-05 | Droits serveur par rôle et par établissement | Apprenant, enseignant, plateforme, individuel : 403 sur prévisualisation, publication et retour ; admin B ne voit ni ne restaure les versions de A (404) ; tout fermé sans dérogation de second facteur | réussi |
| P08-06 | Concurrence de publication et unicité en base | Prévisualisation périmée après modification du brouillon : 409 ; seconde publication sur la même version attendue : 409 ; version, clé de demande et clé d'événement en double refusées par MySQL (1062) | réussi ; simultanéité réelle non éprouvée |
| P08-07 | Cours ajouté après la version restaurée retiré sans suppression, sans réapparition ; passé intact | 7 cours à venir retirés, ligne conservée et marquée exception ; cours déjà passé laissé publié, sans événement ; nouvelle matérialisation et nouvelle publication : aucune réapparition | réussi |

### Résultats exacts
- Migration 2026_11_16_100000_create_schedule_publication_tables : premier essai en échec sur la base de test (mot réservé `change` non protégé dans une contrainte CHECK), après création de deux tables. Correction de la migration, retrait de ces deux tables vérifiées vides sur la seule base de test, nouvelle exécution réussie ; exécution directe réussie sur dev. Onze migrations Ran sur chaque base.
- php artisan test --compact : 114 tests réussis, 1 840 assertions, 48 454 ms (108 hérités + 6 nouveaux).
- vendor/bin/pint --format agent : un fichier hérité reformaté (migration du lot 07, blancs uniquement) ; npm run typecheck et npm run lint : codes 0 ; npm run build : succès, 493 ms.
- Un test hérité du lot 07 adapté : l'enseignant voit le planning publié par défaut et le brouillon avec source=draft.

### Revue des lots 06 et 07 (lecture du code et rejeu des tests)
- Suite héritée rejouée avant toute modification : 108 tests réussis, 1 503 assertions.
- Aucun défaut d'isolation A/B, d'accès privé ou de droit de mutation relevé à la lecture de AcademicAdministration, ScheduleAccess, ScheduleManager, de leurs contrôleurs et migrations.
- Constat mineur ouvert : avec un contexte plateforme actif (administration plateforme ou éditeur), /planning et /etudes/referentiels répondent 403 ; la personne doit repasser en espace individuel pour voir son planning personnel.
- Constat mineur corrigé : Pint n'était pas passé sur la migration 2026_11_16_081825 (mise en forme seule).
- Non revérifié par cette revue : les contrôles navigateur déclarés pour les lots 06 et 07.

### Parcours manuel proposé — données fictives
1. Avec la dérogation locale et un administrateur fictif en contexte d'établissement, créer une série sur /planning puis ouvrir « Prévisualiser et publier ».
2. Lire la liste des différences, saisir un motif, publier et confirmer : la version 1 apparaît.
3. Avec un apprenant inscrit dans le groupe, ouvrir /planning en contexte d'établissement : le cours est visible, marqué publié.
4. Avec l'enseignant du cours, signaler une indisponibilité : le cours reste affiché pour l'apprenant.
5. Côté administration, accepter et annuler, vérifier que l'apprenant voit toujours le cours, publier, puis vérifier qu'il le voit annulé.
6. Revenir à la version 1 : le cours redevient prévu, une version 3 est créée.

### Non vérifié
Rendu navigateur des écrans de publication (360 px, clavier, zoom) ; deux publications réellement simultanées ; volume (limite proposée de 20 000 occurrences par année, non mesurée) ; consommation des événements et recalcul des rappels (lot 13).
### Complément du 16 novembre 2026 — décisions A24 et A25
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| P08-08 | Retour arrière non bloquant : références invalides ignorées et comptées, chevauchements signalés | Cours dont l'affectation de l'enseignant a pris fin non rétabli et resté annulé pour les apprenants ; autre cours rétabli ; chevauchement avec un cours resté en brouillon compté ; résumé de version et message à l'écran | réussi |
| P08-09 | Retour arrière sans aucun cours rétablissable : rien n'est écrit | Groupe archivé : message explicite, aucune nouvelle version, état inchangé | réussi |
| P08-10 | Contexte plateforme : planning et références personnels, sans accès institutionnel | Administration plateforme et éditeur : /planning et /etudes/referentiels 200 en mode personnel ; aucun cours institutionnel ; /planning/publication 403 | réussi |
Suite complète après ces changements : 117 tests réussis, 1 965 assertions, 55 454 ms ; Pint passé. Le constat « contexte plateforme = 403 » de la revue 06/07 est donc clos.


## Lot 09 — import Excel du planning
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, stockage privé simulé, file synchrone. Import fermé hors dérogation locale, faute de second facteur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| X09-01 | Modèle XLSX documenté, sans macro ni formule, relu par l'importeur | Téléchargement, signature ZIP, en-têtes A à K, notice avec fuseau et formats, aucune partie de macro | réussi |
| X09-02 | Fin avant début localisée, rien d'appliqué ni publié (R12) | Erreur feuille Planning, ligne 3, colonne fin ; application refusée ; aucune série, occurrence ni publication | réussi |
| X09-03 | Référence inconnue dans un lot mixte : lot entier bloqué, rien créé en silence (R13) | Groupe, matière, enseignant inconnus, groupe d'un autre établissement, enseignant non affecté ou étranger : sept erreurs localisées ; nombre de groupes, matières, comptes et affectations inchangé | réussi |
| X09-04 | Simulation sans écriture puis application atomique au seul brouillon | Avant application : aucune série ; après : 4 séries, 18 occurrences, heures et dates Excel converties, identifiant externe enregistré ; rien de publié ; seconde application refusée | réussi |
| X09-05 | Même fichier ou mêmes lignes soumis deux fois : aucun doublon (R14) | Fichier identique et clé rejouée : même lot, un seul fichier stocké ; lignes déjà présentes ignorées avec avertissement ; doublon dans le fichier bloquant | réussi |
| X09-06 | Chevauchement visible et confirmation motivée (R15) | Conflits avec le brouillon et entre lignes ; application refusée sans motif d'au moins 10 caractères ; doublon exact bloquant | réussi |
| X09-07 | Mise à jour par identifiant externe, puis publication et retour arrière (R16) | Même série, mêmes UUID, version 2 de la règle ; publication 1, publication 2, retour à la version 1 ; identifiant externe en double dans le fichier bloquant | réussi |
| X09-08 | Sans identifiant externe, correspondance proposée et décision exigée avant application | Proposition avec le cours existant ; application refusée sans décision ; décision invalide : lot annulé en entier ; décision valide : série mise à jour | réussi |
| X09-09 | Tout ou rien si les références changent après la simulation | Affectation close entre simulation et application : aucune série créée, lot invalide, motif consigné | réussi |
| X09-10 | Fichiers non sûrs ou hors limites refusés | Faux .xlsx, .xlsm, .csv, projet de macros, décompression excessive, taille, nombre de lignes, nombre de feuilles, formules, en-têtes déplacés | réussi |
| X09-11 | Droits : administration de l'année active seulement ; jobs revérifiant les droits | Apprenant, enseignant, individuel : 403 ; lot de A introuvable pour l'admin B ; job d'analyse après retrait de l'administrateur : lot en échec ; application directe refusée ; tout fermé sans dérogation | réussi |
| X09-12 | Rapport téléchargeable sans injection de formule ; purge du brut après 30 jours | CSV avec feuille, ligne, colonne, gravité ; valeurs commençant par = ou @ neutralisées ; fichier et lignes lues supprimés après 30 jours, rapport conservé, lot non appliqué rendu inapplicable | réussi |
| X09-13 | Import de 1 000 lignes mesuré (R54 partiel) | Trois passages mesurés, détail dans preuves/lot09-mesure-1000-lignes.txt | mesuré : 121,3 s sans débogueur, 335,4 s avec ; cible de 120 s non tenue avec marge |

### Résultats exacts
- Migrations 2026_11_16_120000 (lots d'import, clé externe des séries) et 2026_11_16_121000 (index de recherche des chevauchements) : appliquées sur test puis dev, treize migrations Ran sur chaque base.
- php artisan test --compact : 130 tests réussis, 1 ignoré (mesure sur demande), 2 222 assertions, 69 676 ms. Suite identique avant et après l'optimisation de la détection de conflits.
- Pint passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 490 ms.
- Premier passage des tests du lot : une erreur fatale (propriété $line redéclarée dans une exception) puis six échecs dus aux tests eux-mêmes et au fichier temporaire du générateur XLSX sous Windows ; corrigés sans assouplir de contrôle.

### Parcours manuel proposé — données fictives
1. Avec la dérogation locale, un administrateur fictif en contexte d'établissement et un worker actif, ouvrir /planning/import et télécharger le modèle.
2. Saisir deux lignes avec des codes de groupe et de matière existants, dont une avec une fin avant le début ; déposer : le rapport indique la feuille, la ligne et la colonne, et rien n'est appliqué.
3. Corriger, déposer de nouveau, appliquer au brouillon après confirmation, puis prévisualiser et publier depuis /planning/publication.
4. Déposer une seconde fois le même fichier : le lot existant s'affiche, sans doublon.

### Non vérifié
Rendu navigateur de l'écran d'import et envoi réel d'un fichier depuis un navigateur ; traitement par un worker de file réel (les tests utilisent la file synchrone) ; fichiers produits par Excel, LibreOffice ou Google Sheets (les tests utilisent des classeurs générés par l'application) ; fichier proche de 5 Mo ou de 5 000 lignes ; mesure sur l'environnement cible.


## Lot 10 — retour privé après cours
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, date de test fixée au 16 novembre 2026. Ce lot n'exige aucun rôle privilégié : il fonctionne sans la dérogation de second facteur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| L10-01 | Cours terminé proposé sur l'accueil, le planning et « Mes retours », sans notification ; reporter, ignorer, non renseigné sans pénalité (R21 partiel) | Cours terminé listé, cours à venir et cours de plus de 7 jours non listés ; reporté reste proposé ; ignoré disparaît mais reste renseignable ; visiteur sans rien | réussi |
| L10-02 | Un retour par propriétaire et par cours, champs facultatifs, « Rien à signaler », historique limité | Champ seul accepté ; retour vide refusé avec renvoi vers « Rien à signaler » ; trois versions ; historique = version, état, noms de champs, sans contenu ; doublon refusé par MySQL (1062) | réussi |
| L10-03 | Double clic ou rejeu : un retour, une tâche ; version dépassée = conflit (R23 partiel) | Deux demandes identiques : même retour, même tâche, une seule entrée d'historique ; contenu différent sur version 0 : 409 VERSION_CONFLICT au format d'erreur commun, rien écrasé | réussi |
| L10-04 | Tâche créée avec le retour, ou rien | Titre manquant ou trop long : aucun retour, aucune tâche, aucun événement ; titre par défaut = première ligne du travail ; pas de seconde tâche à la modification | réussi |
| L10-05 | Retour enregistré : plus de rappel utile (R22 partiel) | Règle shouldRemind fausse après enregistrement ; événement checkin.settled unique, sans contenu privé | réussi |
| L10-06 | Cours annulé : ni retour attendu ni rappel (R18) | Règle fausse, absent de la liste, création refusée | réussi |
| L10-07 | Cours publié d'un groupe : retour de l'apprenant inscrit ; report = même cours, même retour | Brouillon non publié, non-inscrit et autre établissement : 404 ; après report publié, même retour et nouvel horaire affiché ; après retrait de l'appartenance, le retour reste lisible et modifiable par son propriétaire, aucun nouveau retour possible | réussi |
| L10-08 | Aucun enseignant, administrateur ou rôle plateforme ne lit un retour (R58) | Enseignant du cours, admin A, admin B, plateforme, éditeur, tiers : 404 en lecture et en écriture, policy refusée, texte privé absent des listes, du planning, de la publication, de l'administration et de l'export ; aucune route ne désigne le retour d'autrui ; journal, événements et historique sans contenu | réussi |
| L10-09 | Visiteur et compte suspendu sans accès | Redirection vers la connexion ; règle de rappel fausse | réussi |

### Résultats exacts
- Migration 2026_11_16_140000_create_course_check_ins_and_tasks : appliquée sur test puis dev, quatorze migrations Ran sur chaque base.
- php artisan test --compact (XDEBUG_MODE=off) : 139 tests réussis, 1 ignoré (mesure sur demande), 2 660 assertions, 35 851 ms.
- Pint : deux fichiers reformatés puis suite rejouée ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 460 ms.
- Premier passage : erreur de chargement due à un assistant de test nommé comme une méthode du cadre de test, puis un échec d'assertion de test ; corrigés sans toucher à l'application.

### Parcours manuel proposé — données fictives
1. Se connecter avec un compte individuel fictif, créer sur /planning un événement ponctuel déjà commencé aujourd'hui.
2. Ouvrir l'accueil : « Votre prochaine action » propose le retour une fois le cours terminé. L'ouvrir aussi depuis le planning.
3. Cliquer « Rien à signaler », puis rouvrir et saisir un travail à effectuer en cochant la création d'une tâche : le message confirme le retour et la tâche.
4. Recharger et vérifier que le retour n'est plus proposé dans « À renseigner » et figure dans « Mes retours ».

### Non vérifié
Rendu navigateur des écrans du retour (360 px, clavier, zoom) et avertissement avant abandon de saisie ; rappel réel à la fin du cours et annulation d'un envoi (lot 13) ; brouillon hors connexion (lot 20) ; pièces jointes (lot 14) ; consultation des tâches créées, qui n'ont pas encore d'écran (lot 11).

## Lot 11 — tâches et devoirs collectifs
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, date de test fixée au 16 novembre 2026. Les tâches personnelles et l'avancement des apprenants fonctionnent sans dérogation ; la publication d'un devoir par l'enseignant exige la dérogation locale de second facteur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| T11-01 | Tâche personnelle : champs facultatifs, quatre états, retard calculé | Titre seul accepté ; priorité, date ou état invalides refusés ; échéance passée : « en retard » tant que la tâche est ouverte, plus de retard une fois terminée ou abandonnée ; état « late » refusé par la validation et par MySQL (3819) ; matière d'autrui et cours inaccessible refusés | réussi |
| T11-02 | Filtres état, priorité, matière, date ; tâches sans échéance visibles | Chaque filtre renvoie la liste attendue ; la tâche sans échéance reste listée avec un filtre de date ; valeurs de filtre invalides refusées ; accueil : trois prochaines tâches, rien pour un visiteur | réussi |
| T11-03 | Isolation du propriétaire | Apprenant, enseignant, admin A, admin B, plateforme, éditeur : titre absent de toutes les listes, 404 sur modification, progression, suppression, restauration et étapes, policy refusée, règle de rappel fausse ; owner_id, institution_id et scope envoyés par le client ignorés ; journal et événements sans contenu ; visiteur renvoyé à la connexion | réussi |
| R57 (test_r57) | Double clic ou renvoi du formulaire : une seule tâche ; version dépassée = conflit | Deux créations identiques : même tâche ; même clé et autre contenu : 409 VERSION_CONFLICT ; même clé chez un autre compte : tâche distincte ; modification rejouée sans effet ; version dépassée : 409 en JSON et message d'erreur en formulaire, rien écrasé ; création rejouée après modification : rien réécrit | réussi (partiel pour R57 : serveur seulement) |
| R24 (test_r24) | Devoir publié par l'enseignant affecté ; avancement séparé par apprenant | Devoir visible des deux apprenants du groupe, pas du tiers, de l'enseignant ni de l'admin dans « Mes tâches » ; aucun avancement créé d'office ; l'un termine, l'autre reste à faire puis passe en cours ; devoir inchangé ; l'apprenant ne peut ni modifier ni retirer le devoir (404/403) ; la page de l'enseignant ne contient aucun état d'apprenant | réussi |
| T11-05 | Affectation enseignant | Groupe non affecté, groupe d'un autre établissement, institution_id forcé : 404 ; admin, contexte personnel : 403 ; enseignant d'un autre établissement : 404 ; sans dérogation de second facteur : lecture seule, publication 403 ; affectation clôturée : plus de publication ni de modification, devoir toujours visible des apprenants ; inscription clôturée : devoir et avancement inaccessibles, plus de rappel | réussi |
| T11-04 | Changement collectif : avancements conservés, un événement dédupliqué | Modification de la consigne et de la date : version 2, un seul homework.updated (clé …:v2, champs modifiés sans contenu), rejeu sans nouvel événement, doublon refusé par MySQL (1062) ; avancements identiques avant et après ; version dépassée avec autre contenu : 409 ; second changement réel : second événement distinct | réussi |
| T11-06 | Report personnel sans toucher la date officielle | Objectif personnel enregistré, rejoué sans effet, conflit sur version dépassée ; due_at envoyé par l'apprenant ignoré ; date officielle et version du devoir inchangées ; objectif invisible de l'autre apprenant ; retard calculé sur la date officielle ; deux avancements pour un même apprenant refusés par MySQL (1062) | réussi |
| R25 (test_r25) | Terminer ou abandonner annule les rappels, y compris après rejeu | Règle shouldRemind fausse après « terminé » et « abandonné » ; task.settled une fois par version ; ancienne modification « à faire » rejouée : 409, la tâche reste terminée ; réouverture explicite : rappels de nouveau possibles ; devoir : seul l'apprenant qui a fini n'est plus rappelé ; tâche supprimée ou compte suspendu : pas de rappel | réussi (partiel pour R25 : aucun envoi réel) |
| T11-07 | Suppression, corbeille 7 jours, restauration, purge | Version obligatoire ; suppression rejouée sans effet ; tâche absente de la liste, présente en corbeille avec sa date de purge ; à J+6 la purge ne supprime rien et la restauration rend la tâche avec son étape ; à J+7 la restauration est refusée et la purge supprime la tâche et ses étapes, pas les autres tâches | réussi |
| T11-08 | Devoir retiré : avancement conservé jusqu'au rétablissement ou à la purge | Retrait : invisible des apprenants, progression refusée, plus de rappel ; rétablissement : avancement retrouvé ; événements publié, retiré, rétabli ; après 7 jours : rétablissement refusé, purge du devoir et des avancements | réussi |
| T11-09 | Microtâches privées, prochaine action | Titre vide ou trop long refusé ; ajout rejoué : une seule étape ; première étape ouverte = prochaine action ; cocher deux fois sans effet ; les étapes ne changent pas l'état de la tâche ; sur un devoir, les étapes d'un apprenant sont invisibles de l'autre et de l'enseignant ; plafond d'étapes respecté | réussi |

### Résultats exacts
- Migration 2026_11_17_090000_extend_tasks_for_homework_and_steps : appliquée sur test puis dev, quinze migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/TaskTest.php (XDEBUG_MODE=off) : 12 tests réussis, 572 assertions, 6 047 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 151 tests réussis, 1 ignoré (mesure sur demande), 3 239 assertions, 40 154 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 929 ms.
- Premier passage du fichier de tests : 8 échecs sur 17, tous dus à un modèle Task créé en mémoire sans ses valeurs par défaut (erreur 500 à la création). Corrigé dans le modèle ; aucun contrôle n'a été assoupli. Trois tests ont ensuite été renommés pour porter le numéro du scénario de recette qu'ils prouvent réellement, puis rejoués.
- NavigationTest adapté : /taches n'est plus un écran d'attente ; sans connexion il renvoie à la connexion, y compris en production.

### Parcours manuel proposé — données fictives
1. Compte individuel fictif : ouvrir /taches, ajouter « Réviser le chapitre 2 » sans échéance, puis une tâche avec une échéance passée : elle porte « en retard ».
2. Ajouter deux étapes à une tâche, cocher la première : la prochaine action change. Cliquer « Terminer » : la mention de retard disparaît.
3. Filtrer par dates : la tâche sans échéance reste dans « Sans échéance ».
4. Supprimer une tâche (confirmation), ouvrir la corbeille, la restaurer.
5. Avec les comptes d'établissement (non ouvrables à la main tant que les comptes fictifs n'ont pas de mot de passe connu) : l'enseignant publie un devoir sur /taches/devoirs ; deux apprenants du groupe le voient ; l'un le termine, l'autre le voit toujours « à faire » ; l'enseignant change la date : chacun garde son état.

### Non vérifié
Rendu navigateur des écrans Tâches et Devoirs (360 px, clavier, zoom, boîte de confirmation) ; conservation de la saisie après une coupure réseau ; envoi réel des notifications de devoir et annulation d'un rappel programmé (lot 13) ; file hors connexion (lot 20) ; exécution réelle de la purge par le planificateur ; comportement avec un grand nombre de tâches (pagination à 30, 100 tâches sans échéance affichées au plus).

## Lot 12 — notes et moyennes
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, date de test fixée au 16 novembre 2026. Les notes personnelles et la lecture des résultats publiés fonctionnent sans dérogation ; la saisie par l'enseignant et les coefficients de l'administration exigent la dérogation locale de second facteur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R26, R27 (calculateur) | 14/20 coef 1 et 36/60 coef 2 → 12,67 ; maths coef 3, français 15 coef 2 → 13,60 sur valeurs non arrondies | Moyenne de matière égale à la fraction 38/3, affichée 12,67 ; générale égale à 68/5, affichée 13,60 ; contre-épreuve : 12,665 et 12 donnent 12,33 alors que des moyennes arrondies donneraient 12,34 ; matière sans note ignorée ; coefficient non défini : pas de générale ; seuil strict | réussi |
| R26, R27 (notes personnelles) | Mêmes résultats à l'écran, avec couverture | 12,67 sur 2 notes sur 2 ; 13,60 sur 2 matières sur 3, la troisième (coefficient 9, sans note) n'abaissant rien ; note sur 60 affichée « soit 12,00 / 20 » ; coefficient nul, version dépassée et matière d'autrui refusés | réussi |
| R28 | États distincts de zéro ; barème nul refusé | À venir, absence, dispense, non rendu : moyenne inchangée (14,00 sur 1 note parmi 5) ; note zéro réelle : 7,00 et matière à surveiller ; barème 0, barème négatif, coefficient 0, note hors barème, note négative, note absente pour un état noté, valeur pour une absence : refusés ; mêmes refus par MySQL (3819) | réussi |
| G12-01 | Matière, période et date cohérentes ; recalcul après correction et suppression ; double clic | Matière ou période d'autrui, date hors période : refusées ; correction 36→48 sur 60 : 15,33 ; suppression : 14,00 ; rejeu sans effet ; version dépassée : 409 ; double clic : une note | réussi |
| R58 (notes) | Aucun rôle ne lit une note personnelle | Enseignant, admin A, admin B, plateforme, éditeur, tiers, autre apprenant : intitulé et valeur absents de toutes les pages, 404 en modification, suppression sans effet et sans information, policy refusée ; aucune ligne de journal ni d'événement ; visiteur renvoyé à la connexion | réussi |
| G12-02 | Résultats publiés aux seuls apprenants concernés, avec moyennes | Brouillon invisible ; après publication 12,67 pour l'un ; sans coefficients de matière : pas de générale, deux coefficients manquants signalés ; après réglage par l'administration : 13,60 ; l'autre apprenant voit sa note, son absence et une note manquante, et aucune valeur du premier dans sa page ; tiers, appartenance d'autrui, appartenance retirée : 404 | réussi |
| R29 | L'apprenant ne remplace pas un résultat publié ; provenance visible | Toutes les routes de l'enseignant : 403 ; routes des notes personnelles sans effet sur le résultat ; résultat et version inchangés ; note personnelle de même intitulé : chaque source garde sa moyenne (8,00 et 20,00), provenance « institutional » ou « personal » sur chaque note ; source combinée refusée | réussi |
| R30 | Rattachement à l'année, à la période et à l'affectation | Période d'une autre année ou d'un autre établissement, date hors période, barème ou coefficient nul : refusés ; groupe ou matière hors affectation : 404 ; insertion directe d'une période d'une autre année refusée par MySQL (1452) ; résultat hors barème, sans valeur, pour un non-inscrit : refusé ; note zéro réelle acceptée et comptée | réussi |
| G12-03 | Droits de gestion | Admin, enseignant hors contexte, enseignant d'un autre établissement, plateforme : 403 ou 404 sur chaque écriture et sur la lecture ; coefficients réservés à l'administration de l'établissement ; sans dérogation de second facteur : lecture seule ; affectation ou inscription clôturée : plus de saisie | réussi |
| G12-04 | Correction et retrait audités sans valeur ; recalcul | Correction sans motif refusée ; avec motif : 15,33, une révision (valeur, barème, auteur, motif), révision non modifiable, un événement (clé …:v4) et une ligne de journal limités aux identifiants ; aucune note, aucun barème, aucun intitulé dans le journal ni les événements ; barème inférieur à une note refusé ; barème corrigé : 12,67 ; retrait motivé : 14,00, l'autre apprenant inchangé ; republication refusée ; double clic à la création et à la publication : une évaluation, un événement | réussi |

### Résultats exacts
- Migration 2026_11_17_140000_create_grade_tables : appliquée sur test puis dev, seize migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/GradeTest.php (XDEBUG_MODE=off) : 10 tests réussis, 524 assertions, 5 757 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 161 tests réussis, 1 ignoré (mesure sur demande), 3 754 assertions, 44 579 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 394 ms.
- Passages intermédiaires : 13 tests sur 15 avant l'écriture des écrans (une page absente, un ordre de clés dans une assertion), puis deux assertions qui supposaient un ordre entre des notes de même date. Les tests ont été rendus indépendants de l'ordre ; aucune règle de l'application n'a été modifiée pour les faire passer.
- NavigationTest adapté : /notes n'est plus un écran d'attente ; sans connexion il renvoie à la connexion, y compris en production.

### Parcours manuel proposé — données fictives
1. Compte individuel fictif : créer deux matières personnelles (Mathématiques, Français) dans /etudes/referentiels.
2. Ouvrir /notes : ajouter en Mathématiques 14 sur 20 coefficient 1, puis 36 sur 60 coefficient 2. Attendu : moyenne indicative 12,67.
3. Ajouter en Français 15 sur 20. Régler les coefficients de matière à 3 et 2. Attendu : moyenne générale 13,60 sur 2 matières.
4. Ajouter une note « Absence » : la moyenne ne bouge pas. Ajouter un 0 sur 20 : elle baisse.
5. Essayer un barème 0 : refus avec message.
6. Avec les comptes d'établissement (non ouvrables à la main tant que les comptes fictifs n'ont pas de mot de passe connu) : l'enseignant crée une évaluation sur /notes/evaluations, saisit et publie ; chaque apprenant ne voit que son résultat sur /notes, source « Résultats publiés ».

### Non vérifié
Rendu navigateur des trois écrans (360 px, clavier, zoom, saisie de 30 résultats) ; envoi réel d'une notification de publication ou de correction (lot 13) ; lecture hors connexion des notes déjà autorisées (lot 20) ; volumes (une classe de plus de 500 apprenants est refusée par la validation ; aucune mesure de charge) ; règles propres à un établissement réel (arrondis, notes éliminatoires, rattrapages), volontairement absentes.

## Lot 13 — rappels et canaux
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, horloge contrôlée (départ lundi 16 novembre 2026, 8 h UTC). Courriel simulé par le simulateur de notifications de Laravel, Web Push par le simulateur HTTP : aucun envoi réel. Aucun rôle privilégié n'est requis pour recevoir et régler ses rappels.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R21 | Rappel calculé par le serveur à fin de cours + 5 minutes, ou au délai choisi | À 9 h 59 rien ; à 10 h 01 rappel programmé pour 10 h 05, valide jusqu'à 16 h 05 ; délai réglé à 30 minutes : 10 h 30 ; remis à l'heure dans le centre, compteur à 1 ; cours publié : les deux apprenants inscrits, ni l'enseignant ni l'administration ; ouverture = lu + page du retour ; tiers : 404 ; cycle inscrit chaque minute au planificateur | réussi |
| R18, R22 | Cours annulé, reporté ou déjà renseigné : pas de rappel | Annulé avant la fin : aucun rappel créé ; annulé après programmation : annulé (course_cancelled) ; retour enregistré : annulé (settled) ; cours publié reporté : ancien rappel annulé (rescheduled), un seul rappel à la nouvelle fin ; retour renseigné après remise : rappel retiré du centre, celui de l'autre apprenant intact | réussi |
| R25 | Rappels de tâche dans le fuseau de la personne ; arrêt si la tâche est terminée ou déplacée | Veille à 8 h pour Abidjan ; pour un utilisateur réglé sur Tokyo, veille à 23 h UTC (8 h locale) et pas de rappel du jour, qui tomberait après l'échéance ; rappel de veille désactivé et heure réglée à 6 h 30 respectés ; tâche terminée ou échéance déplacée entre programmation et remise : annulé ; terminée après remise : retirée du centre | réussi |
| R43 | Rejeu ou interruption : jamais de second rappel ni de second envoi | Trois cycles : un rappel, un envoi, un courriel, au titre neutre et sans intitulé de tâche ; doublons refusés par MySQL (1062) sur le rappel et sur l'envoi ; envoi réservé puis interrompu : non repris, « indéterminé » après 10 minutes, une seule tentative ; événement écrit pendant l'arrêt repris une fois ; événement rejoué : rien recréé | réussi |
| R44 | Push refusé ou non configuré : rien n'est bloqué ; aucun contenu poussé | Canal non configuré : inscription 404, écran explicite ; préférence push sans appareil : rappel dans le centre, aucun envoi, sept pages en 200 ; huit adresses non reconnues refusées (autre hôte, HTTP, sous-domaine piégé, identifiants, port, adresse IP) ; appareil activé : un seul appel, corps vide, en-tête d'identification, durée de vie, sujet ; adresse d'abonnement jamais renvoyée au navigateur ; retrait par le propriétaire seulement ; service worker sans lecture de contenu | réussi (partiel pour R44 : pas de vrai téléphone) |
| N13-01 | Échecs de push bornés, abonnement invalide révoqué, absence de réponse incertaine | 410 : échec et abonnement révoqué ; 503 : tentatives à 8 h 01, 8 h 02, 8 h 07, 8 h 22 puis échec « retries_exhausted » ; aucune réponse : « indéterminé », une tentative, jamais reprise ; cinq appels comptés au total ; centre alimenté dans les trois cas ; aucune adresse dans le suivi des envois | réussi |
| R53 | Après une panne prolongée : périmés expirés, utiles conservés, pas de rafale | Arrêt simulé du lundi 10 h 01 au jeudi 10 h : rappel de cours programmé lundi expiré ; événements de lundi consommés sans alerte ; aucun rappel pour les cours manqués ; trois rappels « veille » encore valides remis (une tâche, un devoir pour deux apprenants) ; aucun courriel ; envoi resté en file expiré au lieu de partir | réussi |
| N13-02 | Plage silencieuse dans le fuseau de la personne ; canaux externes seulement | Plage 22 h – 7 h à Tokyo : rappel visible tout de suite dans le centre, courriel différé à 22 h UTC ; plage 13 h – 15 h à Abidjan : courriel à 15 h ; sans plage : immédiat ; plage élargie entre-temps : de nouveau différé sans compter de tentative ; validité achevée pendant la plage : expiré, pas d'envoi au réveil | réussi |
| N13-03 | Préférences validées, versionnées, recontrôlées avant envoi | Huit saisies invalides refusées (fuseau, plage incomplète ou nulle, délai hors bornes, heure, catégorie obligatoire, catégorie inconnue) ; version dépassée : 409 ; plage incomplète refusée par MySQL (3819) ; catégorie désactivée : rien créé, ou annulé si désactivée entre-temps ; courriel coupé ou tâche terminée entre remise et envoi : envoi annulé | réussi |
| N13-04 | Seules les personnes concernées, sans contenu sensible | Publication de sept cours : une alerte par apprenant et par enseignant concernés, aucune pour les tiers ; deux cours déplacés : une alerte regroupée ; devoir et résultat : apprenants du groupe ; appartenance retirée avant la remise : non informé ; correction : le seul apprenant corrigé, une fois ; aucune note ni aucun intitulé dans la table des rappels ; synthèse quotidienne seulement si demandée et utile, une par jour ; compte suspendu : annulé | réussi |

### Résultats exacts
- Migration 2026_11_17_180000_create_notification_tables : appliquée sur test puis dev, dix-sept migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/NotificationTest.php (XDEBUG_MODE=off) : 10 tests réussis, 362 assertions, 5 618 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 171 tests réussis, 1 ignoré (mesure sur demande), 4 121 assertions, 50 851 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 437 ms.
- Preuve du chemin réel planificateur → file → exécution : ApplicationFoundationTest lance schedule:run, constate deux tâches dans la table jobs (battement de cœur, cycle de rappels), les exécute avec queue:work, et vérifie zéro tâche restante, zéro échec et les trois positions de reprise écrites.
- Passages intermédiaires : 5 tests sur 10, puis 7, 9, 10. Causes : génération de clé OpenSSL impossible sous Windows sans fichier de configuration (corrigé dans l'application par un repli sur le fichier livré avec PHP) ; tests qui attendaient un rappel « veille » pour une tâche créée après l'heure de ce rappel (tests corrigés, règle conservée) ; trois assertions de test erronées. Aucun contrôle de l'application n'a été assoupli.
- Incident de test : deux exécutions en échec d'ApplicationFoundationTest, qui s'exécute hors transaction, ont laissé cinq tâches dans la table jobs de la base de test. Base vérifiée (hako_ra_campus_test), lignes supprimées, tests rendus insensibles à ce résidu. La base de développement n'a pas été touchée.

### Parcours manuel proposé — données fictives
1. Lancer la file et le planificateur (composer run dev, ou php artisan queue:work et php artisan schedule:work).
2. Compte individuel fictif : créer sur /planning un événement qui se termine dans quelques minutes. Six minutes après sa fin, « Notifications (1) » apparaît dans l'en-tête ; l'ouvrir mène au retour du cours.
3. Renseigner le retour avant ce délai sur un second événement : aucun rappel n'apparaît.
4. Créer une tâche à échéance demain, régler dans /notifications/preferences l'heure des rappels à la minute suivante… le rappel « veille » n'apparaît que si la tâche existait avant cette heure : créer la tâche d'abord, régler l'heure ensuite.
5. Ouvrir /notifications/preferences : l'écran indique que le courriel et les notifications du navigateur ne sont pas configurés.
6. Pour un cycle immédiat sans attendre la minute : php artisan campus:notifications:run (n'affiche que des compteurs).

### Non vérifié
Envoi réel d'un courriel de rappel ; acceptation du signal Web Push par un service réel (Chrome, Firefox, Edge, Safari) et affichage du message générique sur un téléphone, y compris écran verrouillé ; demande d'autorisation et refus dans un vrai navigateur ; deux processus de file réellement simultanés ; tenue en charge du cycle d'une minute ; comportement au changement d'heure d'un fuseau avec heure d'été ; rendu des deux écrans (360 px, clavier, zoom).

## Lot 14 — pièces jointes
Cas exécutés sur MySQL hako_ra_campus_test, données fictives, stockage de test isolé. L'antivirus est remplacé par un scanner de test qui déclare « détecté » tout fichier contenant un marqueur inoffensif : aucun logiciel malveillant, réel ou de test, n'a été utilisé ni stocké.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R49 (refus) | Fichier déguisé, actif, trop gros ou non autorisé : refusé avant stockage | Dix-huit cas refusés : programme renommé en PDF, PNG renommé en PDF, PDF renommé en PNG, HTML en JPEG, script en PNG, SVG, document à macros, exécutable, double extension, sans extension, PDF avec script (en clair et masqué en hexadécimal), action automatique, lancement, fichier embarqué, PNG tronqué, fichier vide, fichier trop gros ; envoi sans fichier ou avec une adresse web : refusé ; aucune ligne, aucun fichier ; PDF, PNG et JPEG valides acceptés ; nom de stockage aléatoire de 64 caractères, jamais transmis ; rien sous public/ ; valeurs interdites refusées par MySQL (3819) | réussi |
| R49 (quarantaine) | Fichier bloqué tant qu'il n'est pas déclaré sain ; scanner indisponible = jamais validé | Quarantaine : 423 FILE_NOT_AVAILABLE même pour le propriétaire, écran explicite ; scanner non configuré, démon injoignable, scanner en panne : état « erreur », toujours bloqué ; commande de contrôle en échec dans les deux cas ; lecture stricte de la réponse du démon (six réponses ambiguës refusées) ; maintenance : analyse relancée, analyse interrompue remise en quarantaine ; scanner opérationnel : « sain », téléchargement identique à l'octet près, enregistrement forcé, nosniff, bac à sable, sans cache ; fichier détecté : refusé, retiré du stockage, hors quota | réussi (antivirus réel non vérifié) |
| N14-01 | Limites par élément et par compte, y compris pour les fichiers non encore analysés | Quatrième fichier refusé ; double clic : un fichier, une analyse ; suppression : place et espace rendus ; quota ramené à 1 Mo : deux dépôts de 400 Ko acceptés, le troisième refusé alors que les deux premiers sont en quarantaine ; petit fichier encore accepté ; autre compte non affecté ; compteurs affichés exacts | réussi (dépôts enchaînés, non simultanés) |
| R50 | Lien transmis sans droit : aucun accès | Fichier personnel : sept comptes (tiers, autre apprenant, enseignant, admin A, admin B, plateforme, éditeur) reçoivent 404 en téléchargement, suppression, signalement et dépôt ; nom absent de leurs pages ; visiteur renvoyé à la connexion ; fichier d'un retour de cours : même règle ; tâche en corbeille : 404, restaurée : 200, purgée : fichier et ligne supprimés | réussi |
| N14-02 | Fichier de groupe = droits du devoir ; fichier personnel jamais partagé ; accès perdu après retrait | Fichier du devoir : apprenants inscrits et enseignant affecté 200, tiers et administrations 404 ; fichier personnel d'un apprenant sur le devoir : lui seul ; l'apprenant ne peut ni déposer en « groupe » ni supprimer le fichier du devoir ; visibilité forcée en base sur une tâche personnelle : toujours 404 ; sans second facteur : dépôt collectif 403 ; inscription clôturée ou appartenance retirée : 404 sur les deux fichiers, suppression de son fichier encore possible ; devoir retiré : 404, rétabli : 200 ; affectation clôturée : 404 | réussi |
| N14-03 | Signalement et circuit de retrait | Motif obligatoire ; tiers 404, auteur 403, fichier personnel 404 ; avant signalement l'administration reçoit 404 ; un signalement ouvert par personne ; fichier toujours disponible avant décision ; file réservée à l'administration de l'établissement ; elle télécharge le fichier signalé, pas un fichier personnel ; autre établissement 404 ; sans second facteur 403 ; retrait : 404 pour apprenants et enseignant, tous les signalements clos, journal ; classement sans suite : fichier conservé ; signalement ouvert incohérent refusé par MySQL (3819) | réussi |
| N14-04 | Suppression, orphelins, purge | Suppression : fichier retiré aussitôt, 404 ensuite ; maintenance : fichier d'une ligne supprimée retiré, orphelin de deux jours supprimé, orphelin récent et fichier actif conservés ; après 31 jours : deux lignes supprimées purgées, fichier actif intact et téléchargeable, espace utilisé exact | réussi |

### Résultats exacts
- Migration 2026_11_17_210000_create_attachment_tables : appliquée sur test puis dev, dix-huit migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/AttachmentTest.php (XDEBUG_MODE=off) : 7 tests réussis, 447 assertions, 9 160 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 178 tests réussis, 1 ignoré (mesure sur demande), 4 568 assertions, 59 200 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 415 ms.
- Défaut trouvé par les tests et corrigé dans l'application : la contrainte MySQL des signalements acceptait un signalement « ouvert » sans marqueur d'unicité (comparaison avec NULL). Contrainte corrigée ; la migration, appliquée seulement sur la base de test et sans donnée, y a été annulée puis rejouée avant d'être appliquée sur la base de développement.
- Choix corrigé en cours de lot : un fichier non diffusable répond 423 (code commun FILE_NOT_AVAILABLE) et non 409, dont le message commun parlait à tort de version.
- Deux tests antérieurs ajustés après la suite complète : liste figée des routes « retour » (lot 10), et un test du lot 12 qui cherchait « 36 » dans un texte contenant des identifiants aléatoires (échec intermittent sans rapport avec ce lot).

### Configuration de recette et procédure de test du scanner — sans logiciel malveillant
1. Installer ClamAV sur le serveur de recette et démarrer son démon clamd en écoute TCP locale (port 3310 par défaut), avec des signatures à jour (freshclam).
2. Dans le fichier d'environnement : CAMPUS_ATTACHMENT_SCANNER=clamd, CAMPUS_CLAMD_HOST=127.0.0.1, CAMPUS_CLAMD_PORT=3310.
3. Exécuter php artisan campus:attachments:scanner-check. Attendu : « Fichier de test EICAR : infected », « Contenu sain : clean », « Antivirus opérationnel sur ce serveur », code de sortie 0. Le fichier EICAR est la chaîne de test standard des antivirus, sans aucun effet ; la commande l'assemble en mémoire et ne l'écrit jamais sur disque.
4. Arrêter clamd puis relancer la commande. Attendu : échec, « Le démon antivirus ne répond pas ». Déposer un PDF dans l'application : il reste « Analyse impossible pour l'instant », non téléchargeable.
5. Redémarrer clamd, attendre la maintenance (dix minutes) ou exécuter php artisan campus:attachments:maintain puis laisser la file traiter : le fichier devient « Analysé, disponible ».
6. Ne pas déposer de fichier EICAR par l'interface sur un poste protégé par un antivirus local : il serait intercepté avant l'envoi. La commande de l'étape 3 suffit à prouver la détection.
Tant que l'étape 3 n'a pas réussi sur un serveur donné, l'antivirus n'y est pas déclaré actif.

### Parcours manuel proposé — données fictives
1. Compte individuel fictif : ouvrir /taches, créer une tâche, déposer un petit PDF. Attendu sur ce poste : « Analyse impossible pour l'instant : fichier non diffusé » et le message indiquant que l'antivirus n'est pas configuré ; aucun bouton de téléchargement.
2. Renommer un fichier quelconque en .pdf et le déposer : refus « Le contenu de ce fichier ne correspond pas à son extension ».
3. Déposer quatre fichiers sur la même tâche : le quatrième est refusé.
4. Supprimer un fichier : l'espace utilisé diminue.

### Non vérifié
Dialogue réel avec clamd et détection réelle (procédure ci-dessus à exécuter en recette) ; deux dépôts strictement simultanés ; dépôt de 5 Mo par une vraie connexion mobile ; nom de fichier accentué au téléchargement dans chaque navigateur ; rendu de la liste de fichiers et de l'écran des signalements (360 px, clavier, lecteur d'écran) ; comportement quand le disque est plein.

## Lot 15 — catalogue éditorial des opportunités
Cas exécutés sur MySQL hako_ra_campus_test, données fictives (organisme fictif, adresses en example.test), horloge contrôlée. L'administration éditoriale exige la dérogation locale de second facteur ; le catalogue public et le signalement, non.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R31 | Publication bloquée sans source, organisateur, conditions ou vérification explicite | Dix-neuf saisies refusées (source ou organisateur vide, adresses javascript:, data:, ftp:, sans protocole, avec identifiants, avec espace ou balise, catégories et codes invalides) ; brouillon sans conditions non publiable ; conditions trop courtes refusées ; sans case de vérification : refus ; publication sans vérification refusée par MySQL (3819) ; après vérification : date et auteur enregistrés ; cinq révisions dont « envoi » et « vérification » distinctes, même auteur ; révision non modifiable ; balises retirées du titre et du résumé ; page publique avec source et date de vérification, sans nom d'éditeur | réussi |
| Visibilité | Visiteurs : fiches publiées seulement ; administration : éditeur seulement | Brouillon, à vérifier, suspendue, archivée : 404 pour visiteur, apprenant, enseignant, administrateurs, et même pour l'éditeur par l'adresse publique ; liste publique limitée à la fiche publiée ; administration : visiteur renvoyé à la connexion, cinq profils en 403 sur sept routes (dont éditeur hors contexte), éditeur sans second facteur en 403 ; suspension sans motif refusée ; reprise sans vérification refusée ; fiche archivée non modifiable ; un événement par changement d'état | réussi |
| R34 | Date seule, clôture continue, date non communiquée : précision conservée | Date et heure sans fuseau, fuseau sans heure, fuseau inconnu, date mal formée : refusés ; date seule : aucune heure ni fuseau stockés même si envoyés ; affichage « 10/12/2026 (heure non communiquée) » avec la règle ; états ouverte / dernier jour / close aux bornes exactes (9 déc. 10 h UTC, 11 déc. 12 h UTC) ; clôture continue et sans date : jamais closes, jamais archivées (commande rejouée en 2030) ; formulaire éditeur rendant la même précision ; incohérences refusées par MySQL (3819) | réussi |
| R35 | Fuseau différent d'Abidjan : conversion et archivage au bon instant | 23 h 59 à Paris en décembre = 22 h 59 UTC ; à New York = 4 h 59 UTC le lendemain ; à Abidjan = 23 h 59 UTC ; Paris en juin = 21 h 59 UTC ; affichage dans le fuseau d'origine ; liste triée par instant réel ; une minute avant : ouverte, non archivée ; à l'instant : close et archivée ; chaque fuseau archivé à son propre instant | réussi |
| R36 | Nouvelle édition séparée ; doublons refusés | Même titre (casse, accents, espaces différents), même organisateur, même campagne : refusé ; même source (avec ou sans www, barre finale) : refusé sauf confirmation ; doublon forcé refusé par MySQL (1062) ; nouvelle édition 2027 : autre fiche, brouillon, sans date ni vérification ; fiche 2026 toujours publiée, historique inchangé ; liens entre éditions ; publique seulement après sa propre vérification | réussi |
| R37 | Prolongation : ancienne date conservée, rappels invalidés, sans doublon | Modification sans vérification refusée ; avec : nouvelle date, ancienne date affichée, révision « changement de date » avec l'ancienne ; rappel programmé annulé ; un événement ; rejeu : ni révision ni événement supplémentaire ; version dépassée : 409 ; modification sans changement de date : rien d'autre ne bouge ; pas d'archivage à l'ancienne date | réussi (partiel pour R37 : pas encore de favoris) |
| R38 | Signalement, file éditoriale, suspension ; pas de fuite | Visiteur renvoyé à la connexion ; motifs invalides, précision manquante ou trop longue, fiche non publiée : refusés ; second envoi : un seul signalement ; fiche toujours publique après signalement ; file en 403 pour non-éditeurs ; aucun nom ni courriel de signalant dans la page de la file ; prise en charge, commentaire interne, décision par un second éditeur ; fiche suspendue : 404 public, hors liste, rappel annulé, événement, révision attribuée ; historique à quatre étapes non supprimable ; journal sans motif ni commentaire ; éditeur ne pouvant traiter son propre signalement (403) ; classement sans suite : fiche conservée, le signalant voit l'issue sans commentaire ni nom d'éditeur ; sixième signalement du jour : 429 ; incohérences refusées par MySQL (3819) | réussi (partiel pour R38 : information des favoris au lot 18) |
| C15-01 | Fixtures explicitement fictives | Quatre fiches, toutes « [FICTIF] », organisme fictif, example.test ; chargement rejoué sans doublon ; trois visibles du public, le brouillon non ; entrée « Opportunités » active dans la navigation | réussi |

### Résultats exacts
- Migration 2026_11_17_230000_create_opportunity_tables : appliquée sur test puis dev, dix-neuf migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/OpportunityTest.php (XDEBUG_MODE=off) : 8 tests réussis, 675 assertions, 5 102 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 186 tests réussis, 1 ignoré (mesure sur demande), 5 252 assertions, 65 896 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès, 444 ms.
- php artisan db:seed --class=OpportunityDemoSeeder sur hako_ra_campus_dev : 4 fiches, 3 publiées, toutes préfixées « [FICTIF] ».
- Passages intermédiaires : 4 tests sur 8 avant l'écriture des écrans, puis 6 (une erreur de type TypeScript dans le formulaire, deux attentes de test inexactes), puis 8. Aucune règle de l'application n'a été modifiée pour faire passer un test.
- NavigationTest : deux tests réécrits. L'un vérifiait qu'un écran provisoire s'affichait (il n'en reste aucun) ; l'autre supposait l'absence de la route d'administration du catalogue.

### Parcours manuel proposé — données fictives
1. Sans connexion, ouvrir /opportunites : trois fiches « [FICTIF] » ; ouvrir celle « avec date seule » et lire la règle sous la date.
2. Ouvrir la fiche « avec date et heure » : l'heure de Paris est affichée, puis l'équivalent sur votre appareil.
3. Se connecter avec un compte individuel fictif, signaler une fiche (motif « Lien cassé ») : le message confirme que l'identité n'est pas publiée.
4. Parcours éditeur (non ouvrable à la main tant que les comptes fictifs n'ont pas de mot de passe connu, et réservé au local) : choisir le contexte « Plateforme — Éditeur du catalogue », ouvrir /administration/catalogue, créer un brouillon, l'envoyer en vérification, tenter de publier sans cocher la vérification (refus), puis publier ; traiter le signalement dans /administration/catalogue/signalements.

### Non vérifié
Rendu des cinq écrans (360 px, clavier, zoom, formulaire long) ; affichage de l'heure locale du lecteur dans chaque navigateur ; information réelle des détenteurs de favoris après suspension ou prolongation (lots 17 et 18) ; recherche et filtres (lot 16) ; comportement avec plusieurs centaines de fiches ; exécution réelle de l'archivage par le planificateur ; saisie par un véritable éditeur de fiches réelles.

## Lot 16 — recherche et correspondances
Cas exécutés sur MySQL hako_ra_campus_test, sept fiches fictives créées par les routes de l'éditeur (cinq publiées, un brouillon, une suspendue), horloge contrôlée. La consultation, la recherche et les critères déclarés n'exigent aucun rôle.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R32 | Filtres combinés, recherche et tris conformes ; ni brouillon ni fiche suspendue | Dix filtres pris un à un ; combinaisons niveau + pays, + domaine, + modalité (une fiche, puis aucune) et cinq filtres à la fois ; « tous niveaux » répond à tout niveau ; recherche sans casse ni accents sur titre, organisateur, domaine, tous les mots exigés ; jokers et tentative d'injection traités comme du texte, table intacte ; pertinence, publication, clôture dans l'ordre attendu ; pertinence sans mot = clôture, affichée comme telle ; filtres et valeurs proposées renvoyés ; brouillon et fiche suspendue absents de huit recherches et de leur contenu | réussi |
| Paramètres et pagination | Paramètres validés ; pagination correcte | Seize paramètres invalides (valeurs hors liste, codes mal formés, page 0, texte, 1001, recherche trop longue, tableaux) : erreur sur le champ et 422 VALIDATION_FAILED en JSON ; paramètres inconnus (statut=draft, institution_id) ignorés sans effet ; 23 fiches : 20 puis 3, sans doublon entre pages, filtres conservés dans le lien suivant, page au-delà de la fin vide | réussi |
| R34 | Date inconnue et clôture continue visibles, jamais closes | « Dans les 30 jours » : trois fiches datées ; « sans date limite connue » : les deux autres ; précision d'origine identique dans la liste et sur la fiche ; à la clôture : la fiche quitte la liste ouverte, apparaît dans « clôturées » (état clos), avant comme après archivage (alors marquée archivée) ; en 2030, les deux fiches sans date sont toujours ouvertes | réussi |
| R33 | Profil incomplet : « à vérifier », aucune inéligibilité inventée | Visiteur et compte sans critère : aucune comparaison, même pour un apprenant rattaché à des établissements ; sept saisies invalides refusées ; version dépassée : 409 ; niveau seul déclaré : niveau « correspond », les quatre autres « à vérifier », ensemble « à vérifier » ; explication citant la fiche et l'absence de déclaration ; niveau contraire : « non satisfait » avec les deux valeurs ; nationalité et résidence jamais confondues (quatre combinaisons) ; domaine sans intitulé commun : « à vérifier » ; tout concorde : « correspond », avec la mention indicative ; critères invisibles de quatre autres comptes, absents du journal et des événements ; fiche suspendue jamais comparée ; tout effacer supprime la comparaison ; niveau interdit refusé par MySQL (3819) | réussi |
| R40 | Ouverture externe sans effet | Fiche montrant financement, frais, précision de l'échéance, organisateur, date de vérification, domaines de destination ; lien de candidature strictement égal à l'adresse saisie ; cinq routes « opportunites » seulement, aucune route de clic, de sortie ou de suivi ; adresses de clic inventées : 404 ; consultation de la liste et de la fiche, connecté ou non : dix tables au compte inchangé, aucune date de modification changée ; fiche sans lien : rien d'inventé ; liens sans référent | réussi (partiel pour R40 : démarches au lot 17) |

### Résultats exacts
- Migration 2026_11_20_090000_add_opportunity_search_and_profiles : appliquée sur test puis dev, vingt migrations Ran sur chaque base ; les quatre fiches fictives de développement ont reçu leurs domaines normalisés.
- php artisan test --compact tests/Feature/OpportunitySearchTest.php tests/Feature/OpportunityTest.php (XDEBUG_MODE=off) : 13 tests réussis, 1 674 assertions, 11 473 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 191 tests réussis, 1 ignoré (mesure sur demande), 6 251 assertions, 92 860 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès.
- Avant d'écrire le lot, la suite complète a été rejouée : 186 réussis, 1 ignoré.
- Passages intermédiaires du fichier de tests : 1 sur 5, puis 3, puis 5. Quatre attentes de test étaient fausses (le mot « or » d'une tentative d'injection est légitimement recherché ; un ordre de clés ; une fiche de niveau différent du niveau déclaré ; le nom de l'utilisateur figure sur sa propre page). Un défaut réel a été corrigé dans l'application : un filtre « domaine » sans lettre ni chiffre était ignoré en silence ; il ne renvoie désormais aucune fiche.
- OpportunityTest (lot 15) : cinq attentes adaptées à la décision du prompt 16 d'identifier les fiches suspendues ou expirées au lieu de les rendre introuvables.

### Parcours manuel proposé — données fictives
1. Sans connexion, ouvrir /opportunites : trois fiches « [FICTIF] ». Filtrer par catégorie « Concours » puis « Réinitialiser les filtres ».
2. Chercher « exemple », choisir le tri « Pertinence » : la règle de calcul s'affiche sous le formulaire.
3. Choisir « Sans date limite connue » : la fiche à clôture continue apparaît seule.
4. Se connecter avec un compte individuel fictif, ouvrir « Déclarer mes critères », ne saisir que le niveau « Master » : sur chaque fiche, la comparaison indique ce qui correspond et ce qui reste à vérifier.
5. Ouvrir la fiche « avec date et heure » : lire le domaine de destination sur le bouton de la source avant de cliquer ; le lien s'ouvre dans un nouvel onglet.

### Non vérifié
Rendu du formulaire de filtres et de la comparaison (360 px, clavier, lecteur d'écran) ; absence de référent constatée dans un vrai navigateur ; tenue de la recherche avec plusieurs milliers de fiches ; pertinence jugée par de vrais utilisateurs ; saisie des codes de pays par des utilisateurs non avertis.

## Lot 17 — favoris et démarches privées
Cas exécutés sur MySQL hako_ra_campus_test, fiches fictives créées par les routes de l'éditeur, horloge contrôlée. Favoris et démarches n'exigent aucun rôle.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R39 | Même favori depuis deux appareils : un seul ; retrait idempotent | Trois ajouts depuis des sessions distinctes du même compte : un favori, « déjà présent » signalé ; insertion directe en double refusée par MySQL (1062) ; même état vu depuis trois contextes ; autre compte : son propre favori ; retrait sur un appareil visible sur l'autre ; retrait répété, d'un favori inexistant, d'une fiche inconnue ou d'un brouillon : même réponse sans erreur ; ajout sur brouillon ou fiche inconnue : 404 ; visiteur renvoyé à la connexion | réussi |
| R37, R38 | Favoris classés ; fiche suspendue ou prolongée : information utile | Cinq favoris : deux « bientôt clos », trois « ouverts » dont une fiche sans date ; après suspension, prolongation et clôture : listes recalculées ; favori suspendu réduit à titre, organisateur, campagne ; favori prolongé montrant nouvelle et ancienne dates ; fiche suspendue consultable par son suiveur sans contenu, retrait possible, ajout impossible ; événements de suspension, de changement de date et d'archivage présents, sans aucune personne ; destinataires calculés : démarche en cours oui, favori retiré non, démarche abandonnée non | réussi (partiel pour R37 et R38 : pas d'alerte avant le lot 18) |
| R40 | Démarche modifiée seulement par choix explicite | Démarche impossible sur brouillon ou fiche inconnue ; cinq saisies invalides refusées ; trois séries de consultations (fiche, liste, suivi) et quatre adresses de clic inventées : état, version, date de modification et date de choix inchangés ; lien de candidature sans aucun identifiant ; aucune route de clic ; les sept états choisis un à un, chacun daté ; version dépassée : 409 ; rejeu identique sans effet ; état inconnu et seconde démarche refusés par MySQL (3819, 1062) | réussi |
| D17-01 | Note, liste de pièces, suppression : texte seulement, réellement supprimable | Pas de liste sans démarche ; ligne vide ou trop longue refusée ; ajout rejoué : une ligne ; cocher deux fois sans effet ; 21e ligne refusée ; aucune route de dépôt de fichier sur une démarche, table des pièces jointes sans colonne correspondante ; note supprimée : nulle en base et absente de tous les écrans, échéance et liste conservées ; suppression de la démarche rejouable, liste supprimée, favori conservé | réussi |
| R41 | Aucun autre compte ne lit ni ne modifie une démarche | Autre apprenant, enseignant, administrations A et B, administrateur de la plateforme, éditeur, tiers : fiche et suivi sans démarche, favori, note ni pièce d'autrui ; écriture : 409 ou 404 ou sans effet sur chacune des routes ; policy refusée ; écrans de l'éditeur et des administrations sans aucune trace ; aucune route par identifiant de démarche ou de favori ; visiteur renvoyé à la connexion sur les huit routes ; rien de changé chez le propriétaire ; journal et événements vides de tout contenu ; compte suspendu sans accès | réussi |
| D17-02 | Après archivage : résumé daté minimal, pas de copie de la source | Résumé à huit champs dès la création ; titre corrigé puis clôture : résumé figé à l'archivage avec le dernier titre et la date du relevé ; ni conditions, ni résumé, ni adresse complète, ni lien de candidature, ni motif interne en base ; démarche encore lisible et modifiable, résumé inchangé ensuite ; favori dans « expirés » ; fiche suspendue : démarche existante accessible avec résumé figé à la suspension, aucune nouvelle démarche possible | réussi |

### Résultats exacts
- Migration 2026_11_20_140000_create_opportunity_follow_up_tables : appliquée sur test puis dev, vingt et une migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/OpportunityFollowUpTest.php (XDEBUG_MODE=off) : 6 tests réussis, 614 assertions, 7 064 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 197 tests réussis, 1 ignoré (mesure sur demande), 6 865 assertions, 98 323 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès.
- Passage intermédiaire : 4 tests sur 6. Une attente de test supposait un ordre de clés dans une colonne JSON, que MySQL réordonne. L'autre échec était un refus 429 réel : le test enchaînait plus de trente requêtes dans la même minute, et le limiteur de débit compte par compte, toutes routes confondues (vérifié dans le code de Laravel : la clé ne contient pas la route). Les deux routes de démarche à plafond bas ont été alignées sur 120 par minute et le test avance l'horloge ; le constat général est porté à l'arbitrage A43.
- Un test du lot 16, qui fige la liste des routes « opportunites », a reçu les huit nouvelles routes.

### Parcours manuel proposé — données fictives
1. Se connecter avec un compte individuel fictif, ouvrir une fiche « [FICTIF] », cliquer « Ajouter à mes favoris », puis ouvrir « Tout mon suivi » : la fiche figure dans « Ouverts » ou « Bientôt clos ».
2. Sur la fiche, section « Ma démarche » : choisir « Dossier en préparation », saisir une échéance et une note, enregistrer. Ajouter deux pièces à préparer, en cocher une.
3. Cliquer « Candidater sur le site de l'organisateur » (si la fiche a ce lien) ou « Ouvrir la source officielle », revenir : l'état est toujours « Dossier en préparation ».
4. Cliquer « Supprimer la note », puis « Supprimer la démarche » (confirmation) : le favori reste.
5. Se connecter sur un second navigateur avec le même compte : le favori y figure aussi.

### Non vérifié
Rendu de « Mon suivi » et du formulaire de démarche (360 px, clavier, lecteur d'écran) ; deux navigateurs réels simultanés ; alerte effective après suspension ou prolongation (lot 18) ; rappel d'échéance personnelle (lot 18) ; suppression des favoris et démarches à la suppression du compte (lot 22).

## Lot 18 — recherches enregistrées et alertes
Cas exécutés sur MySQL hako_ra_campus_test, fiches fictives, horloge contrôlée, cycle de rappels exécuté tel que la file le lance. Courriel simulé ; aucun envoi réel.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| R43 | Chaque nouvelle fiche annoncée une fois ; mise à jour importante identifiée ; rejeu sans doublon | Enregistrement : visiteur renvoyé à la connexion, filtres vides ou invalides refusés, mêmes filtres = même recherche ; fiche déjà au catalogue non annoncée ; le lendemain : une synthèse, une fiche, ni brouillon ni fiche suspendue ni fiche hors critères ; cycle rejoué quatre fois sur deux jours : rien de plus ; doublons refusés par MySQL (1062) ; date prolongée + nouvelle fiche : une synthèse « 1 nouvelle, 1 mise à jour » avec l'ancienne date ; résumé corrigé sur une ancienne fiche : non annoncé ; page de l'alerte avec critères, lien vers la source, fiche suspendue depuis sans lien ; fiche suspendue dès l'origine jamais annoncée, puis annoncée une fois après sa reprise | réussi |
| R42 | Désabonnement effectif ; favoris conservés | Quatre autres comptes : 404 en lecture et en modification, suppression sans effet ; visiteur renvoyé à la connexion sur les quatre routes ; arrêt : synthèse en attente retirée, version dépassée 409, rejeu sans effet ; trois cycles et une publication pendant l'arrêt : aucune synthèse ; favori intact et toujours listé ; reprise : la fiche publiée pendant l'arrêt n'est pas rattrapée, la suivante est annoncée ; recherche hebdomadaire : rien avant sept jours, puis une synthèse regroupée ; suppression rejouable, annonces supprimées, favoris conservés ; onzième recherche refusée | réussi |
| A18-01 | Synthèses plafonnées, canaux choisis, retrait si plus rien n'est publié | 25 fiches publiées d'un coup : 20 dans la première synthèse, 5 le lendemain, rien ensuite ; courriel seulement pour la recherche qui le demande, différé à la fin de la plage silencieuse, avec un titre neutre sans le mot recherché ; catégorie désactivée : synthèse annulée ; synthèse programmée dont la seule fiche est suspendue avant la remise : annulée ; compte suspendu : plus aucune synthèse | réussi |
| R37 | Rappels sur échéance précise seulement ; replanification après prolongation | Échéance exacte : rien à J-7 moins une minute, rappel à l'instant ; démarche réglée à 3 jours, catégorie désactivée, démarche abandonnée : pas de rappel à J-7 ; prolongation : rappel remis annulé, deux suiveurs prévenus avec nouvelle et ancienne dates, aucun rappel aux anciennes dates, nouveaux rappels à J-7, J-3 et J-1 de la nouvelle date, une fois chacun ; date seule : rappels à 8 h locale (Abidjan) et à 8 h de Tokyo (23 h UTC la veille), texte « heure non communiquée » ; clôture continue et date inconnue : aucun rappel ; favori retiré ou fiche suspendue avant la remise : annulé ; réglages 0 à 30 jours validés, 31 refusé y compris par MySQL (3819) | réussi |
| R38 | Suspension : suiveurs prévenus, jamais présentée comme offre | Favori et démarche en cours : un avis chacun, renvoyant à « Mon suivi » ; démarche close, simple abonné à une recherche, éditeur : rien ; aucun motif interne ni adresse de source dans les notifications ; un courriel au titre neutre ; fiche absente de la liste ouverte, de toute synthèse, sans rappel d'échéance à J-7 ; reprise : suiveurs prévenus, rappel J-1 de nouveau émis ; suspension découverte après plus de 48 h : pas d'avis ; suspension puis reprise avant la remise : avis annulé | réussi |

### Résultats exacts
- Migration 2026_11_20_180000_create_saved_search_tables : appliquée sur test puis dev, vingt-deux migrations Ran sur chaque base.
- php artisan test --compact tests/Feature/OpportunityAlertTest.php (XDEBUG_MODE=off) : 5 tests réussis, 379 assertions, 6 664 ms.
- php artisan test --compact (XDEBUG_MODE=off) : 202 tests réussis, 1 ignoré (mesure sur demande), 7 244 assertions, 93 237 ms.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès.
- Avant d'écrire le lot, la suite complète a été rejouée : 197 réussis, 1 ignoré.
- Passages intermédiaires du fichier de tests : 0 sur 5, puis 3, 4, 5. Deux défauts réels corrigés dans l'application : une erreur 500 quand les filtres envoyés ne contenaient que la clôture ou le tri (désormais un refus 422), et un ordre des critères affichés qui dépendait de MySQL (désormais fixe). Les autres échecs venaient du scénario de test : fiche créée au même instant que la recherche, décomptes incluant des rappels annulés, cycle non lancé à l'instant d'un rappel.
- Incident de test récurrent : ApplicationFoundationTest, qui s'exécute hors transaction, a laissé quatre positions de reprise dans la base de test lors d'une exécution en échec. Base vérifiée, lignes supprimées avant de poursuivre.

### Parcours manuel proposé — données fictives
1. Lancer la file et le planificateur (composer run dev). Se connecter avec un compte individuel fictif.
2. Sur /opportunites, filtrer par catégorie puis « Enregistrer cette recherche et être prévenu ». Ouvrir « Mes alertes » : la recherche est active, « pas encore vérifiée ».
3. Aucune synthèse n'arrive pour les fiches déjà présentes : c'est attendu. Une synthèse n'apparaîtra que si un éditeur publie une nouvelle fiche correspondante (parcours éditeur non ouvrable à la main pour l'instant), le lendemain au plus tôt.
4. Cliquer « Arrêter les alertes » : l'état passe à « Alertes arrêtées » ; vérifier dans « Mon suivi » que les favoris sont toujours là.
5. Dans /notifications/preferences, régler les deux rappels des fiches suivies (par exemple 3 et 0).

### Non vérifié
Envoi réel d'un courriel ou d'un signal Web Push pour ces alertes ; réception d'une synthèse dans un parcours manuel complet (il faut un éditeur) ; rendu des pages d'alertes (360 px, clavier) ; tenue en charge avec de nombreux suiveurs et recherches ; comportement au changement d'heure d'été pour les rappels sur date seule.

## Lot 19 — PWA et cache de lecture
Trois niveaux de preuve, tous exécutés le 22 novembre 2026 : tests PHPUnit sur MySQL hako_ra_campus_test ; tests JavaScript sous Node 24.12 (service worker réel chargé dans un environnement simulé, règles de conservation exécutées telles quelles) ; contrôle dans un vrai navigateur, Edge 157 sans interface, piloté par son protocole de débogage contre un serveur local, avec un profil temporaire et des données fictives.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| P19-01 (PwaTest) | Manifest, icônes, coque publique ; pas de promesse d'application native | Manifest valide, « ce n'est pas une application native » dans sa description, trois icônes aux dimensions annoncées dont une « maskable », aucune adresse privée ; lien du manifest dans la page d'accueil ; ancien service worker du push supprimé ; page et script hors ligne sans donnée, sans appel réseau, sans jeton ; page /hors-ligne publique, durée de conservation paramétrable | réussi |
| P19-02 (Node) | Le service worker ne conserve que du public | Préchargement des cinq fichiers de la coque seulement ; vingt adresses privées (pages, administration, export, pièce jointe, instantané, connexion, réinitialisation, invitation) : navigation servie par le réseau sans aucune écriture en cache, requêtes Inertia et de données non interceptées, écritures non interceptées, autre origine non interceptée ; seuls les fichiers de /build/assets/ sont conservés ; activation : cinq caches étrangers supprimés, dont un cache « privé » et un cache de pages ; installation sans prise de contrôle forcée, mise à jour seulement sur demande ; push : message générique, contenu jamais lu | réussi |
| R47 (PwaTest + Node) | Deux comptes sur le même appareil : aucun cache privé partagé | Onze réponses à un compte connecté (pages, instantané, export, écran d'enseignant) et une requête Inertia : « no-store, private » ; clé de compte opaque, stable, différente d'un compte à l'autre, absente pour un visiteur ; instantané refusé sans session et pour un compte suspendu ; règles : appareil partagé par défaut, copie du premier compte effacée au changement de compte même hors connexion, choix « personnel » non transmis, instantané d'un autre compte jamais enregistré, expiration à 7 jours paramétrable | réussi |
| P19-03 (PwaTest) | Instantané limité à ce que le compte peut lire, sur 14 jours ; droits réévalués | Cours personnel et cours publié présents, cours à plus de 14 jours et brouillon de planning absents ; tâche ouverte seulement ; note personnelle ; favori ; onze termes interdits absents (adresse électronique, jeton, identifiants, pièces jointes, adresse de la source…) ; autre compte : instantané vide ; enseignant : le cours qu'il donne ; administration : aucune donnée de membre ; après retrait de l'appartenance et suspension de la fiche : cours institutionnel disparu, favori réduit à son titre ; la fenêtre avance avec le temps | réussi |
| Edge 157 — en ligne | Service worker, installation, caches | Service worker actif ; manifest lié et lisible ; aucun critère d'installation manquant selon le navigateur ; 14 entrées en cache, toutes de la coque ou de /build/assets/, aucune page ; aucun stockage privé créé par défaut | réussi |
| Edge 157 — R44, réseau coupé | Page neutre, sans donnée | Serveur local arrêté : /planning, /notes et /administration/etablissement affichent la page « Hors connexion », avec « Aucune donnée n'est enregistrée sur cet appareil » | réussi |
| Edge 157 — appareil personnel | Lecture seule, datée, périssable | Copie fictive lue hors connexion : planning, tâche, note, favori et fiche consultée affichés avec « Dernière synchronisation », « potentiellement périmées », « Lecture seule » ; aucun formulaire ni lien ; copie de 8 jours : effacée à l'ouverture (base et réglage supprimés) ; mode partagé avec une copie restante : rien d'affiché, tout effacé ; bouton d'effacement fonctionnel ; retour du réseau : page d'accueil servie par le serveur | réussi |
| Edge 157 — R55 | Mobile et zoom sur les écrans du lot | /hors-ligne à 360 px et à 320 px (équivalent d'un zoom à 200 %), page hors ligne à 360 px : pas de défilement horizontal ; boutons présents de 45 px de haut ; bouton « Réessayer » atteignable par le focus | réussi (partiel pour R55) |

### Résultats exacts
- php artisan test --compact (XDEBUG_MODE=off) : 206 tests réussis, 1 ignoré (mesure sur demande), 7 401 assertions, 106 143 ms. PwaTest : 4 tests, dont un qui lance les tests JavaScript.
- npm run test:js (node --test tests/js/*.test.mjs) : 13 tests réussis, 0 échec.
- node tests/browser/pwa-edge.mjs : 22 contrôles réussis, 0 échec, navigateur Edg/157.0.4341.60 ; sortie complète dans docs/preuves/lot19-edge.txt.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : codes 0 ; npm run build : succès. Aucune migration.
- Passages intermédiaires. ESLint a refusé deux fois l'écran /hors-ligne (lecture de l'horloge et du stockage pendant le rendu) : réécrit avec une source externe. Tests JavaScript : deux comparaisons de tableaux entre contextes d'exécution corrigées dans le test. PwaTest : une assertion sans valeur retirée, une attente corrigée (un compte suspendu est renvoyé à la connexion, règle du lot 04). Contrôle Edge : quatre passages ; les échecs venaient du script (sous Windows, arrêter « php artisan serve » ou Edge ne ferme pas leurs processus enfants ; une valeur vide mal comparée ; une mesure faite sur une page sans bouton). Aucun défaut applicatif n'a été trouvé par le navigateur.
- Incident d'outillage : les premiers passages ont laissé tourner un serveur PHP local (port 8123) et deux navigateurs Edge sans interface (port 9123), tous lancés par ce script. Ils ont été identifiés par leur ligne de commande puis arrêtés ; le script ferme désormais lui-même tout ce qu'il lance et refuse de démarrer si ces ports sont occupés. Vérifié : aucun processus restant après le dernier passage.

### Parcours manuel proposé — données fictives
1. Lancer composer run dev et ouvrir Campus dans Chrome ou Edge. Ouvrir « Hors connexion et installation » (/hors-ligne) : le navigateur propose « Installer le raccourci Campus » ; refuser, puis vérifier que tout fonctionne comme avant.
2. Sans rien activer, couper le réseau (mode avion ou arrêt du serveur) et recharger : page « Vous êtes hors connexion », sans aucune donnée.
3. Rétablir le réseau, se connecter avec un compte individuel fictif, créer une tâche, puis sur /hors-ligne choisir « Cet appareil est personnel ». Couper le réseau et recharger : la tâche apparaît, avec la date de synchronisation.
4. Rétablir le réseau, ouvrir le profil, se déconnecter, couper le réseau et recharger : la page est de nouveau neutre.
5. À refaire sur un téléphone Android (Chrome) et un iPhone (Safari, « Sur l'écran d'accueil »).

### Non vérifié
Firefox, Safari, navigateurs mobiles ; installation réelle sur un téléphone ; synchronisation, déconnexion et changement de compte réels dans un navigateur (les étapes 3 et 4 du parcours ci-dessus) ; bandeau de mise à jour entre deux versions du service worker ; comportement quand le navigateur efface lui-même le stockage ; navigation clavier complète et zoom natif.

## Lot 20 — synchronisation des brouillons personnels
Trois niveaux de preuve, exécutés le 22 novembre 2026 avec des données fictives : tests PHPUnit sur MySQL hako_ra_campus_test ; tests JavaScript sous Node 24.12, qui exécutent le vrai code de l'appareil (public/offline-core.js) avec un stockage en mémoire et un serveur simulé ; contrôle dans Edge 158 sans interface, sans compte connecté. Un test relie les deux moitiés : les opérations écrites par le vrai code de l'appareil sont envoyées telles quelles au vrai serveur.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| S20-01 (SyncTest) | Point d'entrée versionné, sous session et CSRF | Route POST synchronisation/v1/operations dans le groupe « web », compte connecté et vérifié ; sans session : 401 ; compte suspendu : rien n'est créé | réussi |
| S20-02 (SyncTest + Node) | Même empreinte des deux côtés | Contenu fictif avec accents, émoji, guillemets, retours à la ligne, barre oblique et séparateur de ligne : même empreinte en PHP et en JavaScript ; empreinte ne correspondant pas au contenu : 422, rien d'écrit | réussi |
| R57 (SyncTest) | Envoi répété : une création, même accusé | Création hors connexion envoyée quatre fois : une tâche, un accusé enregistré, accusé rejoué identique ; même clé et autre contenu : 409 IDEMPOTENCY_KEY_REUSED, rien de créé, premier accusé intact ; même clé chez un autre compte : sa propre tâche ; formulaire en ligne interrompu puis repris par la synchronisation avec la même clé : une tâche ; le registre ne contient aucun texte saisi | réussi |
| R45 (SyncTest) | Deux appareils, une tâche | Versions connues égales à 1 ; premier envoi : version 2 ; second : 409 VERSION_CONFLICT avec la version actuelle, tâche inchangée ; le même envoi rejoué reste en conflit avec l'état relu ; décision explicite sur la version 3 : version 4 | réussi |
| R23 (SyncTest) | Un retour par personne et par cours | Retour envoyé quatre fois : un retour, un événement ; second appareil : 409 avec le retour du propriétaire, retour inchangé ; brouillon vide ou champ inattendu (« action ») : refusé, refus rejoué à l'identique | réussi |
| R48 (SyncTest) | Droit retiré avant l'envoi | Appartenance retirée : 422 PARENT_UNAVAILABLE, aucun retour créé, réponse sans le titre du cours ; cours d'un autre groupe ou inconnu : même refus ; tâche d'un autre compte, devoir collectif, tâche supprimée : refusés et inchangés ; contexte « institution », type inconnu, champ d'établissement glissé : refusés ; brouillon d'un autre compte : 403 ACCOUNT_MISMATCH, rien d'enregistré ; conflit sur un retour existant : seuls les six champs du retour sont rendus | réussi |
| S20-03 (SyncTest) | Instantané version 2 | Tâche personnelle avec version et champs modifiables ; cours terminé sans retour proposé à partir de la version 0 ; après un retour : état et version seulement, jamais son texte ; cours enseigné : aucun retour proposé | réussi |
| S20-04 (SyncTest) | Le vrai code de l'appareil et le vrai serveur | Trois opérations écrites par public/offline-core.js sous Node (création, modification, retour) : empreintes égales à celles recalculées par le serveur, appliquées, puis rejouées sans effet ; contenus enregistrés conformes | réussi |
| S20-05 (SyncTest) | Purge des accusés | Accusé de 91 jours supprimé, accusé de 11 jours gardé ; opération rejouée après la purge : aucune seconde tâche | réussi |
| R57, R23 (Node) | Réseau oscillant | Réseau coupé, puis réponse perdue alors que le serveur a enregistré, puis erreur du serveur, puis réponse reçue : quatre envois strictement identiques, une seule tâche, état « en attente » puis « synchronisé » | réussi |
| S20-06 (Node) | Un brouillon parti ne change plus | Brouillon jamais envoyé : remplacé sur place ; brouillon déjà tenté : inchangé, la saisie suivante part après lui sur la tâche et la version qu'il a produites | réussi |
| R45 (Node) | Conflit sans choix automatique | Brouillon gardé avec ses trois différences ; resynchroniser ne le renvoie pas ; « appliquer mon brouillon » : nouvelle opération fondée sur la version vue ; « garder la version enregistrée » : brouillon abandonné, rien ne part | réussi |
| R48 (Node) | Refus | Brouillon gardé, pas rejoué en boucle ; nouvelle tentative seulement sur demande ; envoi dépendant d'une création refusée : non envoyé | réussi |
| S20-07 (Node) | Session, débit, autre compte | Jeton périmé : renouvelé une fois puis même opération renvoyée ; session fermée, redirection, trop de demandes, refus global, autre compte : arrêt immédiat, tout reste en attente ; brouillon d'un autre compte jamais listé ni envoyé | réussi |
| R46 (Node) | Ce qui serait perdu | Liste des brouillons non synchronisés exacte ; envoi synchronisé retiré après un jour, jamais un brouillon ; abandon explicite | réussi |
| R56 (Node) | Schéma local et mise à jour | Version 1 vers 2 : seule la file est créée, rien n'est supprimé ; repli sur une base déjà migrée ; brouillon d'une version future gardé et non envoyé ; service worker sans accès au stockage privé ; aucune synchronisation en arrière-plan | réussi |
| Edge 158 — R56 | Appareil du lot 19 mis à jour | Base locale créée en version 1 avec une copie fictive : passée en version 2, copie toujours affichée, file créée | réussi |
| Edge 158 — écriture hors connexion | Brouillons sans réseau | Serveur arrêté : nouvelle tâche, modification d'une tâche, retour sur un cours, tous « Enregistré sur cet appareil » ; identifiant, compte, contexte, type, version connue présents, rien n'est parti ; contenu nettoyé ; titre vide refusé à l'écran ; brouillons présents après rechargement ; brouillon modifié remplacé sur place ; aucun lien, aucun envoi de fichier, notes sans formulaire, devoir d'établissement non modifiable | réussi |
| Edge 158 — R55 | Page hors ligne en mobile | 360 px : pas de défilement horizontal, contrôles d'au moins 44 px, chaque champ a son libellé | réussi (partiel pour R55) |
| Edge 158 — R46 | Rien n'est effacé sans décision | Copie périmée avec brouillons : copie effacée, trois brouillons gardés ; « Effacer » : avertissement, refus sans perte ; abandon d'un brouillon : lui seul ; effacement confirmé : tout disparaît | réussi |
| Edge 158 — retour du réseau | Sans compte, rien ne part | Application en ligne sans compte : cœur commun chargé, brouillon ni envoyé ni effacé | réussi |

### Résultats exacts
- php artisan test --compact (XDEBUG_MODE=off) : 216 tests réussis, 1 ignoré (mesure sur demande), 7 582 assertions, 102 843 ms. SyncTest : 10 tests.
- npm run test:js : 22 tests réussis, 0 échec (9 nouveaux dans offline-core.test.mjs).
- node tests/browser/pwa-edge.mjs : 36 contrôles réussis, 0 échec, navigateur Edg/158.0.4370.33 (Edge s'est mis à jour depuis le lot 19) ; sortie complète dans docs/preuves/lot20-edge.txt. Les contrôles du lot 19 sont rejoués dans le même passage.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : sans erreur ; npm run build : succès.
- php artisan migrate (développement et test) : table sync_operations créée ; aucune donnée existante modifiée.
- Passages intermédiaires. SyncTest : un échec au premier passage, dû au test (suspension écrite par un champ protégé du modèle). Tests JavaScript : six échecs au premier passage, dus au test (deux appareils simulés produisaient les mêmes identifiants ; comparaisons entre contextes d'exécution), puis une règle changée dans le code : un refus global 403 ne marque plus un brouillon « refusé ». PwaTest : attentes de l'instantané mises à jour (version 2). Contrôle Edge : premier passage à 34 sur 36 ; un vrai défaut trouvé par le navigateur et corrigé (le libellé d'un brouillon gardait les espaces saisis autour du titre) et une attente mal écrite dans le script.
- Incident d'outillage : le lancement de commandes a été refusé par le système pendant quelques instants (erreur EPERM) ; aucune commande n'était partie, les corrections ont été faites par édition de fichier puis les vérifications relancées. Aucun processus de test restant après le dernier passage.

### Parcours manuel proposé — données fictives
1. Lancer composer run dev, se connecter avec un compte individuel fictif, ouvrir /hors-ligne et choisir « Cet appareil est personnel ».
2. Couper le réseau (mode avion ou arrêt du serveur) et recharger : sur la page hors ligne, créer une tâche, modifier une tâche existante, écrire un retour sur un cours terminé. Les trois apparaissent « Enregistré sur cet appareil ».
3. Rétablir le réseau et rouvrir Campus : le bandeau des brouillons disparaît, les tâches et le retour sont enregistrés une seule fois.
4. Conflit : ouvrir Campus avec le même compte dans deux navigateurs personnels, couper le réseau, modifier la même tâche des deux côtés, rétablir le réseau l'un après l'autre. Le second affiche « Conflit » avec les deux versions et attend un choix.
5. Déconnexion : écrire un brouillon hors connexion sur l'écran Tâches déjà ouvert, ouvrir le profil, choisir « Se déconnecter » : l'avertissement liste le brouillon et propose de synchroniser, d'abandonner ou de rester.
6. À refaire sur un téléphone Android (Chrome) et un iPhone (Safari).

### Non vérifié
Synchronisation réelle d'un compte connecté dans un navigateur (étapes 3 à 5 ci-dessus) ; panneau des brouillons, mise en file depuis Tâches et Retour après cours, et avertissement de déconnexion dans l'application ; deux appareils réels ; remplacement réel du service worker avec des envois en attente ; Firefox, Safari, Android, iPhone ; comportement quand le navigateur efface lui-même le stockage.

## Lot 21 — accueil et parcours complets
Preuves exécutées le 22 novembre 2026 avec des données fictives : tests PHPUnit sur MySQL hako_ra_campus_test ; tests JavaScript sous Node 24.12 ; deux parcours complets dans Edge 158 sans interface, contre un serveur local temporaire sur la base hako_ra_campus_dev, connecté aux comptes fictifs independant, admin-a, enseignant-a, apprenant-double et admin-b. Le mot de passe temporaire de ces comptes est aléatoire, jamais affiché, et les comptes sont refermés en fin de passage.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| H21-01 (HomeTest) | Visiteur : aucune donnée ; aide publique | Accueil sans bloc de données pour un visiteur, un compte non vérifié ; /aide publique ; sans adresse configurée ou avec une adresse invalide, aucun contact affiché ; adresse valide affichée | réussi |
| H21-02 (HomeTest) | Parcours individuel calculé sur les objets réels | Départ : seule l'étape « compte » faite, aucun cours, tâche, moyenne ni suggestion ; fuseau invalide refusé, visiteur renvoyé à la connexion ; après fuseau, matière, cours, tâche et note par les routes des écrans : six étapes faites, cours du jour, tâche et moyenne 14,00 avec couverture 1 sur 1 et provenance « personal » ; un favori termine le parcours ; un autre compte ne voit rien de cela | réussi |
| H21-03 (HomeTest) | Le jour suit le fuseau personnel | Abidjan : cours de 6 h seul, proposé pour un retour ; New York : les deux cours ; Denver : seul le cours de 0 h 30 UTC le lendemain | réussi |
| H21-04 (HomeTest) | Opportunités seulement d'après les critères déclarés | Sans critères : aucune suggestion ; avec niveau et domaine déclarés : seule la fiche compatible, libellée « 2 critère(s) déclaré(s) correspondent ; les autres sont à vérifier », avec l'avertissement d'éligibilité ; fiche suspendue : retirée | réussi |
| H21-05 (HomeTest) | Parcours d'établissement réservé à son administration, cloisonné | Absent en espace individuel, chez un apprenant, chez un enseignant ; départ : référentiels seuls ; après publication, signalement et décision : étapes correspondantes faites ; cours publié visible chez l'apprenant et l'enseignant avec la provenance « institutional », pas chez un tiers ; établissement B inchangé, aucun titre ni motif de A dans ses données | réussi |
| H21-06 (HomeTest) | Erreurs avec reprise | 404 et 403 : page d'erreur de Campus avec le bon code ; demande de données : JSON « RESOURCE_NOT_FOUND » ; sans session : retour à la connexion ; action refusée sur un écran sans affichage d'erreur : retour à l'écran avec le message ; écran des tâches : erreur gardée près du formulaire | réussi |
| H21-07 (HomeTest) | Commande d'accès aux comptes fictifs | Refus en environnement de test, en production, et en local hors base de développement ; aucun mot de passe modifié | réussi |
| H21-08 (HomeTest) | Ni route morte ni écran d'attente | Écran « à construire » supprimé ; chaque écran déclaré existe et est rendu par le serveur, et inversement ; chaque lien fixe des écrans (plus de vingt) mène à une route ; textes d'attente absents de l'accueil | réussi |
| H21-09 (Node) | Formats de date valides | Tous les jeux d'options de format de date des écrans sont acceptés par le moteur ; le cas fautif reste détecté | réussi |
| Edge — parcours individuel | Compte indépendant, de la connexion à l'accueil complété | Visiteur sans donnée ; inscription limitée à nom, adresse, mot de passe, confirmation et déclaration de majorité ; connexion ; fuseau confirmé ; matière, cours, tâche, note créés par les formulaires ; favori ajouté ; retour sur le cours terminé enregistré depuis l'accueil ; accueil : sept étapes faites, tâches, moyenne et couverture affichées, provenance visible | réussi |
| Edge — saisie et erreurs | Double clic, abandon, refus, session | Trois envois du même formulaire : une tâche ; confirmation demandée avant de quitter une saisie commencée ; formulaire incomplet : envoi bloqué, saisie conservée ; accès interdit et page introuvable : page d'erreur avec retour à l'accueil et aide ; session effacée puis action : retour à la connexion ; accueil à 360 px sans défilement horizontal ; aide, préférences de notification et profil atteints depuis l'accueil | réussi |
| Edge — parcours établissement | Administration A, enseignant A, apprenant A/B, indépendant, administration B | Contexte A choisi ; sept étapes affichées ; référentiels ; modèle d'import téléchargé, déposé, analysé par le traitement en file : « Invalide : corrections nécessaires », rien d'appliqué ; cours créé au brouillon pour le groupe A 1 (chevauchement signalé puis confirmé avec motif) ; publication avec confirmation nommant l'établissement ; invitation créée après nouvelle saisie du mot de passe ; acceptée par le compte indépendant, puis départ volontaire avec confirmation nommant l'appartenance ; cours publié visible par l'apprenant avec la provenance « Établissement » ; signalement de l'enseignant ; décision de l'administration ; sept étapes faites ; rien de A visible par l'administration B ; aucune erreur JavaScript | réussi |

### Résultats exacts
- php artisan test --compact (XDEBUG_MODE=off) : 224 tests réussis, 1 ignoré (mesure sur demande), 7 899 assertions, 105 816 ms. HomeTest : 8 tests.
- npm run test:js : 23 tests réussis, 0 échec.
- node tests/browser/journeys-edge.mjs : 35 contrôles réussis, 0 échec, navigateur Edg/158.0.4370.33 ; sortie dans docs/preuves/lot21-parcours-edge.txt.
- node tests/browser/pwa-edge.mjs (lots 19 et 20, rejoué après le changement d'accueil) : 36 contrôles réussis, 0 échec.
- vendor/bin/pint --format agent : passé ; npm run typecheck, npm run lint : sans erreur ; npm run build : succès. Aucune migration.
- Mesure : « un cours puis une tâche » par le script : 7,0 s au dernier passage (9,1 à 9,3 s aux précédents), 8 saisies ou clics, 2 écrans, attentes du script comprises. Ce n'est pas la mesure d'un débutant.
- Passages intermédiaires. HomeTest : trois échecs au premier passage, dus au test (calcul de fuseaux, valeur de niveau, en-tête resté actif d'une requête à l'autre), puis une règle changée dans le code : une fiche est suggérée si aucun critère déclaré n'est contredit et qu'au moins un correspond, la règle « tous les critères correspondent » ne retenant presque aucune fiche. Trois tests des lots 10, 11 et 01 adaptés au nouveau contrat de l'accueil.
- Parcours en navigateur : onze passages. Un vrai défaut applicatif trouvé et corrigé (fiche d'opportunité vide, voir ci-dessus). Les autres échecs venaient du script : libellés de champs supposés, lien d'invitation cherché au mauvais endroit, mot de passe à ressaisir avant de créer une invitation, signalement possible seulement sur un cours à venir, cours de deux passages successifs se chevauchant (refus légitime de Campus), traitement en file lancé sans la dérogation locale (l'analyse s'arrête alors, les droits d'administration n'étant pas ouverts : comportement voulu), boîte de confirmation du navigateur traitée une seule fois.
- Incidents d'outillage : le premier passage a été arrêté de force après dix minutes sans réponse, ce qui a laissé tourner un navigateur sans interface et les comptes fictifs ouverts avec un mot de passe aléatoire inconnu ; le navigateur a été identifié par sa ligne de commande puis arrêté, et les comptes refermés par la commande. Le script a depuis un délai par commande, un arrêt de secours à neuf minutes et une fermeture fiable du navigateur ; un second arrêt de secours a bien refermé les comptes, mais a encore laissé un navigateur, arrêté de la même façon. Vérifié après le dernier passage : aucun processus ni port restant.

### Parcours manuel proposé — données fictives
1. Sur le poste de développement : php artisan campus:fixtures:access (saisir un mot de passe d'au moins 12 caractères), puis lancer le serveur avec CAMPUS_PRIVILEGED_WITHOUT_MFA=true.
2. Chronométrer une personne qui ne connaît pas Campus : connectée comme independant@example.test, lui demander de créer un cours puis une tâche, sans aide. Noter le temps et les hésitations. C'est la mesure demandée par le lot, non faite ici.
3. Parcours individuel : suivre les sept étapes de l'accueil jusqu'à leur disparition.
4. Parcours établissement : admin-a@example.test, contexte « Établissement fictif A », suivre les sept étapes ; pour l'import, remplir le modèle avec deux lignes, le déposer, lire le rapport, l'appliquer, publier.
5. Refermer l'accès : php artisan campus:fixtures:access --lock.

### Non vérifié
Essai chronométré avec une personne débutante ; inscription d'un nouveau compte jusqu'à sa vérification (aucun service de courriel) ; import de lignes réelles et application depuis le navigateur ; coupure de réseau pendant un envoi dans l'application ; page de session expirée (419) dans un navigateur, la session effacée renvoyant directement à la connexion ; Firefox, Safari, téléphones réels ; lecteur d'écran.

## Lot 22 — droits des personnes et conservation
Preuves exécutées le 22 novembre 2026 avec des données fictives : tests PHPUnit sur MySQL hako_ra_campus_test, stockage de fichiers simulé, journal des effacements dans un fichier jetable propre aux tests.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| V22-01 | Notice publique, versionnée, sans rien d'inventé | Version du 22 novembre 2026, huit rubriques à compléter dont le responsable en tête ; « à compléter avant ouverture » pour le responsable, le contact et l'hébergement ; aucun des mots « conforme », « RGPD », « certifié » ; « pas instantanément » pour les sauvegardes ; coordonnées fictives fournies par configuration : affichées, cinq rubriques restantes, empreinte du texte inchangée ; durée d'audit suivant la configuration ; version inconnue introuvable | réussi |
| V22-02 | Preuve de lecture liée à la version | Rappel présent pour le compte, absent pour un visiteur ; version périmée refusée ; confirmation : compte, version, empreinte et date enregistrés, une seule ligne après deux envois ; rappel levé pour ce compte seulement | réussi |
| R51 (export) | Export autorisé, limité, privé, expirant | Sans nouvelle saisie du mot de passe : renvoi à la confirmation, aucun export ; export : fichier privé, expiration à 7 jours, demande « accès » close avec date de vérification ; contenu : compte, tâche, retour de cours, note, pièce jointe (nom seulement), critères ; quatorze termes interdits absents (mot de passe, jetons, empreintes, autres comptes) ; compte rattaché : ses deux établissements et ses groupes, rien d'un tiers ; téléchargement : propriétaire 200 sans mise en cache, autre compte, plateforme et identifiant inconnu 404, visiteur renvoyé à la connexion ; après 7 jours : 404, fichier retiré par la purge ; quatrième export en vingt-quatre heures refusé | réussi |
| V22-03 | Rectification et opposition suivies | Type « suppression » et texte trop court refusés ; deux demandes suivies par leur auteur seul ; annulation par un tiers introuvable ; file réservée à la plateforme (indépendant et administration d'établissement : 403) ; la file montre la demande, jamais l'espace individuel ; réponse obligatoire pour clôturer ; « en cours » puis « traitée », réponse visible par l'auteur ; demande close non retraitée ; cinq demandes ouvertes au plus ; audit sans le texte | réussi |
| R51 (suppression) | Conséquences, réauthentification, effacement, révocation, preuve | Écran après confirmation du mot de passe, deux établissements nommés, export rappelé ; mauvais mot de passe ou mot de confirmation erroné : rien ne change, journal vide ; suppression : déconnexion, nom « Compte supprimé », adresse et identifiant vides, état « erased » ; quinze tables de l'espace individuel vides pour ce compte, l'autre compte intact ; fichier d'export et pièce jointe retirés du stockage, espace utilisé revenu à zéro ; appartenances et inscriptions fermées, connexion impossible avec l'ancien mot de passe ; historique de rattachement conservé ; demande « suppression » close sans texte ; audit fait de nombres ; journal : deux retraits et un effacement, sans nom ni contenu ; la plateforme voit « Compte supprimé » ; administration unique : suppression refusée avec son motif | réussi |
| R52 (préparation) | Réapplication après restauration | Un départ volontaire et une suppression journalisés ; restauration simulée : identité, appartenances, espace, tâche et session remis en base ; ligne ajoutée à la main sans signature valide ; commande : 4 entrées, 1 rejetée, 1 compte effacé de nouveau, 3 appartenances refermées, sessions fermées ; compte visé par la ligne falsifiée intact ; second passage sans effet ; journal conservé au moins 60 jours même réglé à 1 jour, vidé au-delà | réussi |
| V22-04 | Conservation paramétrable et bornée | Avant les délais : rien ; invitations parties 45 jours après leur création (14 + 30), une par passage avec la limite à 1 ; notifications et traces d'envoi à 90 jours ; trace d'audit faite de nombres ; audit : ligne gardée à 12 mois, partie avec la durée ramenée à 3 mois ; inactivité : dernière activité notée, 25 mois plus tard les comptes sont comptés, aucun n'est supprimé ni bloqué | réussi |
| V22-05 | Parcours des mineurs fermé | Fermé par défaut : inscription sans déclaration de majorité refusée ; activation seule, référence vide, référence seule : toujours fermé, aucun compte créé ; activation et référence réunies : contrôle ouvert ; la notice dit que l'inscription n'est pas ouverte | réussi |

### Résultats exacts
- php artisan test --compact (XDEBUG_MODE=off) : 232 tests réussis, 1 ignoré (mesure sur demande), 8 343 assertions, 118 432 ms. PrivacyTest : 8 tests.
- npm run test:js : 23 tests réussis ; npm run typecheck, npm run lint : sans erreur ; npm run build : succès ; vendor/bin/pint --format agent : passé.
- php artisan migrate (développement et test) : migration 2026_11_22_150000 exécutée.
- Passages intermédiaires. PrivacyTest : cinq passages. Défauts du code trouvés par les tests : une contrainte existante exigeait une adresse ou un identifiant, ce qui empêchait d'effacer l'identité (contrainte assouplie pour l'état « erased ») ; les limites de débit des nouvelles routes se cumulaient avec les autres actions du compte et refusaient la suppression (compteur propre à chaque route). Défauts des tests : valeur de note attendue, canal d'envoi invalide, horloge de la confirmation du mot de passe, nombre d'appartenances refermées, ordre d'affichage, durée de trois mois calculée trop juste.
- Migration rejouée : la migration du lot, déjà exécutée sur les deux bases locales avec des tables vides, a été annulée puis relancée après sa correction. Aucune donnée n'a été perdue ; aucune autre migration n'a été touchée.

### Parcours manuel proposé — données fictives
1. Ouvrir /confidentialite sans compte : lire la notice et la liste « à compléter ».
2. Se connecter avec un compte fictif (php artisan campus:fixtures:access), ouvrir « Confidentialité et données » depuis le profil, confirmer la lecture de la notice.
3. « Générer un export » : ressaisir le mot de passe, télécharger le fichier, vérifier qu'il ne contient que ses propres données.
4. Déposer une demande de rectification ; avec plateforme@example.test (contexte plateforme, dérogation locale), la traiter depuis « Demandes de confidentialité et conservation » ; revenir lire la réponse.
5. « Supprimer mon compte » avec un compte fictif sans rôle d'administration : lire les conséquences, confirmer, vérifier la déconnexion et l'impossibilité de se reconnecter. Relancer ensuite le seeder pour retrouver le jeu fictif.

### Non vérifié
Écrans du lot dans un navigateur ; relecture juridique de la notice ; sauvegarde et restauration réelles (lot 24) ; écriture du journal sur un stockage distinct ; purge planifiée sur un serveur déployé ; volumes importants pour l'export et la suppression.

## Lot 23 — durcissement et mesures
Détail des constats, des mesures et des risques ouverts : docs/audit-v1.md. Preuves exécutées le 22 novembre 2026 avec des données fictives.
| Cas | Attendu | Preuve exécutée | Résultat local |
|---|---|---|---|
| D23-01 (HardeningTest) | En-têtes de sécurité sur toute réponse | Page publique, page connectée, JSON, page introuvable, redirection, refus JSON : quatre en-têtes identiques, politique sans script en ligne ni origine tierce ; HSTS seulement sur requête sécurisée ; téléchargement d'un fichier avec sa propre politique « rien ne s'exécute » | réussi |
| D23-02 (HardeningTest) | Nom de site forgé refusé | Hors local et tests : nom exact accepté ; nom forgé, suffixe forgé, sous-domaine : 400 | réussi |
| D23-03 (HardeningTest) | Une limite de débit par route et par compte | 60 créations acceptées, la 61e refusée ; fuseau, demande, suppression et copie hors connexion du même compte toujours ouverts ; autre compte non affecté ; compteur reparti à la minute suivante | réussi |
| D23-04 (HardeningTest) | Adresses http et https seulement, aucun appel sortant | Sept adresses refusées pour un lien de cours, cinq pour une fiche ; adresse valide acceptée ; aucun client HTTP dans le code de l'application pour ces adresses | réussi |
| D23-05 (HardeningTest) | Balises saisies : du texte, jamais du HTML | Nom, matière, tâche et note contenant des balises : enregistrés tels quels, absents comme balises de quatre pages ; séquence de rupture : bloc de données fermé, conteneur présent, données relues à l'identique par un analyseur HTML5 ; aucun écran n'insère de HTML | réussi |
| R58 (HardeningTest) | Aucun autre compte, pas même la plateforme, n'atteint une donnée privée | Sept acteurs : quatre lectures directes introuvables, six écritures sans effet, synchronisation refusée sans indice, contenu absent de sept pages chacun et de cinq écrans d'administration ; la plateforme lit seulement la demande qui lui est adressée ; rien n'a changé | réussi |
| R09 (HardeningTest) | Aucun rôle ne s'élève | Administration d'établissement : trois rôles refusés ; apprenant et enseignant : invitation refusée ; contexte d'un autre introuvable ; numéro d'établissement dans l'adresse sans effet ; aucune invitation créée | réussi |
| D23-06 (HardeningTest) | Session et CSRF | Plus de 80 routes d'écriture dans le groupe « web », toutes sous session hors entrées d'identité ; aucune exception CSRF ; déconnexion par POST ; cookie inaccessible aux scripts et limité au site | réussi |
| D23-07 (HardeningTest) | Contrôle avant ouverture | Configuration de test : échec avec les réglages à corriger, sans aucune valeur affichée ; configuration d'ouverture : réussite ; débogage réactivé : échec | réussi |
| D23-08 (HardeningTest) | Aucun secret côté client | Données partagées : nom et clé opaque ; fichiers servis au navigateur sans clé, mot de passe ni variable du serveur | réussi |
| D23-09 (QueryBudgetTest) | Pas de requête par ligne | Quinze écrans, de 2 à 12 lignes : au plus deux requêtes de plus, 60 au maximum | réussi |
| Edge — accessibilité | Structure, libellés, contrastes, cibles | 18 écrans à 1 280, 360 et 320 px : 54 relevés sans défaut ; contraste : aucun texte mesuré sous le seuil ; aucun contrôle sous 44 px à 360 px | réussi (partiel pour R55) |
| Edge — clavier, erreurs, animations | Parcours sans souris | Lien d'évitement en premier, 33 éléments en 45 tabulations, focus toujours visible ; dialogue gardant le focus, fermé par Échap ; erreur reliée à son champ ; animations coupées sur demande | réussi |
| Edge — contenu saisi | Rien d'exécuté, page intacte | Tâche contenant balises et séquence de rupture : texte affiché tel quel, aucune image ni script créé, aucune erreur ; aucun refus de la politique de contenu pendant tout le contrôle | réussi |
| Edge — temps de chargement | Moins de 3 s à 1,6 Mbit/s et 150 ms | Avec compression : 1,34 à 1,52 s sur sept écrans ; visite suivante : 0,47 à 0,65 s ; sans compression (serveur de développement) : 3,01 à 3,17 s, relevé sans verdict | réussi avec compression |
| Essai de charge court | Outil et application tiennent | 5 utilisateurs, 60 s, 221 requêtes : médiane 221 ms, 95e centile 771 ms, aucune erreur | réussi ; ne vaut pas mesure |
| Import de 1 000 lignes | Moins de 2 minutes | 158,7 s avant correction, 138,0 s après | cible non tenue sur ce poste |

### Résultats exacts
- php artisan test --compact (XDEBUG_MODE=off) : 243 tests réussis, 1 ignoré (mesure sur demande), 9 496 assertions, 129 207 ms. HardeningTest : 10 tests ; QueryBudgetTest : 1 test.
- npm run test:js : 23 tests réussis ; npm run typecheck, npm run lint : sans erreur ; npm run build : succès ; vendor/bin/pint --format agent : passé.
- node tests/browser/audit-edge.mjs : 78 contrôles réussis, 0 échec (Edg/158.0.4370.33) ; docs/preuves/lot23-audit-edge.txt.
- node tests/browser/pwa-edge.mjs : 36 réussis ; node tests/browser/journeys-edge.mjs : 35 réussis, rejoués après le durcissement.
- node tests/perf/load.mjs --smoke : docs/preuves/lot23-charge-essai-court.txt.
- CAMPUS_MEASURE=1 php artisan test --filter=measured_import : 153,6 s (pendant un build), 158,7 s au calme, 138,0 s après correction.
- composer audit : aucune alerte ; npm audit --omit=optional : 0 vulnérabilité.
- Passages intermédiaires. Première mise en place du limiteur par route : 125 tests en échec, méthode du cadre inaccessible, corrigé aussitôt. HardeningTest : réglage statique des hôtes de confiance resté actif entre deux tests ; suppression d'une note d'autrui répondant « succès » sans rien supprimer (contrat rejouable, test aligné) ; vérification textuelle sans effet à cause des accents échappés, ce qui a mené au constat S05. Contrôle Edge : quatre passages ; un script en ligne refusé s'est révélé être l'outil de développement Laravel Boost, désactivé pour le serveur du contrôle ; le relais de compression transmettait un mauvais nom de site ; trois vérifications du script comptaient un échec comme une réussite, corrigées.
- Incident d'outillage : un serveur local lancé à la main sur le port 8129 pour lire une page a été arrêté aussitôt après. Aucun processus ni port restant après le dernier passage.

### Non vérifié
Charge de référence (100 utilisateurs, 30 minutes, 5 000 comptes) ; Chrome sur ordinateur et sur Android, Safari, Firefox ; lecteur d'écran ; zoom natif ; antivirus, courriel et notifications réels ; en-têtes et compression des fichiers statiques sur un vrai serveur web ; test d'intrusion indépendant.

## Lot 24 — déploiement et restauration

Exécuté le 23 novembre 2026 sur le poste de développement. Données fictives uniquement. Rien n'a été déployé.

| Scénario | Attendu | Constaté | Preuve | Résultat |
|---|---|---|---|---|
| R52 | Après restauration, effacements et retraits d'accès réappliqués | Restauration **simulée** en test (lignes remises en base) : effacement et retrait d'accès appliqués de nouveau, entrée d'audit `restore.finalized` | OperationsTest | réussi en test ; non réalisé sur une base réellement restaurée |
| R53 | Après restauration ou panne, anciens envois neutralisés, rappels périmés expirés, rappels valides repris une seule fois | En test : envois en file annulés avec le motif `restored_backup` ; rappel périmé expiré ; rappel encore valide conservé puis traité au cycle suivant ; anciens traitements retirés | OperationsTest ; lot 13 pour la panne prolongée | réussi en test |
| Sauvegarde chiffrée | Base et fichiers chiffrés, manifeste signé | Sauvegarde `campus-20261123-072546` : 68 tables, 6 fichiers, 414 Ko chiffrés | docs/preuves/lot24-sauvegarde.txt | réussi (exercice réel) |
| Vérification approfondie | Empreintes conformes, export déchiffré complet | Manifeste signé ; export de 389 Ko ; 68 définitions de table ; export complet | idem | réussi (exercice réel) |
| Restauration des fichiers | Fichiers extraits dans un dossier isolé et conformes | 6 fichiers restaurés, conformes au manifeste | idem | réussi (exercice réel) |
| Restauration de la base | Base rechargée dans une base isolée, nombre de lignes contrôlé | Aucune base isolée disponible : les comptes MySQL du poste ne peuvent pas en créer. La commande a refusé la base en service | idem | **non réalisé** |
| Altération | Sauvegarde modifiée refusée | Autre clé, un octet modifié, manifeste retouché : trois refus | idem ; OperationsTest | réussi |
| Conservation | 30 jours, trois plus récentes gardées | Vérifié en test | OperationsTest | réussi en test |
| Supervision par composant | Un état par composant ; un accueil qui répond ne suffit pas | En test : chaque composant rapporté séparément ; sonde fermée sans jeton, 200 ou 503 avec jeton | OperationsTest | réussi en test ; antivirus, certificat et courriel jamais vus sains |
| Version en service | Affichée et contrôlée | Vérifié en test avec le contrôle de mise en service | OperationsTest | réussi en test |
| Déploiement | Mise en service sur un hébergement | Aucun hébergement ni accès fourni | — | non réalisé |

Commandes : `XDEBUG_MODE=off php artisan test --compact` → 251 tests, 250 réussis, 1 ignoré, 9 655 assertions ; `npm run test:js` → 23 réussis ; `vendor/bin/pint --format agent`, `npm run typecheck`, `npm run lint`, `npm run build` → passés.

Pour réaliser l'exercice manquant : sous un compte d'administration MySQL, créer une base vide (par exemple `hako_ra_campus_restauration`) et en donner les droits au compte local ; puis suivre docs/exploitation-v1.md, § 7.

## Lot 25 — recette finale (23 novembre 2026)

### Cadre commun à toutes les lignes

- **Version** : `1.0.0-rc1+8c70dd22c420`, empreinte du code du poste, calculée hors dossier docs et hors fichiers Markdown ; aucun commit n’existe. Les deux passages dans Edge ont été faits sur ce même code avant la correction de l’anomalie AN25-01, qui ne touche que l’emplacement du fichier de version et ses tests ; après quoi toute la suite PHPUnit a été relancée.
- **Environnement** : poste de développement uniquement. PHP 8.4.15, MySQL 8.4.7, Node 24.12, Edge 158 sans interface. **Recette et production : non exécuté pour les 58 scénarios**, ces environnements n'existant pas.
- **Fixtures** : pour les tests PHPUnit, base `hako_ra_campus_test` et données fabriquées par chaque test ; pour Edge, base `hako_ra_campus_dev` et jeu fictif (établissements fictifs A et B, comptes `independant`, `apprenant-double`, `enseignant-a`, `admin-a`, `admin-b`, `editeur`, tous en @example.test). Aucune donnée réelle.
- **Preuves** : T = docs/preuves/lot25-tests-par-scenario.txt (251 tests, 0 échec, 1 ignoré, classés par scénario) ; N = docs/preuves/lot25-recette-edge.txt (19 contrôles, 0 échec) ; P = docs/preuves/lot25-parcours-edge.txt (35 contrôles, 0 échec) ; E = docs/preuves/lot25-exploitation-locale.txt ; les preuves des lots précédents sont citées par leur nom.
- **Statuts** : passé ; échoué ; non exécuté ; non applicable. Quand une partie seulement du scénario a pu être exécutée, le statut le dit : « passé en test ; non exécuté : … ».
- Les rôles d'enseignement, d'administration et d'édition n'ont pu être exercés qu'avec la dérogation limitée au poste de développement, le second facteur n'étant pas livré.

### Matrice

| ID | Acteur et fixture | Étapes | Attendu | Observé | Preuve | Statut (local) |
|---|---|---|---|---|---|---|
| R01 | Visiteur, nouveau compte | Inscription ; lien de vérification valide, expiré, d'un autre compte | Compte utilisable après vérification | Espace privé créé sans rôle ; seul un lien valide ouvre le compte ; écran d'inscription limité à cinq champs dans Edge. Aucun courriel réel reçu : pas de service d'envoi | T (3 tests) ; P | passé en test ; non exécuté : réception réelle du courriel |
| R02 | Compte existant, compte inconnu | Mots de passe faux répétés ; demande de récupération | Limitation, sans révéler l'existence du compte | Même réponse dans les deux cas ; limitation appliquée ; compte suspendu déconnecté | T (4 tests) | passé |
| R03 | `independant`, invitation de A | Ouvrir l'invitation en étant connecté | Rattachement sans seconde identité | Un seul compte, une appartenance de plus ; constaté aussi dans Edge | T ; P | passé |
| R04 | Invitation expirée, révoquée | Ouvrir le lien | Refus, aucune appartenance | Refus, rien d'ajouté | T | passé |
| R05 | Même matricule en A et en B | Rattacher par matricule | Aucun mélange | Personnes et accès distincts | T | passé |
| R06 | `apprenant-double` | Changer de contexte A puis B | Changement explicite, données séparées | Dans Edge : cours importé et devoir visibles en A, absents en B | T (2 tests) ; N | passé |
| R07 | `enseignant-a` | Tenter de s'attribuer l'administration | Refus, événement tracé | Refus et trace d'audit | T | passé |
| R08 | `independant`, membre de A | Quitter l'établissement | Accès retiré, espace personnel conservé | Dans Edge : plus aucune appartenance à A, espace individuel intact | T (2 tests) ; P | passé |
| R09 | Membre de A, objet de B | Ouvrir l'adresse directe d'un objet de B ; envoyer un numéro d'établissement | Refus côté serveur, aucune fuite | Introuvable ou interdit ; numéro envoyé sans effet ; dans Edge, l'administration B ne voit rien de A | T (3 tests) ; N ; P | passé |
| R10 | `admin-a`, serveur sans dérogation | Se connecter ; activer le second facteur ; se reconnecter ; rejouer un code ; utiliser un code de secours ; retrait par l’exploitation | Second facteur exigé, récupération contrôlée | Sans facteur : administration fermée. Après activation : code exigé après le mot de passe, aucun écran ni contexte avant ; code faux, rejoué ou trop ancien refusé ; code de secours accepté une fois ; retrait par commande tracé, rôle refermé. Codes calculés par le script selon la RFC 6238 | SecondFactorTest (7 tests) ; lot25-second-facteur-edge (7 contrôles) | passé en test et dans Edge ; non exécuté : application d’authentification réelle sur un téléphone |
| R11 | `independant` | Créer un cours récurrent sur une période | Occurrences exactes | Occurrences exactes, fuseaux compris ; cours créé dans Edge | T (3 tests) ; P | passé |
| R12 | `admin-a` | Importer une ligne dont la fin précède le début | Erreur localisée, rien de publié | Erreur avec feuille, ligne et colonne ; rien d'appliqué | T | passé |
| R13 | `admin-a` | Importer un groupe inconnu parmi des lignes valides | Lot refusé | Lot refusé en entier | T | passé |
| R14 | `admin-a` | Déposer deux fois le même fichier | Aucun doublon | Même lot retrouvé, aucun cours en double | T | passé |
| R15 | `admin-a` | Créer un cours qui en chevauche un autre | Conflit visible, confirmation motivée | Refus affiché dans Edge, puis acceptation seulement avec motif | T (2 tests) ; N ; P | passé |
| R16 | `admin-a` | Publier, puis revenir à la version précédente | Planning cohérent, rappels recalculés | En test : nouvelle version créée par le retour, identifiants et historique gardés. Dans Edge : publications des versions 12 et 13 | T (3 tests) ; N | passé en test ; non exécuté : retour à une version antérieure dans un navigateur |
| R17 | `admin-a`, `apprenant-double` | Reporter une seule occurrence | Série inchangée, identifiant conservé | En test : identifiant conservé. Dans Edge : occurrence importée déplacée de 10:50 à 17:50, republiée, vue par l'apprenant au nouvel horaire | T ; N | passé |
| R18 | Cours avec rappel prévu | Annuler le cours | Aucun rappel inutile | Aucun rappel créé ; rappel déjà programmé annulé | T (3 tests) | passé |
| R19 | Apprenant | Modifier un horaire officiel | Refus ; ajout personnel possible | Écriture refusée ; événement personnel accepté | T | passé |
| R20 | `enseignant-a` | Signaler une indisponibilité | En attente jusqu'à décision | Signalement visible, décision prise par l'administration dans Edge | T ; P | passé |
| R21 | Apprenant, cours terminé | Laisser passer la fin du cours et 5 minutes | Un rappel créé selon les préférences | En test, horloge avancée : rappel programmé à fin + 5 minutes ou au délai choisi | T (2 tests) | passé en test ; non exécuté : attente en temps réel |
| R22 | Apprenant | Enregistrer le retour avant le rappel | Rappel supprimé | Rappel annulé ; rappel déjà remis retiré | T (2 tests) | passé |
| R23 | Apprenant, appareil personnel | Écrire un retour hors connexion, l'envoyer plusieurs fois | Un seul retour, état clair | En test : un retour après quatre envois. Dans Edge, pour une tâche : écrite sans réseau, affichée comme brouillon, envoyée une seule fois au retour du réseau | T ; lot20-edge ; N | passé en test ; non exécuté : retour de cours hors connexion dans un navigateur |
| R24 | `enseignant-a`, deux apprenants | Un apprenant termine un devoir de groupe | État des autres inchangé | En test : états distincts. Dans Edge : devoir publié, terminé par un apprenant, absent de son contexte B | T ; N | passé |
| R25 | Apprenant | Terminer une tâche dont le rappel est programmé | Rappel annulé | Annulé à la remise et avant l'envoi externe | T (2 tests) | passé |
| R26 | `independant` | Saisir 14/20 coefficient 1 et 36/60 coefficient 2 | 12,67 | 12,67 en test ; **12,67 affiché dans Edge** après saisie réelle | T (2 tests) ; N | passé |
| R27 | Apprenant | Exemple à deux matières, coefficients 3 et 2 | 13,60 | 13,60 sur valeurs non arrondies | T (2 tests) | passé |
| R28 | Apprenant | Note manquante, dispense, barème nul | États distincts ; barème nul refusé | États sans valeur jamais comptés zéro ; barème nul refusé, y compris par la base | T | passé |
| R29 | Apprenant | Remplacer une note publiée | Refus, provenance visible | Écritures refusées, résultat inchangé | T | passé |
| R30 | Enseignant | Note sur une période d'une autre année | Refus | Refus par la validation et par la base | T | passé |
| R31 | `editeur` | Publier sans source, sans organisateur, sans vérification | Publication bloquée | Refus en test ; dans Edge, publication refusée sans acte de vérification puis acceptée avec | T ; N | passé |
| R32 | Visiteur | Filtrer par niveau, pays, domaine, modalité | Résultats conformes, filtres visibles | Filtres seuls et combinés, tris, pagination ; recherche par titre dans Edge | T ; N | passé |
| R33 | Compte au profil incomplet | Comparer avec une condition | « À vérifier », aucune certitude inventée | Information manquante = « à vérifier » | T | passé |
| R34 | `editeur` | Date sans heure, clôture continue | Précision conservée | Conservée partout ; fiche d'essai créée avec une date sans heure dans Edge | T (2 tests) ; N | passé |
| R35 | `editeur` | Clôture dans un autre fuseau | Archivage au bon instant | Paris, New York, Abidjan : instant exact | T | passé |
| R36 | `editeur` | Nouvelle édition | Nouvelle campagne, historique gardé | Fiche distincte, historique intact | T | passé |
| R37 | `editeur`, `independant` | Prolonger la clôture d'un favori | Date et rappels à jour, sans doublon | En test : un seul avis. Dans Edge : date prolongée, puis « La date limite d'une opportunité que vous suivez a changé » dans le centre | T (3 tests) ; N | passé |
| R38 | `editeur` | Suspendre une fiche signalée | Retrait du catalogue, information utile | Retrait immédiat, suiveurs prévenus une fois | T (3 tests) | passé |
| R39 | Apprenant | Même favori depuis deux appareils | Un seul favori | Trois ajouts, un favori ; doublon refusé par la base | T | passé en test ; non exécuté : deux appareils réels |
| R40 | Apprenant | Cliquer le lien de candidature | Aucun état « envoyée » automatique | État, version et dates inchangés | T (2 tests) | passé |
| R41 | Sept profils dont l'administration de la plateforme | Demander le suivi d'un autre | Refus, rien d'exposé | Rien d'exposé, rien de modifié | T | passé |
| R42 | Apprenant | Se désabonner d'une recherche | Alertes arrêtées, favoris gardés | Arrêt, favoris intacts | T | passé |
| R43 | File de traitement | Rejouer un même envoi | Pas de seconde notification | Aucun doublon ; doublons refusés par la base. Envois externes simulés | T (2 tests) | passé en test ; non exécuté : envoi par un service réel |
| R44 | Apprenant | Refuser les notifications | Application utilisable, centre disponible | Centre disponible sans notification du navigateur, dans Edge sur ordinateur | T ; lot19-edge ; N | passé en test ; non exécuté : sur un téléphone |
| R45 | Apprenant, deux appareils | Modifier la même tâche hors connexion | Conflit visible, aucune perte | Version dépassée refusée, différences rendues, brouillon gardé | T ; tests JavaScript | passé en test ; non exécuté : deux appareils réels |
| R46 | Apprenant | Se déconnecter avec un brouillon en attente | Avertissement, purge après décision | Avertissement et effacement confirmé constatés sur la page hors ligne au lot 20 | tests JavaScript ; lot20-edge | passé en test ; non exécuté : avertissement de l'écran Profil dans un navigateur |
| R47 | Deux comptes, un appareil | Se connecter l'un après l'autre | Aucun cache privé partagé | Réponses non mises en cache ; copie du premier compte effacée au changement | T ; tests JavaScript ; lot19-edge | passé en test ; non exécuté : contrôle du stockage après deux connexions réelles |
| R48 | Apprenant dont l'appartenance est retirée | Synchroniser un brouillon | Refus, brouillon récupérable | Refus sans donnée du cours ; brouillon gardé | T (2 tests) | passé |
| R49 | Apprenant | Déposer un fichier interdit, trop gros, infecté | Refus ou quarantaine | Dix-huit fichiers refusés ; dans Edge, un PDF valide reste « en quarantaine, en attente d'analyse », sans téléchargement. Détection simulée seulement | T (2 tests) ; N | passé en test ; non exécuté : antivirus réel |
| R50 | Tiers sans droit | Ouvrir le lien d'un fichier privé | Refus | Introuvable, y compris après retrait d'appartenance | T | passé |
| R51 | Apprenant | Exporter, puis demander l'effacement | Accès vérifié, traitement tracé | Export limité au demandeur après mot de passe ; effacement et preuve sans contenu | T (2 tests) | passé en test ; non exécuté : écrans dans un navigateur |
| R52 | Exploitation | Restaurer une sauvegarde complète | Application utilisable, révocations réappliquées | Sauvegarde chiffrée, vérification approfondie et restauration des fichiers réelles ; **base non restaurée** : aucune base isolée disponible. Réapplication réussie en test sur restauration simulée | T (2 tests) ; lot24-sauvegarde | **non exécuté** : restauration réelle de la base |
| R53 | File après panne | Reprendre après un arrêt prolongé | Périmés expirés, utiles conservés | En test, trois jours simulés : périmés expirés, utiles remis, aucune rafale. Sur le poste : un passage du planificateur et de la file, supervision saine | T (2 tests) ; E | passé en test ; non exécuté : panne réelle d'un serveur |
| R54 | Exploitation | Charge de référence ; import de 1 000 lignes | Cibles tenues | Import : 138,0 s sur le poste pour une cible proposée de 120 s. Essai court sans erreur. Charge de référence jamais lancée | lot09-mesure ; lot23-charge ; audit-v1 | **échoué** pour l'import sur ce poste ; non exécuté : charge de référence |
| R55 | Tous écrans | Clavier, zoom, écran mobile | Actions accessibles, texte lisible | 18 écrans à 1 280, 360 et 320 px sans défilement horizontal, focus visible, contrastes mesurés ; accueil à 360 px revérifié | lot23-audit-edge (78 contrôles) ; P | passé pour clavier et écran étroit ; non exécuté : zoom natif à 200 %, téléphones, lecteur d'écran |
| R56 | Appareil avec envois en attente | Mettre à jour l'application | Migration sans perte | Base locale passée de la version 1 à 2 avec sa copie | lot20-edge ; tests JavaScript | passé pour la migration locale ; non exécuté : remplacement réel du service worker avec des envois en attente |
| R57 | `independant` | Perdre le réseau pendant un formulaire, puis reprendre | Saisie conservée, création unique | En test : création unique. Dans Edge : réseau coupé, tâche gardée comme brouillon, une seule tâche après le retour du réseau ; trois envois du même formulaire, une seule tâche | T (2 tests) ; N ; P | passé |
| R58 | Administrations A, B et plateforme | Lire un retour privé, une tâche, une note, une démarche | Refus systématique | Refus en lecture, en écriture, par la synchronisation et par les écrans d'administration | T (4 tests) | passé |

Bilan local, après la livraison du second facteur : 40 passés ; 16 passés en test avec une partie non exécutée ; 1 échoué (R54) ; 1 non exécuté (R52). Aucun « non applicable ».

### Parcours de bout en bout dans Edge

| Parcours | Ce qui a été fait | Résultat |
|---|---|---|
| Indépendant | Accueil, fuseau, matière, cours, tâche, note, favori, retour après cours, aide, erreurs, écran de 360 px, session expirée | P : 20 contrôles réussis |
| Apprenant de deux établissements | Planning publié vu en A, absent en B ; cours reporté vu au nouvel horaire ; devoir reçu, terminé, absent en B | N et P : réussis |
| Enseignant affecté | Signalement d'indisponibilité ; devoir publié pour son groupe | N et P : réussis |
| Administration A et B | Référentiels, import analysé puis appliqué, publication, invitation, décision sur un signalement, report d'une occurrence ; B ne voit rien de A | N et P : réussis |
| Éditeur | Brouillon, refus sans vérification, publication, prolongation | N : réussis |

| Fonction demandée | Preuve dans Edge | Reste non exécuté |
|---|---|---|
| Planning importé et publié | Fichier valide analysé, appliqué au brouillon, publié, vu par l'apprenant | essai avec un vrai fichier d'établissement |
| Report | Une occurrence déplacée, republiée, vue au nouvel horaire | — |
| Retour après cours | Cours terminé proposé sur l'accueil, retour enregistré | rappel reçu en temps réel |
| Tâche | Création, double envoi sans doublon, devoir terminé | — |
| Moyenne | 12,67 affiché | 13,60 non rejoué dans le navigateur |
| Opportunité | Créée, vérifiée, publiée, trouvée sans compte | — |
| Favori | Ajouté depuis la fiche | — |
| Alertes | Recherche enregistrée, puis synthèse et avis de changement de date dans le centre après un cycle | courriel et notification du navigateur |
| Synchronisation | Tâche écrite sans réseau, envoyée une seule fois | conflit entre deux appareils réels |
| Fichier | PDF déposé, reçu, gardé en quarantaine | téléchargement : impossible sans antivirus |

### Périmètre

- Recherche dans les écrans, les routes et les contrôleurs des fonctions exclues (SMS, WhatsApp, calendrier ICS, connexion Google ou Microsoft, paiement, assistant, forum, portail parental, présences, sanctions, bulletin, accès de support) : aucune trouvée.
- Interfaces simulées : aucune. L'atelier des composants répond 404 hors poste de développement et tests. Aucun texte « à venir » ni donnée d'exemple dans les écrans livrés.
- Intégrations : aucun pilote factice activable. Sans configuration, courriel, notifications du navigateur et antivirus sont déclarés indisponibles.

### Commandes exécutées

- `vendor/bin/phpunit --log-junit …` → 251 tests, 250 réussis, 1 ignoré (mesure d'import, lancée à part), 9 655 assertions.
- `node tests/browser/recette-edge.mjs` → 19 contrôles réussis, 0 en échec (après quatre passages de mise au point du script lui-même).
- `node tests/browser/journeys-edge.mjs` → 35 contrôles réussis, 0 en échec.
- `php artisan schedule:run`, `queue:work --stop-when-empty`, `campus:health` → base, file, processus de file, planificateur et disque sains ; sauvegarde, antivirus, certificat et envois « non configuré ».
- `php artisan campus:release:check --wait=5` → version, migrations, stockage et planificateur corrects ; processus de file en échec, aucun processus permanent ne tournant sur le poste.
- `php artisan campus:security:check` → 12 réglages bloquants pour une ouverture, attendus sur un poste de développement.
- `composer audit`, `npm audit --omit=optional` → aucune vulnérabilité.
- `vendor/bin/pint --format agent` → passé.

Non relancés, faute de changement les concernant depuis leur dernier passage : contrôles PWA et audit d'accessibilité dans Edge (lot 23), tests JavaScript, typage, lint et build (lot 24), essai de charge court (lot 23).

### Anomalie trouvée et corrigée pendant la recette

- AN25-01 : un test du lot 24 écrivait puis effaçait le vrai fichier `REVISION` du poste. Constaté quand le fichier de version a disparu après la suite de tests. Corrigé : emplacement réglable (`campus.revision_file`), les tests utilisent un fichier jetable. Retest : suite complète relancée, fichier intact.

### Données fictives laissées dans la base de développement

Fiches, cours, devoirs, tâches et notes préfixés « [RECETTE … ] » et « [PARCOURS … ] », dont trois fiches d'essai publiées dans le catalogue de développement. Elles ne doivent jamais être copiées vers un catalogue réel.
## Second facteur — après le lot 25 (23 novembre 2026)

Décision du porteur : second facteur par code temporaire à six chiffres (Google Authenticator ou application compatible). Version : `1.0.0-rc1+02e7365af89b`.

| Contrôle | Attendu | Constaté | Preuve | Résultat |
|---|---|---|---|---|
| Calcul des codes | Conforme à la RFC 6238 | Quatre vecteurs de la RFC exacts ; tolérance d’un pas ; rejeu et pas éloignés refusés | SecondFactorTest | réussi |
| Compte privilégié sans facteur | Rôle fermé, espace personnel utilisable | Connexion possible, administration en « Accès interdit », activation proposée sur le profil | SecondFactorTest ; Edge | réussi |
| Activation | Mot de passe redemandé, clé montrée une fois, code exact exigé | Constaté ; huit codes de secours affichés une seule fois ; ni secret ni code en clair dans la base | SecondFactorTest ; Edge | réussi |
| Connexion suivante | Rien n’est accessible avant le code | Tout écran renvoie à la saisie du code ; la synchronisation répond 403 ; aucune écriture ; ni contexte ni appartenance affichés | SecondFactorTest ; Edge | réussi |
| Codes de secours | Usage unique ; renouvelables | Un code sert une fois ; de nouveaux codes annulent les anciens | SecondFactorTest | réussi en test ; non exécuté dans un navigateur |
| Tentatives | Limitées | Après le nombre d’échecs autorisé, même un code exact est refusé pendant le délai | SecondFactorTest | réussi |
| Retrait par la personne | Code exigé ; rôle refermé | Constaté | SecondFactorTest | réussi en test |
| Récupération contrôlée | Retrait par l’exploitation, motivé et tracé | Motif obligatoire, entrée d’audit, sessions fermées, rôle fermé jusqu’à nouvelle activation | SecondFactorTest ; Edge | réussi |
| Traitements de fond | Mêmes droits que la personne | Droits gardés seulement si le compte a un facteur confirmé | SecondFactorTest | réussi |
| Application réelle sur téléphone | Codes acceptés | Non essayé : les codes ont été calculés par les tests et par le script | — | non exécuté |

Commandes : `php artisan test --compact` → 258 tests, 257 réussis, 1 ignoré, 9 940 assertions ; `node tests/browser/second-factor-edge.mjs` → 7 contrôles réussis, serveur lancé sans dérogation ; `npm run test:js` → 23 réussis ; Pint, typage, lint, build passés ; migration `create_second_factors_table` appliquée aux bases de développement et de test (ajout d’une table).

Non relancés après ce changement : recette-edge et journeys-edge, qui tournent avec la dérogation locale et des comptes sans second facteur.

## Connexion par compte Google — après le lot 25 (23 novembre 2026)

Décision du porteur : ajouter la connexion par compte Google, en plus du mot de passe et du second facteur. Version : `1.0.0-rc1+b041ea833cf1`.

**Aucun échange réel avec Google n'a eu lieu** : aucun identifiant Google n'a été fourni. Dans les tests, la réponse de Google est fabriquée. La fonction est donc écrite et testée, pas vérifiée en conditions réelles.

| Contrôle | Attendu | Constaté | Preuve | Résultat |
|---|---|---|---|---|
| Sans identifiants configurés | Aucun bouton, routes fermées | Bouton absent, 404 | GoogleSignInTest | réussi |
| Départ vers Google | État, nonce et PKCE ; jamais le secret | Paramètres exacts ; état différent à chaque demande ; vérificateur présenté à l'échange | GoogleSignInTest ; lot25-google-local | réussi (sans appel à Google) |
| Compte Google inconnu | Création seulement après la déclaration de majorité ; aucun rôle | Rien créé sans déclaration ; rôle ou établissement glissé dans la demande ignoré ; adresse vérifiée ; espace personnel créé | GoogleSignInTest | réussi en test |
| Inscription fermée | Aucun compte créé | Refus, aucun compte | GoogleSignInTest | réussi en test |
| Compte existant vérifié, même adresse | Rattachement sans seconde identité | Relié, mot de passe conservé ; un second compte Google de même adresse refusé | GoogleSignInTest | réussi en test |
| Compte existant jamais vérifié | Pas de prise de contrôle par l'ancien mot de passe | Adresse vérifiée, ancien mot de passe annulé, sessions fermées | GoogleSignInTest | réussi en test |
| Réponses invalides | Refus sans connexion ni rattachement | État inventé ou rejoué, nonce, destinataire, émetteur, expiration, adresse non vérifiée, erreur de Google, refus de la personne, compte suspendu : douze refus | GoogleSignInTest | réussi en test |
| R10 avec Google | Second facteur toujours exigé | Code demandé après Google ; sans second facteur, aucun rôle privilégié | GoogleSignInTest | réussi en test |
| Rattachement depuis le profil | Mot de passe redemandé ; compte Google déjà pris refusé | Constaté ; un seul compte Google par compte Campus | GoogleSignInTest | réussi en test |
| Confirmation d'une action sensible | Authentification Google récente, du compte relié | Ancienne authentification ou autre compte Google : refus | GoogleSignInTest | réussi en test |
| Ce que Campus conserve | Identifiant du compte Google et adresse ; aucun jeton | Constaté sur la ligne enregistrée | GoogleSignInTest | réussi |
| Connexion réelle avec un compte Google | Parcours complet | Non essayé : aucun identifiant fourni | — | **non exécuté** |

Commandes : `php artisan test --compact` → 268 tests, 267 réussis, 1 ignoré, 10 277 assertions ; Pint, typage, lint, build passés ; migration `create_social_identities_table` appliquée aux deux bases locales (ajout d'une table) ; contrôle local du bouton et du départ sur un serveur temporaire, sans appel à Google (docs/preuves/lot25-google-local.txt).

Tests de garde ajustés, sans retirer leur règle : l'interdiction de tout appel sortant garde une seule exception, limitée à l'adresse fixe de Google et vérifiée par le test ; la liste des écritures ouvertes aux visiteurs reçoit la création de compte après identification Google.

## Recette manuelle du porteur — en cours (17 novembre 2026)
Tests réalisés à la main par le porteur, dans son navigateur, sur l'environnement local. Résultats tels que rapportés ; navigateur et version non précisés. Liste à compléter au fil des essais.
| N° | Test manuel | Résultat observé par le porteur | Statut |
|---|---|---|---|
| 1 | S'inscrire : l'écran de vérification signale que l'envoi de courriels n'est pas configuré | « Oui, cela affiche un message d'erreur » | passé |
| 2 | Cinq mauvais mots de passe : blocage temporaire, identique pour une adresse inconnue | Blocage de 30 secondes affiché ; pareil pour un compte inconnu | passé ; voir remarque |
| 3 | Deux navigateurs, fermeture d'une session depuis le profil : l'autre est déconnecté au rechargement | « C'est ok » | passé |
| 4 | Compte sans email créé depuis l'administration, activé sur /activation, secret refusé comme mot de passe | Non exécuté : « je n'ai pas accès à l'admin pour l'instant » | non exécuté, bloqué |
| 5 à 18 | Invitations et contextes, planning et publication, import Excel, retour après cours, 360 px, clavier, zoom, hors réseau, double clic | Pas encore rapportés | non exécuté |

Remarques :
- Test 2 : le blocage configuré dure 60 secondes à partir du premier échec de la série ; l'écran affiche le temps restant. Voir « 30 secondes » après cinq essais étalés sur une trentaine de secondes est donc le comportement attendu, pas un écart.
- Test 4 : bloqué par l'absence de compte d'administration utilisable à la main (mots de passe aléatoires du jeu fictif, aucun courriel envoyé). Ce blocage concerne aussi les tests 5 à 13. Depuis le lot 21, il se lève sur le poste de développement avec « php artisan campus:fixtures:access » (mot de passe saisi sans écho, donné aux seuls comptes fictifs), le serveur local étant lancé avec CAMPUS_PRIVILEGED_WITHOUT_MFA=true pour les rôles d'administration et d'enseignement ; « --lock » referme l'accès.
- Ces résultats complètent R01 (test 1), R02 (test 2) et le cas I08 (test 3) par une observation en navigateur réel ; ils ne valent pas réception de phase.