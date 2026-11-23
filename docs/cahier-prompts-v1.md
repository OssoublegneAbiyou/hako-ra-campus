# Cahier de prompts pour développer HAKO RA Campus V1

**Version 1.0 — 15 novembre 2026**  
**Destinataire :** porteur du projet HAKO-RA et développeurs utilisant Claude Code ou Codex.  
**Stack retenue :** Laravel, React, TypeScript, Inertia, Tailwind et MySQL.  
**Produit :** Web responsive et PWA, avec compte unique, usage individuel et usage établissement.

Ce cahier transforme le périmètre Fondation et Opportunités en consignes de développement exécutables. Chaque prompt demande une réalisation vérifiable dans le dépôt, pas une simple proposition. Les critères de validation évitent de confondre écrans terminés, fonctionnalités réellement utilisables et service prêt à accueillir des données réelles.

Référence fonctionnelle : [Cahier des charges HAKO-RA Campus v1 du 15 novembre 2026](https://chatgpt.com/library?file_id=libfile_ad4afae926588191a270d663dc5c9256&account_id=personal), notamment les chapitres 5 à 23 et les scénarios R01 à R58. Ce cahier de prompts est un complément de réalisation ; il ne remplace pas le cahier des charges. La décision Laravel + React + Inertia + MySQL précise son architecture initialement proposée.

## 1 Mode d emploi

1. Télécharge ce fichier et ouvre le dépôt Campus dans Claude Code ou Codex.
2. Place le présent cahier dans `docs/cahier-prompts-v1.md` et le cahier des charges dans `docs/` si tu peux le fournir à l'outil. Le prompt 00 crée une référence textuelle de travail à partir des règles présentes ici, sans prétendre avoir lu un fichier absent.
3. Au début de chaque nouvelle conversation, colle le **contexte permanent**, puis le prompt du lot à traiter. Avec une conversation déjà initialisée, colle seulement le prompt du lot suivant.
4. Exécute les lots dans l'ordre. Un lot n'est terminé que si sa réalisation et sa validation sont documentées. Si un lot est trop volumineux, l'agent le divise en sous-lots et poursuit les étapes locales autorisées.
5. Utilise les prompts de revue, de correction ou de reprise en fin de document lorsque nécessaire.

Un lot correspond à un résultat fonctionnel, pas à une journée. Les quatre semaines sont un objectif de pilotage conditionné à l'effort disponible et aux résultats des contrôles. Aucune date ne transforme un test échoué ou une dépendance manquante en fonctionnalité validée.

**Avec deux outils :** attribuer un seul lot et une branche ou un worktree à chaque outil. Éviter les écritures simultanées dans les mêmes fichiers. Pour débuter, faire implémenter un lot par l'un et relire le diff par l'autre. La revue ne doit pas modifier silencieusement les fichiers pendant l'implémentation. Intégrer et vérifier chaque changement avant de commencer un lot dépendant.

## 2 Périmètre de référence

| Inclus dans la V1 | Limite de réalisation |
|---|---|
| Comptes, invitations, contextes et droits | Un compte peut appartenir à plusieurs établissements ; accès contrôlé côté serveur |
| Années, périodes, groupes, matières et affectations | Référentiels isolés et archivables |
| Planning individuel et institutionnel | Jour, semaine, récurrence hebdomadaire, exceptions, publication versionnée |
| Import Excel | Modèle XLSX, simulation, rapport, lot atomique et déduplication |
| Retour après cours et tâches | Retour privé, devoirs collectifs, progression personnelle et microtâches |
| Notes et moyennes | Provenance personnelle ou institutionnelle, calculs indicatifs expliqués |
| Rappels | Centre interne, courriel, Web Push lorsque configuré et compatible |
| Opportunités | Catalogue vérifié, recherche, favoris, alertes et démarches privées |
| PWA | Installation, lecture locale limitée et brouillons personnels synchronisables |
| Pièces jointes | PDF, JPEG, PNG, stockage privé, quotas et analyse antivirus |
| Administration et exploitation | Audit, demandes de droits, sauvegardes, restauration et supervision |

**Hors V1 :** Academy, bibliothèque de cours officiels, assistant IA, génération de cours, paiement intégré, abonnements automatisés, applications natives, réseau social, chat public, scolarité complète, présences, sanctions, frais scolaires, paie, dépôt de candidature au nom de l'utilisateur et collecte automatisée massive d'annonces. Le portail parental complet et Google/Microsoft SSO sont des évolutions ultérieures. SMS et WhatsApp ne sont pas activés dans ce périmètre.

Le nécessaire pour encadrer l'inscription de mineurs reste une condition d'ouverture du pilote concerné. Il ne faut ni inventer un âge légal ni activer automatiquement les inscriptions de mineurs avec une simple case de consentement.

## 3 Contexte permanent à coller

```text
Tu développes HAKO-RA Campus V1 dans le dépôt actuellement ouvert. Réalise les modifications et les vérifications, sans t'arrêter à un plan. Commence par lire les instructions applicables du dépôt, l'état Git et les documents de référence disponibles. Respecte les modifications existantes du développeur.

PRODUIT
Application francophone pour élèves et étudiants en Côte d'Ivoire, extensible à d'autres pays. Deux modes réellement utilisables : individuel sans établissement et établissement partenaire. Un compte unique peut cumuler des appartenances et rôles, avec un contexte actif explicite. Fondation comprend organisation scolaire et Magic Schedule ; Opportunités comprend catalogue éditorial et suivi personnel. Aucun paiement, Academy, assistant IA, application native ou réseau social dans la V1.

STACK
Laravel + React/TypeScript + Inertia + Tailwind + MySQL dans un monolithe modulaire. Préserver les versions compatibles déjà installées. Pour un dépôt neuf, vérifier les versions maintenues et compatibles dans les documentations officielles avant l'installation ; consigner les versions exactes et les lockfiles. Utiliser les mécanismes du framework avant d'ajouter une dépendance. Pas de microservices, Kubernetes ou serveur Next.js parallèle. Pas d'API dupliquant tous les écrans Inertia ; réserver les endpoints JSON aux besoins réels de synchronisation et de traitements asynchrones. Files et planificateur Laravel ; stockage privé. Aucun service payant ajouté sans décision existante qui le couvre.

CONFIDENTIALITÉ ET INTÉGRITÉ
Chaque requête, mutation, job, export, fichier et cache contrôle propriétaire, établissement, appartenance active et permission. Un institution_id envoyé par le client ne constitue jamais une autorisation. Les rôles appartiennent à un périmètre, pas à un simple champ global users.role. L'administrateur plateforme ne dispose pas d'un passe-droit pour lire notes privées, retours de cours et démarches. Ne pas créer d'impersonation ou d'accès de support privé dans cette V1 ; un éventuel mécanisme futur exigera une procédure distincte.
Les données personnelles et institutionnelles gardent une provenance explicite. La désactivation d'une appartenance retire ses accès sans supprimer le compte ou l'espace individuel. Aucun secret en sortie, dans Git, dans les fixtures ou dans les logs. Aucune donnée réelle d'élève dans les tests. Pas de fetch serveur arbitraire d'URL fournie par l'utilisateur.
Les erreurs JSON exposent un code stable, un message français, les champs concernés et un identifiant de requête, sans révéler de ressource étrangère au périmètre. Distinguer session absente, accès interdit, introuvable, validation, conflit de version, quota et fréquence. Les contrôles d'interface ne remplacent jamais ces règles serveur.

MÉTHODE
Garder les contrôleurs courts, les règles métier testables et les changements ciblés. Livrer frontend et backend utilisables ensemble. États chargement, vide, erreur, hors réseau, session expirée et succès ; formulaire préservé en cas d'échec. Déduplication serveur et contrôle de version pour les opérations rejouables. Écrire des tests utiles des règles et permissions, pas des tests qui reproduisent simplement le code.
Ne pas déclarer une intégration opérationnelle sur la seule base d'un mock. Une configuration externe manquante doit être visible, documentée et marquée non vérifiée. Ne pas contourner un contrôle pour faire passer les tests. Ne pas exécuter de migration destructrice, toucher à la production ou remplacer une configuration distante sans instruction explicite correspondante. Les développements locaux et leurs vérifications sont autorisés.

RÉFÉRENCES ET ARBITRAGES
Lire docs/cahier-prompts-v1.md et le cahier des charges lorsqu'ils sont présents. Suivre les décisions explicites les plus récentes du porteur. En cas de conflit matériel non résolu, documenter précisément le point et poursuivre les travaux indépendants. Choisir de façon autonome les détails réversibles compatibles avec le périmètre. Ne pas inventer de charte validée, partenaire acquis, tarif fournisseur, obligation légale ou capacité mesurée. Ne pas réduire silencieusement une exigence P0.

SORTIE ATTENDUE À CHAQUE LOT
1. Résultat fonctionnel et fichiers principaux modifiés.
2. Commandes de vérification réellement exécutées et résultat exact.
3. Parcours de contrôle manuel avec données fictives et résultat attendu.
4. Limites, blocages ou configurations externes restant à tester.
5. Mise à jour de docs/etat-v1.md et docs/recette-v1.md avec les exigences et tests couverts.
Poursuivre les étapes locales possibles et corriger les échecs liés au lot. Ne jamais écrire « tout fonctionne » si la preuve se limite à une compilation. Ne pas créer de commit, pousser, fusionner ou publier sans instruction correspondante ; préparer un diff reviewable.
```

## 4 Ordre de réalisation

| Étape | Lots | Résultat visé |
|---|---|---|
| Semaine 1 indicative | 00 à 06 | Base exécutable, identité, isolation et organisation |
| Semaine 2 indicative | 07 à 12 | Planning, import, retours, tâches et notes |
| Semaine 3 indicative | 13 à 18 | Rappels, fichiers et Opportunités |
| Semaine 4 indicative | 19 à 25 | PWA, confidentialité, exploitation et recette |

Traiter les contrôles critiques au fil des lots ; la dernière semaine ne doit pas être le premier moment où les droits et les calculs sont testés. Si l'avancement dépasse le mois, livrer une démonstration à données fictives ou ouvrir seulement la phase dont les exigences P0 sont satisfaites. Nommer les écarts et mettre à jour le calendrier.

## 5 Prompts de construction

### Prompt 00 Initialiser la référence et diagnostiquer le dépôt

```text
Initialise le travail sur Campus V1. Inspecte l'état réel du dépôt, les instructions locales, les versions disponibles, les scripts, l'authentification et l'éventuel code existant. Un dépôt existant doit être complété sans réinitialisation.

Crée ou complète :
- docs/specification-v1.md : décisions produit et stack, périmètre inclus/exclu, règles fonctionnelles de ce cahier ; citer la référence source disponible et signaler si le cahier des charges complet est absent.
- docs/architecture-v1.md : monolithe, modules, propriété des données, contextes, flux asynchrones et décisions techniques.
- docs/etat-v1.md : lots 00 à 25 avec statuts non commencé/en cours/implémenté/validé/bloqué ; distinguer local, recette et production.
- docs/recette-v1.md : exigences, scénarios R01 à R58, lot responsable, preuve et résultat ; jamais de cases validées à l'avance.
- docs/arbitrages-v1.md : décisions connues et points à clôturer, dont hébergement, budget, identité visuelle, mineurs, règles institutionnelles et conservation.

Si des AGENTS.md ou CLAUDE.md existent, respecte leur portée. Prépare des consignes projet communes compatibles, sans écraser les instructions existantes. Aucun verrouillage à un outil particulier et aucune obligation d'utiliser des sous-agents.

Établis les dépendances des lots, les commandes disponibles et les lacunes réelles. Vérifie que les exclusions ne se retrouvent pas dans le backlog V1. Livrer ces fichiers et poursuivre uniquement le travail couvert par ce lot.
Validation : référence compréhensible sans historique de conversation ; chaque lot a un résultat attendu et une place dans la recette ; aucune réalisation inventée.
```

### Prompt 01 Installer une application exécutable

```text
À partir du diagnostic 00, initialise ou complète Laravel + starter kit React/TypeScript + Inertia + Tailwind avec MySQL. Vérifie les versions compatibles auprès de leurs sources officielles et conserve les lockfiles. Utilise une base de développement dédiée ; ne touche pas aux données d'un autre projet.

Configure les environnements par .env.example sans secret réel, le français, les dates avec fuseau IANA et Africa/Abidjan comme valeur proposée de départ. Prépare file de jobs en base pour le pilote si aucun besoin ne justifie Redis, worker, scheduler, stockage privé et canaux de logs sans secrets. Documente les commandes d'installation, migration, développement et build dans README.md.

Configure une CI avec dépendances verrouillées, tests backend sur MySQL de service, typage, lint pertinent et build frontend. Pas de déploiement distant dans ce lot. Utiliser les outils de test existants ; ne pas multiplier les frameworks.
Validation : installation reproductible, écran de base accessible, migration sur base dédiée, build et vérifications réellement exécutés. Une absence de MySQL ou de réseau reste un blocage documenté, pas un succès supposé.
```

### Prompt 02 Construire la navigation et les composants communs

```text
Construis l'interface francophone de Campus avec la stack installée. Propose une présentation sobre, lisible et chaleureuse, adaptée aux étudiants ; l'identité visuelle reste une proposition modifiable. Aucun achat de police ou d'asset.

Navigation mobile : Accueil, Planning, Tâches, Opportunités, Profil. Notes accessibles depuis Accueil et l'espace d'études. Navigation ordinateur cohérente, contexte individuel/établissement toujours visible. Ajouter les entrées d'administration seulement avec permission. Ne pas afficher de boutons Academy, paiement ou IA inactifs.

Créer les composants utiles : champs et erreurs, sélection, dialogue de confirmation, liste/table responsive, pagination, filtres, badges de provenance, messages d'état, chargement et notification de succès. Les données de démonstration restent dans les seeders ou en environnement de développement ; ne pas simuler des statistiques dans l'application publiée.
Validation : 360 px, ordinateur, navigation clavier, focus, labels et zoom 200 %. Pas de défilement horizontal du parcours principal. Les états ne reposent pas uniquement sur la couleur. Brancher les liens vers de vrais écrans au fil des lots et signaler temporairement les routes non encore construites en développement.
```

### Prompt 03 Modéliser les données et les frontières de visibilité

```text
Implémente les fondations du modèle après examen du schéma existant. Décrire les frontières avant les migrations : compte ; espace individuel ; institution ; année ; groupe ; affectation ; données publiées ; données privées. Un rôle est porté par une appartenance contextualisée. Un utilisateur peut être apprenant dans A, enseignant dans B et indépendant sans créer plusieurs comptes.

Entités à prévoir progressivement : User, Institution, Membership, Invitation, AcademicYear, Period, Group, Enrollment, Subject, TeachingAssignment, CourseSeries, CourseOccurrence, SchedulePublication, ImportBatch, CourseCheckIn, Task, TaskProgress, Assessment, Grade, Attachment, Opportunity, OpportunityRevision, Favorite, SavedSearch, ApplicationTracker, NotificationDelivery, PushSubscription, SyncOperation, ConsentRecord et AuditLog. Éviter de créer tous les CRUD vides : migrer maintenant le socle et fixer les contrats nécessaires aux prochains lots.

Propriétaire et périmètre doivent rester explicites ; pas de tenant global implicite qui mélange espace personnel et institution. Contraintes et validations empêchent les relations entre années, groupes et établissements incompatibles. Les objets privés portent un propriétaire ; aucun null ambigu ne signifie « public ». Archivage des références utilisées et retraits d'accès datés. Indexer les accès fréquents, documenter les suppressions et cascades autorisées.

Crée fixtures : établissements A et B, indépendant, apprenant membre des deux, un enseignant affecté à un groupe de A, admins A/B et éditeur de catalogue. Mêmes matricules locaux possibles dans A et B. Validation sur MySQL : contraintes, absence de doublons actifs incohérents et conservation des données privées après retrait d'appartenance.
```

### Prompt 04 Sécuriser les comptes et les sessions

```text
Implémente inscription email, vérification, connexion, déconnexion, récupération non énumérante, expiration des liens, sessions consultables et révocables. Ne pas utiliser le matricule comme mot de passe ou identité globale. Aucun rôle privilégié choisi dans une inscription publique.

Prévoir les comptes provisionnés sans email individuel : identifiant unique, activation par secret personnel temporaire non journalisé, rattachement vérifié et changement initial du secret. Documenter et implémenter une récupération assistée avec contrôle d'identité par personnel habilité et trace limitée ; aucun mot de passe partagé ou email collectif utilisé pour récupérer toute une classe. Si ce parcours manque, marquer IAM02 bloquant pour les cohortes concernées.

MFA et codes de secours pour comptes privilégiés avec mécanisme maintenu compatible ; aucune intégration payante imposée. Réauthentification pour opérations sensibles, limitation de fréquence et cookies sécurisés selon environnement. Préparer le mécanisme configurable d'activation des parcours mineurs sans inventer de seuil légal ; ne pas ouvrir le recrutement réel avant validation du cadre.

Tests R01, R02 et R10 : connexion, vérification, récupération, expiration, limitation, révocation effective et second facteur. Tester aussi le provisionnement individuel. Documenter la configuration courriel absente, sans prétendre qu'un email a été reçu.
```

### Prompt 05 Mettre en œuvre invitations et isolation des contextes

```text
Construis les appartenances, invitations et le sélecteur de contexte. Seule la plateforme crée les établissements et habilite leur administration. L'admin d'établissement agit dans son périmètre ; l'enseignant dans ses groupes/matières ; l'éditeur dans le catalogue. Invitations à secret non devinable, stocké sous forme protégée, à durée limitée, révocable et consommé atomiquement.

L'acceptation propose d'utiliser le compte existant, vérifie le rattachement et n'accorde pas de rôle supplémentaire hors invitation. Matricule + établissement servent au rapprochement contrôlé ; les cas ambigus restent en attente. Changement de rôle privilégié confirmé, réauthentifié et audité. Retrait d'appartenance immédiatement effectif pour requêtes, exports, téléchargements et jobs, tout en conservant l'espace individuel.

Policies/autorisation serveur systématiques ; pas de Policy::before accordant tout à l'admin plateforme. Le changement de contexte client ne prouve aucun droit. Aucune lecture libre des retours privés, notes personnelles ou démarches par support/administration. Aucune impersonation.

Tests R03 à R09 et R58 : compte existant, invitation expirée/révoquée, matricule local identique, multi-appartenance, tentative d'escalade, retrait et accès par identifiant direct. Tester une mutation, un export et un job en contexte A visant B. Ne pas se limiter à masquer un menu.
```

### Prompt 06 Administrer les référentiels académiques

```text
Réalise les écrans et règles années académiques, périodes, groupes/classes, matières, inscriptions et affectations enseignants. Dates et fuseau propres à l'établissement, types de période configurables. Empêcher les rattachements incompatibles et chevauchements incohérents ; expliciter les périodes imbriquées autorisées.

Historiser entrées, sorties et changements de groupe. Archiver les références utilisées. La nouvelle année peut reprendre des référentiels sélectionnés, sans recopier notes ou tâches privées. L'indépendant doit créer ses matières et périodes personnelles sans établissement fictif ni matricule obligatoire.

Valider les actions côté serveur et construire des listes utilisables, avec recherche et états vides. Tests : admin A ne modifie pas B ; enseignant non affecté refusé ; inscription dupliquée évitée ; année archivée protégée ; sortie d'établissement sans perte de l'espace personnel.
```

### Prompt 07 Construire les cours et leurs occurrences

```text
Implémente planning jour/semaine et liste mobile, individuel et institutionnel. Une série hebdomadaire porte matière, jours actifs, heures locales, fuseau, bornes de validité, groupes, enseignant et salle/lien éventuels. Matérialise les occurrences sur une fenêtre bornée et documentée, renouvelable sans doublon. Stocke instants cohérents en UTC et fuseau d'origine. Tester aussi un fuseau avec changement saisonnier.

Chaque occurrence possède un identifiant stable. Autoriser événement personnel le week-end, annulation, report et modification d'une occurrence ou des occurrences futures. Préserver liens tâches/retours et historique. Gérer vacances/jours non travaillés et semaines actives. Pas de moteur de récurrence universel inutile.

Détecter conflits de groupe, enseignant et salle ; bloquer doublons exacts ; les autres chevauchements nécessitent confirmation motivée par un rôle autorisé. Un apprenant ne modifie pas le planning publié. Distinction visuelle des sources personnelle et institutionnelle.

Tests R11, R15, R17, R18 et R19 : récurrence bornée, fuseaux, conflit, report conservant l'identité, annulation et droits. Fenêtre paginée/bornée pour éviter toute génération illimitée.
```

### Prompt 08 Publier et réviser le planning institutionnel

```text
Ajoute brouillon, prévisualisation, publication transactionnelle et versions immuables du planning. Conserver auteur, date, motif et différences utiles. Gérer le retour à une version antérieure sans effacer l'historique, les tâches privées ou les identifiants stables déjà référencés. Réconcilier les occurrences et empêcher la réapparition d'un cours supprimé par erreur de copie.

Un enseignant affecté peut signaler une indisponibilité. Le signalement reste en attente jusqu'à décision de l'administration ; seule la publication autorisée annule, remplace ou reporte le cours. Les destinataires sont les membres autorisés concernés.

Produire les événements métier nécessaires au recalcul des rappels du lot 13, après validation transactionnelle. Une publication rejouée ne recrée ni versions identiques ni événements en double. Le rollback recalcule uniquement les alertes futures pertinentes et ne renvoie pas les anciennes.

Tests R16 et R20, plus concurrence de publication, droits, conservation des liens et comparaison avant/après. Interface de confirmation explicite avant publication et retour arrière.
```

### Prompt 09 Réaliser l import Excel du planning

```text
Crée le modèle XLSX documenté et son téléchargement. Colonnes minimales : code groupe, code matière, jour ou date, début, fin. Facultatives : enseignant, salle, validité, récurrence, identifiant externe stable. Annoncer formats et fuseau. Fichier sans macros.

Valider type réel, extension, 5 Mo maximum et 5 000 lignes maximum comme paramètres proposés, nombre de feuilles et limites de décompression/ressources. Aucun calcul ou exécution de formule importée. Signaler les références inconnues au lieu de créer silencieusement des matières ou comptes.

Flux : dépôt privé → job borné → simulation → rapport par feuille/ligne/colonne → corrections → brouillon → publication via le lot 08. Aucune modification du planning actif pendant l'analyse. Lot atomique : toute erreur bloquante empêche l'application entière. Rapport téléchargeable autorisé et sans injection de formule lors d'un export tabulaire.

Empreinte liée au périmètre et clé d'import empêchent la répétition. Une mise à jour s'appuie sur une clé externe stable ; sinon proposer les correspondances avant application. Les jobs vérifient les droits courants et exposent progression/échec. Purge du brut après 30 jours proposés.

Tests R12 à R16 : fin avant début, groupe inconnu dans un lot mixte, double soumission, conflit et retour arrière. Vérifier un import de 1 000 lignes avec mesure réelle ; ne pas annoncer une performance non mesurée.
```

### Prompt 10 Ajouter le retour privé après cours

```text
Construis le formulaire depuis le planning et l'accueil : résumé personnel, travail à effectuer, points à revoir, compréhension, pièces jointes lorsque lot 14 prêt. Champs facultatifs ; action « Rien à signaler ». États commencer, reporter, ignorer et non renseigné sans pénalité.

Un retour principal unique par propriétaire et occurrence, modifiable avec métadonnées d'historique limitées. Retour toujours privé ; pas d'accès enseignant/admin par défaut, pas de classement des difficultés, pas d'IA. La création facultative d'une tâche depuis le retour est atomique et dédupliquée, avec lien au cours.

Enregistrement avant le rappel annule les envois encore en attente. Cours annulé : pas de nouveau rappel. Report : même occurrence et même retour. Préparer contrat idempotent et version pour le brouillon hors connexion du lot 20.
Tests R21 à R23 pour la partie disponible, R58 et double clic. Marquer R23 incomplet tant que la synchronisation réelle n'est pas éprouvée. Le formulaire reste accessible sans réception de notification.
```

### Prompt 11 Gérer tâches et devoirs collectifs

```text
Implémente tâches personnelles, devoirs collectifs et microtâches. Champs : titre, description optionnelle, matière, échéance optionnelle, priorité, propriétaire/périmètre, cours source éventuel. États à faire/en cours/terminé/abandonné. Retard calculé, pas état remplaçant la progression. Filtres date, matière, priorité et statut ; tâches sans échéance visibles.

Un enseignant affecté publie un devoir pour son groupe/matière. Séparer contenu collectif et TaskProgress par apprenant. La progression de l'un ne modifie pas celle des autres. Date officielle inchangée par l'apprenant ; objectif personnel distinct autorisé. Changement collectif conserve les progressions et produit un événement de notification dédupliqué.

Suppression confirmée, corbeille/restauration proposée 7 jours, puis purge contrôlée. Terminer ou abandonner invalide les futurs rappels, y compris après rejeu de synchronisation. Contrôle de version, idempotence et messages de conflit.

Tests R24, R25 et R57, plus isolation du propriétaire, affectation enseignant, report personnel, suppression/restauration et double clic. Pas de plateforme de dépôt/correction de copies dans ce lot.
```

### Prompt 12 Calculer les notes et moyennes

```text
Construis notes personnelles et résultats publiés par un enseignant affecté, avec provenance distincte et visible. Les notes institutionnelles sont publiées uniquement aux apprenants concernés ; l'apprenant ne les remplace pas. Ne pas mélanger silencieusement une note personnelle représentant le même résultat et sa publication institutionnelle : séparer les vues/sources par défaut et définir toute sélection combinée explicitement, sans doublon implicite.

Valeur entre zéro et barème ; barème et coefficient d'évaluation strictement positifs ; matière, période, date et année cohérentes. Normalisation sur 20 = 20 × valeur / barème. Moyenne matière = somme(note normalisée × coefficient évaluation) / somme(coefficients retenus). Moyenne générale = somme(moyenne matière non arrondie × coefficient matière) / somme(coefficients des matières renseignées). Précision décimale suffisante ; arrondi uniquement à l'affichage, deux décimales proposées.

Note absente, à venir, absence et dispense distinctes de zéro. Aucun traitement institutionnel spécifique inventé ; afficher les calculs comme indicatifs et leur couverture. Pas d'annuel sans poids/règle validés. Seuil explicable de matière à surveiller, proposé 10/20, sans diagnostic de capacité. Recalcul après correction ou suppression ; audit des changements institutionnels sans journaliser les notes privées en clair.

Tests R26 à R30 : 14/20 coef 1 et 36/60 coef 2 donnent 12,67. Avec maths coef matière 3 et français 15 coef 2, général = 13,60 sur les valeurs non arrondies. Matière sans note n'abaisse pas la moyenne. Tester barème zéro, note zéro réelle, droits et années incompatibles.
```

### Prompt 13 Fiabiliser les rappels et leurs canaux

```text
Réalise centre interne de notifications, préférences, catégories, canaux, plages silencieuses, fuseau utilisateur et synthèses. Déclenchements : fin de cours +5 minutes par défaut réglable, modification de planning, tâche J-1/jour J réglable, synthèse quotidienne facultative, opportunités et favoris via lot 18. Aucun refus de Web Push ne bloque le produit. Séparer messages de compte/sécurité et communications facultatives.

Calcul serveur par scheduler et jobs, même onglet fermé. Transaction métier et événement asynchrone cohérents : outbox ou mécanisme durable équivalent avec rattrapage démontré. Recontrôler droits, préférences, statut, version et validité juste avant envoi ; invalider rappels obsolètes après report, retour renseigné, tâche terminée ou retrait d'appartenance. Les rappels périmés expirent après une panne ; pas de rafale historique.

Clés uniques utilisateur/événement/occurrence/type/canal, verrous ou contraintes adaptés à la concurrence, reprises bornées et suivi échec/expiration. Un timeout fournisseur peut laisser la livraison incertaine : ne pas promettre un exactement-une-fois externe ni renvoyer aveuglément ; utiliser l'idempotence fournisseur si disponible et tracer l'incertitude.

Courriel configurable ; Web Push avec consentement contextualisé, abonnement par appareil, révocation et nettoyage des endpoints invalides. Ne pas livrer de contenu scolaire sensible dans une notification visible sur écran verrouillé ; renvoyer à une page autorisée. Pas de SMS/WhatsApp actifs ou service facturant non autorisé.

Tests R18, R21, R22, R25, R43, R44 et R53 avec horloge contrôlée, jobs rejoués/concurrents, silence et fuseaux. Tester réellement les canaux externes lorsque configuration disponible ; sinon statut non vérifié et centre interne utilisable.
```

### Prompt 14 Protéger les pièces jointes

```text
Implémente dépôt PDF/JPEG/PNG, type réel + extension + taille, nom de stockage aléatoire indépendant du nom affiché. Paramètres proposés : 5 Mo/fichier, 3 fichiers/objet, 100 Mo/apprenant, avec vérification transactionnelle des quotas lors de dépôts concurrents. Refuser macros, formats actifs, exécutables et SVG non autorisés.

Stockage privé, parent et propriétaire autorisés à chaque opération, visibilité personnelle ou groupe explicite. Le changement de groupe d'une tâche ne rend pas public un fichier privé. Télécharger via contrôleur autorisé ; si URL temporaire utilisée, durée courte et contrôle documenté de son caractère partageable. Pas de fichier privé dans public/storage. Réponses de téléchargement empêchant exécution sur le domaine.

Analyse antivirus asynchrone avec états quarantaine/en analyse/sain/refusé/erreur. Un scanner indisponible ne valide jamais un fichier ; maintenir le refus de diffusion. Fournir configuration de recette et procédure de test du scanner sans diffuser de malware réel. Les mocks ne suffisent pas à déclarer le scanner actif en production.

Suppression, purge des orphelins, compteurs de quotas cohérents et signalement d'un contenu partagé litigieux avec circuit de retrait. Pas de téléchargement automatique des liens externes, pas de coffre de passeports/dossiers de candidature.

Tests R49/R50 : contenu déguisé, trop gros, quota concurrent, fichier en quarantaine, lien transmis sans droit et accès après retrait d'appartenance. Interface explicite d'attente/refus ; aucun contournement pour débloquer la démo.
```

### Prompt 15 Construire le catalogue éditorial Opportunités

```text
Réalise les fiches de bourse, concours, stage et formation, avec administration éditoriale. Champs : titre, catégorie, organisateur, URL source obligatoire, pays/modalité, niveaux et domaines, langues, conditions publiées, financement complet/partiel/non financé/non précisé, frais gratuit/payant/non précisé, dates et précision, lien de candidature éventuel, vérification datée, statut et campagne/édition.

Dates : distinguer instant avec fuseau, date seule, clôture continue et date non communiquée. Ne jamais inventer une heure officielle. Pour une date seule, définir une règle technique prudente et affichée pour l'état du catalogue ; elle n'est pas une heure de clôture garantie. Une date internationale n'est pas présumée en heure d'Abidjan.

Workflow brouillon → à vérifier → publié → suspendu/archivé. Publication exige source, organisateur, conditions minimales et acte explicite de vérification. La même personne peut rédiger et vérifier mais les deux actions sont tracées. Vérification éditoriale manuelle de l'accessibilité/authenticité ; pas de requête serveur arbitraire sur l'URL. Afficher « source vérifiée le », sans garantie d'obtention.

Révisions conservées, doublons par source/titre normalisé/organisateur/campagne, nouvelle édition séparée. Archivage après clôture connue ; prolongation garde ancienne/nouvelle date et invalide rappels dépassés. Texte nettoyé contre XSS ; URL HTTP(S) seulement. Ajouter fixtures clairement fictives en développement, jamais un catalogue d'opportunités prétendument actuelles.

Ajouter le signalement par fiche : lien cassé, date erronée, offre close, doute sur authenticité, autre. Limiter fréquence et taille du motif ; identité du signalant non publique. Construire une file éditoriale avec responsable, statut, commentaire interne, décision et historique. Un éditeur habilité peut suspendre une annonce suspecte ; un signalement isolé ne dépublie pas automatiquement sans règle validée. Conserver la preuve minimale du traitement. Suspendre retire l'offre des listes ouvertes et invalide les envois correspondants, tout en informant les détenteurs de favoris.

Tests R31 et R34 à R37 : publication incomplète bloquée, précision de date conservée, fuseaux, campagnes distinctes et prolongation. Visiteurs ne voient que les fiches publiées autorisées, jamais les brouillons.
Tester aussi R38, la séparation signalant/éditeur, le traitement complet d'un signalement et l'absence de fuite du commentaire interne. Un formulaire sans circuit de traitement ne valide pas la modération.
```

### Prompt 16 Rechercher et expliquer les correspondances

```text
Construis catalogue public, détail, recherche paginée et filtres visibles/réinitialisables : catégorie, niveau, pays, domaine, modalité, financement, langue et clôture. Recherche titre/organisateur/domaine ; tri publication, clôture proche et pertinence simple documentée. Pas de moteur externe payant.

Un visiteur consulte une fiche et sa source sans compte. Le profil connecté peut déclarer niveau, domaines, langues et critères utiles ; pays de résidence et nationalité distincts, facultatifs sauf nécessité démontrée. Règles de correspondance déterministes et explicables. Afficher correspond à vos critères / information à vérifier / critère non satisfait ; une donnée manquante ne prouve pas l'inéligibilité. Aucun critère sensible déduit automatiquement et aucune promesse d'éligibilité définitive.

Montrer financement, frais, précision des échéances, organisateur, date de vérification et domaine de destination. Action « Candidater sur le site de l'organisateur » sans transmettre de donnée privée. Fiches expirées/suspendues identifiées ; elles ne restent pas dans la liste ouverte par défaut.

Tests R32, R33, R34 et R40 : filtres combinés, profil incomplet, date inconnue et ouverture externe ne modifiant pas une démarche. Vérifier pagination, validation des paramètres et absence de brouillon dans recherche/publication.
```

### Prompt 17 Ajouter favoris et démarches privées

```text
Construis favoris uniques par utilisateur/opportunité, listes ouvert/bientôt clos/expiré/suspendu et retrait idempotent. Ajouter le suivi personnel depuis une fiche : à étudier, dossier en préparation, envoyée, en attente, retenue, non retenue, abandonnée. Changements manuels uniquement.

Une démarche contient échéance personnelle facultative, liste de pièces à préparer sous forme de texte/checklist et note privée courte supprimable. Aucun dépôt de passeport, document bancaire ou dossier sensible. Après archivage de la fiche, conserver un résumé daté minimal de référence nécessaire à la compréhension, sans copier intégralement la source externe.

Pas d'accès enseignant, établissement, autre utilisateur ou administrateur plateforme aux démarches privées. Cliquer sur candidater ouvre le site externe mais ne marque jamais « envoyée ». Même compte sur deux appareils : favoris cohérents, sans duplication. Préparer événements de changement de date et suspension pour lot 18.

Tests R38 à R41 et droits sur chaque endpoint. Vérifier favori concurrent, retrait répété, annonce archivée, note supprimée et absence de transmission d'identifiants au site externe.
```

### Prompt 18 Ajouter les recherches enregistrées et alertes Opportunités

```text
Sur la base du lot 13, permets d'enregistrer des filtres et choisir fréquence/canaux, puis arrêter les alertes en gardant les favoris. Produire des synthèses de nouvelles annonces correspondant aux critères, avec explication et lien source ; pas une rafale de tous les résultats à chaque exécution.

Dédupliquer par recherche/utilisateur/annonce/révision pertinente. Une modification importante peut produire un événement identifié comme mise à jour. Prévoir favoris J-7/J-1 réglables lorsque l'échéance est suffisamment précise. Annuler et replanifier après prolongation ; ne pas générer de rappel à heure inventée sur une date incertaine. Suspension informe les détenteurs concernés sans rendre l'annonce suspecte accessible comme offre ouverte.

Appliquer préférences, plages silencieuses, fuseaux, droits et limites d'envoi. Accès à mes critères et démarches reste privé. Les pages d'alertes montrent leur état et offrent désabonnement fonctionnel.

Tests R37, R38, R42 et R43 : tâche planifiée rejouée sans doublon métier, nouvelle annonce unique, mise à jour pertinente, désabonnement effectif, favoris conservés et aucun envoi d'annonce suspendue comme nouvelle offre.
```

### Prompt 19 Installer la PWA et le cache de lecture

```text
Implémente manifest, icônes originales simples, service worker et aide à l'installation selon capacité du navigateur. Ne pas promettre une application native. Prévoir message de mise à jour et stratégie de versionnement sans forcer un rechargement perdant un brouillon.

Sépare cache de coque publique et stockage local privé lié au compte. Ne jamais mettre en cache générique les réponses HTML Inertia authentifiées, routes admin, jetons, exports, pièces jointes ou réponses de comptes différents. Accueil hors ligne neutre si aucune donnée personnelle autorisée n'est stockée.

Stockage privé persistant uniquement après choix appareil personnel ; désactivé par défaut sur appareil partagé. Lecture limitée du planning synchronisé sur une fenêtre de 14 jours, tâches, notes déjà autorisées et opportunités consultées/favorites. Afficher date de synchronisation et caractère potentiellement périmé. Politique proposée : réévaluer/purger à l'ouverture après 7 jours sans contrôle, paramétrable et documentée. Une révocation distante ne peut pas effacer instantanément un appareil déconnecté ; expliquer la limite.

Pas de nouvel accès institutionnel, connexion, import, publication ou téléchargement hors ligne. Déconnexion/changement de compte purgent IndexedDB, caches privés et abonnements locaux pertinents. Éviter toute conservation de contenu privé dans un cache public/service worker partagé.

Tests R44, R47 et R55 : réseau coupé, appareil partagé, deux comptes successifs, refus d'installation ou push, droits réévalués au retour. Tester effectivement sur navigateurs disponibles, marquer les autres à vérifier.
```

### Prompt 20 Synchroniser les brouillons personnels

```text
Ajoute hors ligne création/modification de tâches personnelles et brouillon du retour post-cours. Notes en lecture seule hors ligne ; opérations institutionnelles en ligne uniquement. UI distingue enregistré sur cet appareil, en attente, synchronisé, refusé et conflit.

Chaque opération porte identifiant client unique, utilisateur, contexte, type, empreinte du contenu et version serveur connue. Endpoints JSON versionnés avec session/CSRF ou authentification adaptée documentée. L'unicité d'idempotence est liée à utilisateur + opération ; même clé avec contenu différent = conflit, pas nouvel objet. L'accusé rejoué renvoie le résultat déjà obtenu.

Serveur revérifie à chaque synchronisation propriétaire, appartenance, droit, validité du parent et version. Une version dépassée n'écrase rien : afficher différences utiles et conserver un brouillon récupérable par son propriétaire, sans redonner accès à des données institutionnelles révoquées. Les retours restent uniques utilisateur/occurrence.

Synchronisation au retour dans l'app, à la reconnexion et via bouton manuel ; ne pas dépendre uniquement de Background Sync. Logout avec brouillons : avertir des pertes possibles, offrir synchroniser si connecté ou abandon explicite, puis purger réellement. Mise à jour service worker et schéma local doivent préserver opérations en attente et gérer migration/repli.

Tests R23, R45 à R48, R56/R57 : réseau oscillant, envoi répété, deux appareils concurrents, retrait de droit avant envoi, déconnexion et nouvelle version PWA. Ne pas utiliser un « dernier arrivé gagne » silencieux pour résoudre les conflits.
```

### Prompt 21 Construire un accueil utilisable et les parcours complets

```text
Relie les modules déjà réalisés dans un accueil de prochaine action : cours du jour, retour à renseigner, tâches proches, notes/moyennes avec couverture et opportunités pertinentes lorsque le profil le permet. Affiche clairement source personnelle/institution et contexte. Pas de statistiques fictives, animation lourde ou inscription demandant trop d'informations.

Onboarding individuel : compte → profil minimal/fuseau → matière → cours → tâche → note → opportunité. Onboarding établissement : référentiels → import simulé → publication → invitation acceptée par compte unique → planning → signalement enseignant → décision admin. Profil et préférences, aide, support et notifications accessibles.

Complète chargements, listes vides, erreurs avec reprise, accès interdit, session expirée et succès. Préserver saisie après échec réseau, dédupliquer le double clic et avertir avant abandon d'une saisie. Les confirmations sensibles nomment l'objet ; pas de dialogue pour chaque lecture.

Teste les deux parcours dans un vrai navigateur avec les fixtures A/B et indépendant. Mesure si un débutant peut créer un cours puis une tâche en moins de 10 minutes, sans affirmer ce résultat sans essai. Élimine routes mortes et écrans faussement terminés. Toute API/module manquant devient une anomalie assignée, pas une donnée simulée en production.
```

### Prompt 22 Exercer les droits et maîtriser la conservation

```text
Réalise notice et paramètres de confidentialité, versions des notices et preuve des choix nécessaires. Les coordonnées du responsable, région d'hébergement et règles non fournies restent explicitement à compléter avant ouverture ; ne pas inventer de personne ni qualifier le service de légalement conforme sans vérification.

Demandes d'accès/export, rectification/opposition et suppression avec suivi et revérification proportionnée d'identité. Export limité aux droits du demandeur et données personnelles pertinentes, jamais établissement entier ou secrets. Fichier privé et lien expirant proposés 7 jours. Avant effacement : conséquences, export et réauthentification ; distinguer données privées effaçables et données institutionnelles dont la conservation doit être justifiée.

Implémenter les politiques proposées configurables : invitations et fichiers bruts d'import 30 jours après expiration/traitement ; notifications/livraisons 90 jours ; audit 12 mois ; corbeille tâches 7 jours ; revue d'inactivité 24 mois sans suppression automatique injustifiée ; sauvegardes glissantes 30 jours et cache local selon lot 19. Purges bornées, audit sans copie des données effacées, compteurs fichiers cohérents.

Prévoir journal minimal des effacements/révocations permettant leur réapplication après restauration, protégé et sauvegardé hors du seul point restauré. Documenter sa propre conservation. Pas d'annonce d'effacement instantané de toutes les sauvegardes. Après suppression, sessions, tokens et accès sont révoqués.

Garder le parcours réel des mineurs fermé tant que information, représentation/autorisation requise et preuve n'ont pas été validées pour le contexte applicable. Tester le contrôle technique sans inventer de règle juridique. Tests R51 et préparation R52 : export autorisé, lien expiré, suppression, retrait d'accès et réapplication des effacements.
```

### Prompt 23 Durcir et mesurer la V1

```text
Effectue une revue corrective ciblée des surfaces réelles : session/CSRF, validation, XSS des contenus, injection, autorisation directe, invitations, changement de rôle, exports, fichiers, jobs, sync et caches. Vérifier chaque accès croisé A/B et privé/autre utilisateur, y compris par admin plateforme. URL HTTP(S) validée sans fetch arbitraire ; aucun secret ou contenu privé complet dans logs, source client ou artefacts.

Corrige les défauts démontrés et ajoute un test de régression par risque important. Examine les audits de dépendances avec les outils présents ; traiter les alertes pertinentes avant ouverture, sans mise à jour majeure aveugle. Mesure les requêtes fréquentes et N+1 ; indexer et paginer selon résultats.

Cibles à vérifier dans un environnement décrit, jamais garanties anticipées : 5 000 comptes fictifs, 1 000 actifs/jour de référence ; charge mixte 100 utilisateurs pendant 30 minutes ; 95 % des API courantes sous 800 ms serveur ; erreurs techniques <1 % ; import 1 000 lignes sous 2 minutes ; contenu principal sous 3 secondes sur réseau simulé 1,6 Mbit/s et 150 ms. Ne pas lancer de charge sur un service tiers ou de production sans autorisation. Séparer smoke local et mesure complète.

Accessibilité : 360 px, clavier, zoom 200 %, contrastes, labels, erreurs associées et réduction des animations. Tester Chrome Android, Safari iOS, Chrome/Edge ordinateur lorsque disponibles ; signaler les configurations non testées et limites PWA par capacité. Ne pas revendiquer une conformité WCAG AA sur un seul outil automatique.

Tests R09, R43 à R50, R54, R55, R57 et R58. Produire docs/audit-v1.md : constats, gravité, preuve, correction, retest et risques encore ouverts.
```

### Prompt 24 Préparer le déploiement et éprouver la restauration

```text
Prépare une exploitation du monolithe sur un hébergement compatible, sans présumer ses accès ou sa capacité. Préparer configurations de serveur Web/PHP, HTTPS, MySQL non public, stockage privé, worker supervisé, scheduler, mail/push et scanner. Séparer local/recette/production et données fictives ; afficher version en service. Ne pas déployer à distance sans instruction et accès fournis.

CI et procédure de release : tests, typage/build, sauvegarde avant migration, migrations compatibles, contrôle après déploiement connexion/planning/tâche/fichier/worker/scheduler. Rollback du code distinct d'une restauration de base qui peut perdre des écritures. Documenter la marche à suivre après échec, sans commande destructive automatique.

Sauvegarde quotidienne base + fichiers, chiffrée et hors de l'instance principale, rétention proposée 30 jours, accès limité et gestion séparée des secrets. Exécuter une restauration complète dans un environnement isolé disponible, vérifier intégrité et fichiers ; sinon marquer l'exercice non réalisé. Réappliquer effacements et révocations, neutraliser anciens rappels puis reprendre seulement ceux encore valides.

Supervision séparée site, base, queue, heartbeat worker et scheduler, sauvegarde, scanner, disque, certificats et volumes d'envoi. Un accueil HTTP 200 ne prouve pas les rappels. Préparer seuils/alertes et contact à renseigner. Les cibles RPO 24 h, RTO 8 h ouvrées et disponibilité 99,5 % restent des objectifs à éprouver avec les moyens réels, pas un SLA promis.

Livrer docs/exploitation-v1.md, procédure d'incident, coûts observables sans tarif inventé, rotation clés mail/push et renouvellement domaine. Tests R52/R53 et preuves de restauration/retour des jobs.
```

### Prompt 25 Effectuer la recette et préparer le pilote

```text
Réalise la recette finale de Campus V1 en suivant la matrice R01 à R58. Pour chaque scénario, indiquer version, environnement, fixture, étapes, résultat attendu, résultat observé et preuve, avec statut passé/échoué/non exécuté/non applicable justifié. Relancer les vérifications nécessaires après les corrections, pas tous les outils sans raison.

Faire les parcours indépendant, apprenant multi-établissement, enseignant affecté, admin A/B et éditeur. Tester de bout en bout planning importé/publié, report, retour, tâche, moyenne, opportunité, favori, alertes, sync et fichier. Vérifier qu'aucune fonctionnalité hors V1 ni interface simulée n'a glissé dans la livraison. Lister explicitement toute exigence P0 incomplète.

Préparer docs/pilote-v1.md : installation PWA, guides apprenant/enseignant/admin/éditeur, canal de support à renseigner, formulaire d'anomalie sans secret, indicateurs d'activation/usage récurrent/imports/rappels et retour des utilisateurs. Proposition de cohortes, non partenaires acquis : 30 à 50 indépendants puis un lycée et une structure du supérieur, 50 à 100 actifs chacun. Monter progressivement selon capacité et cadre des mineurs validé.

Le catalogue réel sera ouvert avec un responsable éditorial désigné et 20 à 30 annonces pertinentes effectivement vérifiées ; ne pas fabriquer ces annonces à partir de fixtures. Ce remplissage n'est pas automatiquement couvert par la validation du logiciel.

Livrer le bilan de livraison : prêt pour démonstration fictive / prêt pour recette / prêt pour pilote Fondation / prêt pour ouverture Opportunités, selon preuves. Bloquants de sécurité, accès croisé, perte de données, calcul erroné, dépendance essentielle non vérifiée ou cadre d'accueil réel non finalisé empêchent l'ouverture concernée. Ne pas confondre « code implémenté » et « production validée ». Préparer la release sans la publier automatiquement.
```

## 6 Scénarios de recette à conserver

Cette matrice est la référence courte à recopier dans `docs/recette-v1.md`. Le résultat reste vide tant que le scénario n'a pas été exécuté. Le lot mentionné porte la vérification principale ; les lots suivants la complètent ou la retestent si affectée.

| Test | Situation | Résultat attendu | Lot principal |
|---|---|---|---|
| R01 | Inscription et vérification | Compte actif selon méthode autorisée | 04 |
| R02 | Mauvais secrets et récupération | Limitation sans révélation de comptes | 04 |
| R03 | Invitation et compte existant | Même identité, nouvelle appartenance | 05 |
| R04 | Invitation expirée ou révoquée | Aucun rattachement créé | 05 |
| R05 | Même matricule dans A et B | Aucun mélange d'identités | 05 |
| R06 | Un compte dans deux établissements | Contextes explicites et séparés | 05 |
| R07 | Tentative d'escalade de rôle | Refus serveur et trace appropriée | 05 |
| R08 | Retrait d'appartenance | Accès retirés, espace individuel conservé | 05 |
| R09 | Accès direct à une ressource de B depuis A | Refus sans fuite | 05, 23 |
| R10 | Compte privilégié | MFA et récupération contrôlée | 04 |
| R11 | Cours hebdomadaire récurrent | Occurrences exactes et bornées | 07 |
| R12 | Import avec fin avant début | Erreur localisée, pas de publication | 09 |
| R13 | Import mixte avec référence inconnue | Lot entier bloqué | 09 |
| R14 | Même fichier soumis deux fois | Aucun doublon | 09 |
| R15 | Conflit groupe, salle ou enseignant | Détection et confirmation autorisée | 07, 09 |
| R16 | Publication puis retour arrière | Planning cohérent et rappels recalculés | 08, 13 |
| R17 | Report d'une occurrence | Série préservée et identité stable | 07 |
| R18 | Cours annulé | Aucun rappel post-cours devenu inutile | 07, 13 |
| R19 | Élève modifiant un horaire officiel | Refus, événement personnel séparé possible | 07 |
| R20 | Indisponibilité enseignant | Décision admin avant effet publié | 08 |
| R21 | Fin du cours plus 5 minutes | Un rappel logique selon préférences | 10, 13 |
| R22 | Retour saisi avant alerte | Aucun rappel futur inutile | 10, 13 |
| R23 | Retour hors ligne rejoué | Un retour et état de sync exact | 20 |
| R24 | Un élève termine un devoir collectif | Les autres progressions restent identiques | 11 |
| R25 | Tâche terminée avant envoi du rappel | Rappel invalidé au contrôle d'envoi | 11, 13 |
| R26 | Notes 14/20 et 36/60 coefficients 1 et 2 | Moyenne 12,67 | 12 |
| R27 | Maths coef 3 et français 15 coef 2 | Générale 13,60 sans arrondi intermédiaire | 12 |
| R28 | Note absente, dispense et barème nul | Statuts distincts, zéro de barème refusé | 12 |
| R29 | Élève remplaçant une note institutionnelle | Refus et provenance visible | 12 |
| R30 | Note rattachée à une période incompatible | Validation refusée | 12 |
| R31 | Publication sans source ou organisateur | Publication bloquée | 15 |
| R32 | Recherche avec plusieurs filtres | Résultats et filtres cohérents | 16 |
| R33 | Profil incomplet | Information à vérifier, pas d'inéligibilité inventée | 16 |
| R34 | Date seule, inconnue ou clôture continue | Précision d'origine conservée | 15, 16 |
| R35 | Clôture avec autre fuseau | Bon instant d'archivage | 15 |
| R36 | Nouvelle édition annuelle | Campagne séparée et historique conservé | 15 |
| R37 | Prolongation d'un favori | Date et rappels révisés sans doublon | 15, 18 |
| R38 | Offre signalée puis suspendue | Retrait de l'ouvert et information utile | 15, 18 |
| R39 | Favori ajouté sur deux appareils | Une seule relation | 17 |
| R40 | Clic sur candidature externe | Aucune transition automatique à envoyée | 16, 17 |
| R41 | Autre utilisateur lisant une démarche | Refus sans exposition privée | 17 |
| R42 | Désabonnement à une recherche | Alertes arrêtées, favoris conservés | 18 |
| R43 | Rejeu d'un worker après interruption | Déduplication métier et livraison suivie | 13, 18 |
| R44 | Notifications refusées sur téléphone | Service principal et centre interne utilisables | 13, 19 |
| R45 | Deux modifications hors ligne concurrentes | Conflit visible, aucune perte silencieuse | 20 |
| R46 | Déconnexion avec brouillon en attente | Choix informé puis purge effective | 20 |
| R47 | Deux comptes successifs sur appareil partagé | Aucun contenu privé transmis entre comptes | 19, 20 |
| R48 | Droit retiré avant sync | Rejet et brouillon personnel préservé si possible | 20 |
| R49 | Fichier non autorisé, gros ou détecté dangereux | Refus ou quarantaine effective | 14 |
| R50 | Téléchargement privé sans droit | Refus ou expiration appropriée | 14 |
| R51 | Export puis effacement | Accès contrôlé, traitement et preuve | 22 |
| R52 | Restauration complète | Données vérifiées, révocations réappliquées | 24 |
| R53 | Reprise après longue panne | Anciennes alertes expirées, utiles préservées | 13, 24 |
| R54 | Charge et import de référence | Mesures comparées aux cibles | 23 |
| R55 | Clavier, zoom et mobile | Parcours utilisables et texte lisible | 02, 23 |
| R56 | Mise à jour PWA et opérations en attente | Migration sans perte silencieuse | 20 |
| R57 | Échec réseau puis reprise formulaire | Saisie conservée et création unique | 11, 20, 21 |
| R58 | Admin tentant de lire un retour privé | Refus standard sans accès support implicite | 05, 10, 23 |

## 7 Prompts de contrôle et de reprise

### Revue d un lot par le second outil

```text
Relis le diff du dernier lot Campus à partir de son objectif et des règles de docs/specification-v1.md. Compare le code, les migrations, les composants et les tests à l'existant. Ne modifie aucun fichier dans cette revue.
Cherche d'abord les bugs concrets : isolation A/B, accès privé, permissions des jobs/exports/fichiers, migration dangereuse, doublon après rejeu, calcul erroné, fuseau, cache entre comptes et perte de saisie. Pour chaque constat, donner gravité, fichier/ligne, déclencheur, impact et correction recommandée. Éviter les préférences stylistiques sans effet.
Exécute les contrôles pertinents disponibles en lecture seule ou sur environnement de test isolé. Indique exactement lesquels et leurs limites. S'il n'y a pas de défaut démontré, le dire sans garantir l'absence de bug ; citer les scénarios restant non vérifiés. Proposer un verdict : intégrable / corrections requises / preuve insuffisante.
```

### Corriger une anomalie sans casser les règles

```text
Corrige l'anomalie décrite dans le dernier compte rendu ou la dernière sortie d'erreur. Commence par reproduire dans l'environnement de test et expliquer sa cause à partir des faits. Corrige au niveau pertinent, ajoute un test utile de non-régression, puis rejoue le scénario et les règles voisines affectées.
Ne désactive pas une Policy, CSRF, MFA, validation, scanner, contrainte d'unicité ou test pour faire disparaître l'erreur. Ne réinitialise pas la base et ne remplace pas un module entier si une correction ciblée suffit. Vérifie le diff existant pour ne pas écraser le travail en cours. Mets à jour l'état, les preuves et les éventuelles migrations nécessaires. Une erreur non reproductible reste qualifiée comme telle.
```

### Reprendre une nouvelle session

```text
Reprends Campus V1 sans recommencer les travaux terminés. Lis les instructions applicables, Git, docs/specification-v1.md, docs/architecture-v1.md, docs/arbitrages-v1.md, docs/etat-v1.md et docs/recette-v1.md. Inspecte le code réellement présent pour les lots concernés ; les cases de suivi seules ne prouvent rien.
Identifie le dernier lot validé, le lot en cours, les modifications non intégrées et les blocages documentés. Reprends le prochain sous-lot dont les dépendances sont satisfaites. N'installe pas une nouvelle stack, ne régénère pas le dépôt et ne remplace pas les versions sans raison démontrée. Si une preuve manque, effectuer le contrôle ciblé avant de revendiquer une validation. Donne un bref état puis réalise le travail local autorisé.
```

### Faire un point sur le délai

```text
Évalue l'avancement Campus à partir des preuves. Sépare implémentation, tests automatisés, recette navigateur, intégrations externes, exploitation et conditions d'ouverture réelle. Liste les parcours utilisables maintenant, les P0 manquants, leurs dépendances et le travail restant en unités concrètes ; pas de pourcentage fictif.
Propose l'ordre des prochaines actions pour obtenir un pilote utile. Si le mois ne suffit plus, distinguer démonstration fictive, pilote Fondation et ouverture Opportunités, avec conditions précises. Ne supprimer aucune exigence P0 ni annoncer une plateforme sécurisée sur le seul fondement du build. Toute proposition de réduction de périmètre doit rester visible dans le registre d'arbitrages et nécessite une décision produit avant d'être considérée comme acceptée.
```

## 8 Première action

Commencer par le contexte permanent et le **prompt 00**, puis le **prompt 01** après lecture du diagnostic. Fournir à l'outil uniquement le dossier du projet Campus et des données fictives. Les domaines, secrets, comptes fournisseurs et accès de production seront renseignés dans les environnements appropriés au moment utile.

La V1 est livrable lorsque les parcours retenus fonctionnent, que leurs contrôles sont prouvés et que les dépendances d'ouverture sont satisfaites. Un cahier de prompts organise le travail ; il ne garantit ni un délai fixe ni la qualité d'un code qui n'a pas été vérifié.
