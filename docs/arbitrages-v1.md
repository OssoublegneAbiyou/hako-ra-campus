# Arbitrages V1

15 novembre 2026. Confirmé désigne une décision produit, pas une implémentation. Sources : décisions récentes du porteur et PDF v1.0 chap.2/30.

## Décisions connues

| ID | Décision et origine |
|---|---|
| D01–D10 | PDF et porteur : application unique, secondaire/supérieur, Fondation puis Opportunités, individuel/établissement, compte unique, Web/PWA, cohortes parallèles, Magic Schedule et import Excel |
| D11 | PDF : rappel 5 minutes par défaut configurable |
| D12-V1 | Porteur : centre interne, courriel/Push configurés ; SMS/WhatsApp non activés ni dépendances V1 |
| D13–D16 | PDF : retour d'action, confirmation sensible, fichiers privés, dimensionnement progressif et absence de paiement ; capacité/coûts non validés |
| T01 | Porteur : Laravel + React/TypeScript/Inertia + Tailwind + MySQL, monolithe ; remplace les alternatives techniques du PDF |
| T02 | Porteur : JSON aux besoins sync/asynchrones, pas une API parallèle pour tous les écrans |
| S01 | Porteur : pas de bypass privé, support privé ou impersonation ; R58 attend toujours refus |
| M01 | Porteur : cadre mineurs avant admission concernée ; aucun âge légal inventé ni activation par simple case |
| P01 | Porteur : quatre semaines indicatives, contrôles au fil des lots ; démonstration fictive ou phase P0 reçue uniquement |
| G01 | Porteur : aucun commit/push/fusion/publication, production ou migration destructrice sans instruction correspondante |

## Points à clôturer

| ID | Sujet | Décision / responsable à désigner | Avant | Statut |
|---|---|---|---|---|
| A01 | Hébergement/région | Porteur/technique : isolation, capacité, transferts et fournisseurs | données réelles | ouvert |
| A02 | Budget | Porteur : plafond, devis et temps humain/éditorial | service payant | ouvert |
| A03 | Établissements pilotes | Porteur/référents : partenaires réels et titulaires | import effectifs | ouvert |
| A04 | Capacité et calendrier | Porteur/équipe : heures disponibles et jalons | engagement de date | ouvert |
| A05 | Identité visuelle/domaine | Porteur : charte, nom de domaine et droits | maquettes finales/diffusion | ouvert |
| A06 | Mineurs | Personnes qualifiées/porteur : information, représentation, autorisations/preuves et contrôles adaptés | inscription concernée | ouvert, bloque le pilote concerné |
| A07 | Canaux | Porteur/technique : SMTP/Push et compatibilité testés ; limites visibles | recette alertes | ouvert |
| A08 | Règles institutionnelles | Référent habilité : coefficients, absence/dispense, périodes, annuel et délégations | activation concernée | ouvert |
| A09 | Conservation/quotas | Responsables des finalités/technique : durées et volumes justifiés, purges | production | ouvert |
| A10 | Revenus après pilote | Porteur : décision commerciale ultérieure, hors dépendances V1 | après pilote | différé hors V1 |
| A11 | Données et formalités | Responsables à nommer : finalités individuel/établissement, notices, droits et transferts | données réelles | ouvert |
| A12 | Exploitation/support | Titulaire/suppléant : incidents, couverture, sauvegarde et restauration | pilote | ouvert |
| A13 | Éditorial | Responsable à nommer : vérification, révisions et modération | Opportunités | ouvert |
| A14 | Cibles et appareils | Technique/porteur : environnement, mesures, navigateurs et dérogations | recette qualité | ouvert |
| A15 | Git | Porteur : dossier sans dépôt ; initialisation à décider séparément | historique durable | ouvert |
| A16 | Prompts suivants | Rapprocher prompts 01–25 reçus du découpage provisoire | chaque lot | ouvert |

## Écarts et traitement
- PDF p.8 et R58 permettent accès exceptionnel au privé : S01 le supprime pour V1. Pas de mécanisme dormant d'impersonation.
- PDF propose MySQL/PostgreSQL et de nombreuses routes API ; T01/T02 imposent MySQL/Inertia, en conservant contrats de sécurité/idempotence.
- PDF D12 évoque SMS/WhatsApp : exclus de l'activation et des dépendances V1.
- PDF macro-lots L0–L6 : 55–80 jours effectifs proposés ; quatre semaines du porteur : ordre indicatif sans capacité fournie. Aucun P0 réduit pour ce délai ; démo fictive ou réception partielle par phase.
- Prompts 01–25 absents ; intitulés/dépendances proposés dans état, à ajuster sans masquer écart.
- Incident TLS Bunny ancien non reproduit au build lot 00 ; résolution durable non prouvée.

## Paramètres et références
Durées, quotas et cibles de specification-v1.md restent propositions, pas délais légaux/capacités mesurées. Enveloppes budgétaires PDF non assimilées à devis ; cohortes non assimilées à partenaires acquis.
Références externes juridiques et navigateur du PDF non revérifiées dans ce lot documentaire ; aucune conclusion juridique ou garantie de compatibilité n'en est tirée.

## Décisions futures
Consigner date, décideur habilité, source, décision, exigences/tests et lots affectés. Conserver le motif des écarts. Un arbitrage bloque ses seules dépendances ; poursuivre les travaux indépendants autorisés. Aucune dérogation P0 implicite ou déclaration de phase reçue sans preuve.


## Décisions techniques locales du lot 01
- T03 : intégration progressive du socle officiel React/Inertia à l'existant, sans copier le kit complet ; auth/Fortify au lot 02. Aucun élargissement du périmètre.
- T04 : queue database et InnoDB par configuration Campus, sans Redis ni changement global MySQL.
- T05 : UTC côté serveur, Africa/Abidjan proposé pour affichage ; paramètres individuels/institutionnels futurs.
- T06 : polices système pour compilation sans fetch Bunny ; présentation provisoire, aucune charte validée.
- T07 : ESLint 10 maintenu et override minimatch ^11.0.8 après avis de sécurité ; revoir quand l'amont est corrigé.
- A16 : Prompt 01 désormais reçu et réalisé. Les intitulés 02–25 restent proposés jusqu'aux prompts détaillés.
- GitHub CI, services permanents et validation visuelle restent non vérifiés ; aucune ouverture réelle autorisée par le socle seul.


## Décisions du Prompt 02 — 15 novembre 2026
- P02 : lot 02 = navigation et composants communs, remplace le découpage provisoire identité. Identité non commencée, regroupement proposé au 03 jusqu'au prochain prompt ; aucun P0 supprimé.
- V01 : présentation vert profond/tons clairs proposée, librement modifiable, polices système et icônes SVG locales ; aucune charte prétendument validée ni achat.
- V02 : contexte réel actuel individuel/visiteur ; pas de sélecteur simulant une appartenance. Préparer le rendu institutionnel sans ouvrir d'accès.
- V03 : écrans en attente et atelier uniquement local/testing ; production sans liens actifs vers fonctions non construites. Administration visible seulement avec permission serveur et route existante.
- Q01 : Edge, clavier, 360/768/1440 et repli équivalent 200 % vérifiés ; zoom natif par menu, Safari et lecteur d'écran restent à confirmer. Ne pas assimiler ces vérifications à une conformité WCAG complète.

## Décisions du Prompt 03 — 15 novembre 2026
- P03 : lot 03 = fondations du modèle/frontières, remplace son intitulé provisoire identité/contextes/permissions. Les prompts 04–25 restent à rapprocher des contenus reçus ; l'authentification n'est pas déclarée livrée.
- T08 : Membership institutionnel par année et rôle, rôles plateforme séparés ; groupe/matière via Enrollment/TeachingAssignment. Pas de rôle global User, pas de tenant global.
- T09 : unicité SQL des relations en cours, y compris suspendues, via colonnes générées ; lignes closes conservées et créneau libéré. Les expirations sont contrôlées à la lecture avant clôture administrative éventuelle.
- T10 : RESTRICT partout dans le socle, aucune cascade autorisée ; archivage et retrait daté. Effacement de compte et suppression de ses dépendances nécessiteront leur procédure distincte.
- T11 : espace individuel concret PersonalSpace, propriétaire obligatoire ; futur provisionnement atomique avec création du compte. Les entités métier absentes restent des contrats, pas des tables/CRUD vides.
- A17 : années d'appartenance, types/chevauchements de périodes, délégations et politiques officielles doivent être complétés selon A08 dans les lots de gestion ; la fondation ne prétend pas clôturer tous ORG/IAM.
- A18 : email non nullable du squelette conservé pour le moment ; IAM02 reste indispensable, à traiter avec identifiant provisionné et récupération sûre, pas en inventant une identité partagée.
- Q02 : contraintes, garde de base du seeder et frontières testés sur MySQL réel. Pas de nouvelle validation navigateur, d'authentification ou de recette de production revendiquée au lot 03.


## Décisions du Prompt 04 — 15 novembre 2026
- A16 clos : cahier de prompts complet reçu et placé dans docs/cahier-prompts-v1.md ; cahier des charges PDF dans docs/. Lots 04 à 25 de etat-v1.md et lots responsables de recette-v1.md réalignés.
- P04 : lot 04 = comptes et sessions. Décision du porteur : aucune dépendance Composer ajoutée. Conséquence : second facteur et codes de secours non livrés ; IAM07 et R10 bloquants pour tout compte privilégié réel.
- S02 : en l'absence de second facteur, les actions privilégiées sont refusées partout. Seule dérogation : local/test avec CAMPUS_PRIVILEGED_WITHOUT_MFA=true, pour travailler sur des données fictives. Jamais en production.
- T12 : authentification écrite avec les mécanismes natifs de Laravel (garde session, broker de réinitialisation, liens signés, RateLimiter, password.confirm, auth.session). Pas de Fortify.
- T13 : compte provisionné = email nul + identifiant HK- aléatoire, distinct du matricule ; secret temporaire de 12 caractères, haché, valable 72 h (proposé), utilisable uniquement sur l'écran d'activation. A18 clos.
- T14 : récupération assistée par l'administration du même établissement et de la même année, après réauthentification, contrôle d'identité déclaré et confirmé ; trace audit_logs limitée à l'acteur, au compte, à l'établissement et au type de contrôle.
- T15 : table audit_logs migrée dès ce lot, en ajout seul. Conservation proposée 12 mois, purge à traiter au lot 22.
- M02 : parcours mineurs fermé tant que CAMPUS_MINORS_ENABLED et CAMPUS_MINORS_FRAMEWORK_REFERENCE ne sont pas tous deux renseignés. Aucun seuil d'âge codé ; déclaration de majorité à l'inscription et attestation au provisionnement. A06 reste ouvert.
- A19 ouvert : choisir un service d'envoi de courriels (A07) avant toute inscription réelle ; sans lui, aucun compte par email ne peut être vérifié.
- A20 ouvert : décider du mécanisme de second facteur (bibliothèque TOTP ou autre) ; tant qu'il manque, aucun pilote établissement ni éditorial n'est ouvrable.
- A21 ouvert : l'inscription révèle qu'une adresse est déjà utilisée (message d'unicité). Le cahier n'exige la non-révélation que pour la récupération ; à arbitrer si un parcours d'inscription non énumérant est voulu.

## Décisions du Prompt 05 — 15 novembre 2026
- P05 : lot 05 = invitations et isolation des contextes, réalisé sans second facteur ; S02 s'applique à toutes les nouvelles routes d'administration.
- T16 : secret d'invitation porté par le lien, stocké en empreinte SHA-256 (secret aléatoire de 256 bits, donc sans hachage lent) ; validité proposée 14 jours.
- T17 : cas ambigus = matricule déjà rattaché dans le même établissement et la même année, ou adresse du compte différente de l'adresse invitée. Ils restent en attente de contrôle sans accès. Une invitation sans adresse ni matricule est rattachée directement : le lien vaut alors preuve, à remettre en personne.
- T18 : matrice d'habilitation. Administration d'établissement : apprenant, enseignant. Plateforme : administration d'établissement, éditeur du catalogue. Le rôle d'administration de la plateforme ne s'accorde par aucun écran.
- T19 : contexte actif en session, relu en base à chaque requête ; hors périmètre = 404, mauvais rôle = 403.
- T20 : pas de suspension ni de modification de rôle en place dans ce lot ; retrait puis nouvelle invitation. À réexaminer au lot 06.
- T21 : vue racine en directives @inertia, jamais en composants <x-inertia::*>, qui écrivent les données de page sur disque.
- A22 ouvert : amorçage du premier administrateur de la plateforme en environnement réel (aucun écran ne l'accorde) ; procédure d'exploitation à définir au lot 24.
- A23 ouvert : faut-il imposer une adresse ou un matricule sur toute invitation, pour ne jamais rattacher sur la seule possession du lien ?
- A15 : dépôt Git initialisé localement à la demande du porteur ; envoi vers GitHub non effectué (le compte mémorisé sur le poste n'a pas accès au dépôt).

## Décisions réversibles du lot 06 — 16 novembre 2026
- Périodes : dates inclusives, imbrication seulement via parent explicite et sans chevauchement entre frères ; types configurables mais sans moteur de calendrier universel. Les racines personnelles peuvent décrire des années successives disjointes.
- Un apprenant a un seul groupe actif sur une même fenêtre d'année ; changement de groupe déjà effectif, daté et transactionnel. Les groupes multiples spécialisés nécessiteraient une règle explicite avant extension.
- Administration dans l'année du contexte courant. Une nouvelle année créée par cet administrateur lui donne seulement à lui un contexte de continuation, tracé ; les autres membres passent par les invitations existantes.
- Reprise des périodes par décalage en jours depuis le début de l'année source ; rejet si les bornes ne tiennent pas dans la cible. Matières réutilisables au niveau établissement ; aucun contenu privé ni inscription repris.
- Aucun effacement des références exposé. Clôture conserve les dates ; annulation future garde closed_at sans fabriquer une sortie avant l'entrée.
- MFA non livré : dérogation uniquement locale/test, jamais ouverte en production. Courriels non reçus : limite des lots 04/05 maintenue.


## Décisions réversibles du lot 07 — 16 novembre 2026
- Une plage locale par jour actif ; ponctuel ou hebdomadaire. Événement traversant minuit non pris en charge dans ce moteur ; durée de 12 h maximum. 730 dates de validité maximum depuis l'ancrage initial et fenêtre renouvelable de 56 jours, sans génération universelle ou sans borne.
- Semaines actives indiquées par leurs lundis ISO ; jours non travaillés et vacances propres à la série. Pas de calendrier national prétendu officiel ni de vacances inventées.
- Heures locales inexistantes ou répétées lors d'un changement saisonnier : refus explicite. L'utilisateur peut choisir une autre heure ou exclure la date ; aucune interprétation silencieuse.
- Révision du futur à partir de la date d'origine ; dernière opération applicable prioritaire, exceptions manuelles conservées. Une occurrence retirée reste calendar_excluded ; annulation manuelle reste cancelled jusqu'au rétablissement explicite.
- Conflits dans le seul périmètre autorisé. Doublon institutionnel identifié par instants, matière, enseignant, groupes et salle, même si le titre change ; pour le personnel, titre également comparé. Les autres chevauchements nécessitent confirmation et motif de 10 caractères minimum, par propriétaire/admin seulement.
- Dans ce lot, enseignant affecté consulte les brouillons et ne modifie pas le planning institutionnel ; apprenant ne les voit pas. Publication versionnée et signalement d'indisponibilité au lot 08, sans prétendre cet accès opérationnel maintenant.
- Contrat des liens privés futurs : UUID d'occurrence conservé et suppression restreinte ; intégration effective aux tâches/retours à prouver lors de leurs lots. Rappels non livrés ici.


## Décisions du Prompt 08 — 16 novembre 2026
- P08 : lot 08 = publication et révision du planning institutionnel, réalisé sans second facteur ; S02 s'applique.
- T22 : le brouillon reste la table des occurrences ; le publié est une projection (published_occurrences) reconstruite par des versions immuables stockant les différences. Pas de copie complète du planning par version.
- T23 : publication conditionnée à la version attendue et à l'empreinte de la prévisualisation ; clé de demande pour le rejeu. Un brouillon identique au publié ne crée pas de version.
- T24 : un cours jamais publié et déjà annulé ou exclu n'est pas publié : il n'a jamais été annoncé.
- T25 : le retour arrière ne touche que les cours à venir et réaligne le brouillon ; un cours retiré garde sa ligne, marquée exception, pour ne jamais être recréé ni republié par erreur.
- T26 : boîte d'événements transactionnelle domain_events, retenue pour le lot 13 ; clé de déduplication par version et par cours.
- T27 : apprenants = publié uniquement ; enseignants = publié par défaut, brouillon en consultation ; modifie le comportement par défaut du lot 07 pour l'enseignant.
- T28 : le signalement d'indisponibilité est une action d'enseignant soumise à la même fermeture que les autres rôles privilégiés tant que le second facteur manque.
- A24 ouvert : faut-il revalider conflits et références lors d'un retour arrière, ou seulement les signaler ?
- A25 ouvert : contexte plateforme actif et accès au planning personnel (403 aujourd'hui) ; comportement à décider.
- A26 ouvert : limite d'occurrences par publication (20 000 proposée) à mesurer au lot 23.

## Décisions déléguées par le porteur — 16 novembre 2026
Le porteur a demandé à l'agent de trancher A24 et A25 et d'en rendre compte. Décisions prises, réversibles :
- A24 clos — retour arrière : il n'est jamais bloqué. Un cours à rétablir comme prévu dont l'enseignant n'est plus habilité ou affecté, ou dont un groupe ou la matière est archivé, n'est pas rétabli ; il garde son état actuel et il est compté (« skipped ») dans le résumé de la version. Les cours rétablis qui chevauchent un autre cours actif partageant groupe, enseignant ou salle sont comptés (« overlaps ») et signalés à l'administration, sans empêcher l'opération. Si tous les cours concernés sont invalides, rien n'est écrit et le motif est affiché.
- A25 clos — contexte plateforme : /planning et /etudes/referentiels y affichent le planning et les références personnels du titulaire au lieu de répondre 403. Ce contexte ne donne toujours aucun accès institutionnel ; la publication lui reste interdite.
- Non tranché par cette délégation : le second facteur (A20). Le porteur avait refusé toute dépendance ; R10 reste bloqué.

## Décisions du Prompt 09 — 16 novembre 2026
- P09 : lot 09 = import Excel du planning, réalisé sans second facteur ; S02 s'applique.
- T29 : lecture et écriture XLSX natives (extensions zip et xmlreader), aucune bibliothèque ajoutée, conformément au refus de dépendance du porteur. Conséquence assumée : seul le sous-ensemble utile du format est pris en charge (texte, nombres, dates et heures Excel des colonnes prévues).
- T30 : une cellule contenant une formule est une erreur bloquante ; sa valeur calculée n'est jamais lue.
- T31 : unicité du fichier par empreinte et périmètre ; un fichier identique renvoie le lot existant. Après correction des référentiels, on relance l'analyse du lot au lieu de le redéposer.
- T32 : mise à jour par identifiant externe ; sans lui, correspondance sur groupe, matière et jour, soumise à décision explicite. Aucune mise à jour implicite.
- T33 : simulation et application en file ; l'application revérifie droits et références et exige que le résultat soit identique à la simulation, sinon elle s'annule en entier.
- T34 : conflit non exact = confirmation motivée du lot entier ; doublon exact = erreur bloquante.
- T35 : optimisation de la détection de conflits du lot 07, à comportement constant, motivée par la mesure.
- A27 ouvert : cible de durée (R54). 121,3 s mesurées sans débogueur pour 1 000 lignes sur un poste de développement ; à mesurer sur l'environnement cible et à optimiser si nécessaire au lot 23.
- A28 ouvert : code enseignant stable dans les référentiels, pour éviter d'écrire des adresses électroniques dans les fichiers d'import.
- A29 ouvert : essai avec de vrais fichiers Excel, LibreOffice et Google Sheets d'un établissement pilote avant tout import réel.

## Décisions du Prompt 10 — 16 novembre 2026
- P10 : lot 10 = retour privé après cours. Aucun rôle privilégié requis : utilisable sans second facteur.
- T36 : le retour appartient au compte, pas au contexte. Il se renseigne depuis n'importe quel contexte actif ; l'accès au cours est vérifié par inscription effective à un groupe du cours publié, ou par propriété de l'événement personnel.
- T37 : aucune route par identifiant de retour ; l'adresse est celle du cours, toujours résolue pour le compte connecté. Un tiers reçoit 404, qu'un retour existe ou non.
- T38 : le retour s'ouvre au début du cours ; il n'est jamais créé sur un cours annulé ; un retour existant reste lisible et modifiable par son propriétaire même après perte d'accès au cours.
- T39 : « ignoré » et « reporté » ne s'appliquent qu'à un retour non renseigné ; « ignoré » clôt la sollicitation, « reporté » la maintient.
- T40 : compréhension sur quatre niveaux (pas compris, à revoir, plutôt compris, bien compris), proposition modifiable.
- T41 : une tâche au plus par retour ; créée dans la même transaction. La table tasks est posée dans sa forme personnelle minimale, à étendre au lot 11.
- T42 : cours terminés proposés pendant 7 jours (CAMPUS_CHECK_IN_PENDING_DAYS), valeur proposée.
- A30 ouvert : agrégation des difficultés pour un établissement (CHK06). Rien n'est construit ; toute agrégation exigera une décision, un seuil d'effectif minimal et une fonction autorisée explicite.

## Décisions du Prompt 11 — 17 novembre 2026
- P11 : lot 11 = tâches personnelles, devoirs collectifs, microtâches. Pas de dépôt ni de correction de copies.
- T43 : une tâche personnelle appartient au compte ; un devoir appartient à un groupe et une matière d'un établissement. L'avancement d'un devoir n'existe que par apprenant (task_progress) ; l'absence de ligne vaut « à faire ».
- T44 : le retard n'est pas un état. Il est calculé sur l'échéance officielle ; l'objectif personnel d'un apprenant ne le masque pas.
- T45 : un devoir se gère par l'affectation effective au groupe et à la matière, pas par l'auteur : un enseignant affecté plus tard peut le corriger, un enseignant dont l'affectation est close ne le peut plus.
- T46 : une tâche personnelle porte une matière personnelle (référentiel du lot 06), jamais une matière d'établissement. Proposition simple ; à revoir si les apprenants demandent à classer leurs tâches personnelles par matière de l'établissement.
- T47 : l'enseignant ne voit aucun avancement individuel ni agrégé. Toute vue de suivi exigera une décision explicite (même logique que A30).
- T48 : corbeille de 7 jours (CAMPUS_TASK_TRASH_DAYS). Un devoir retiré disparaît pour les apprenants mais garde leurs avancements jusqu'à la purge. La purge est une commande planifiée, bornée, qui n'affiche qu'un nombre.
- T49 : écritures de l'enseignant soumises à la même dérogation de second facteur que l'administration, par cohérence avec le lot 08. À confirmer : le cahier n'impose le second facteur qu'aux rôles privilégiés ; si l'enseignant n'en fait pas partie, cette contrainte peut être levée en une ligne.
- T50 : les événements de devoir (publié, modifié, retiré, rétabli) ne portent ni titre ni consigne ; les destinataires seront recalculés à l'envoi (lot 13) parmi les inscrits effectifs.
- T51 : 20 microtâches au plus par tâche et par personne ; terminer toutes les étapes ne termine pas la tâche.
- A31 ouvert : gestion déléguée des devoirs par l'administration d'établissement. Rien n'est construit : seul un enseignant affecté publie.

## Décisions du Prompt 12 — 17 novembre 2026
- P12 : lot 12 = notes personnelles, résultats publiés, moyennes indicatives. Aucun bulletin officiel.
- T52 : deux sources strictement séparées. Aucune vue combinée en V1 : une note personnelle et un résultat publié représentant la même évaluation ne peuvent donc jamais compter deux fois. Toute sélection combinée future devra être un choix explicite de l'apprenant, évaluation par évaluation.
- T53 : calcul par période uniquement. Aucune moyenne annuelle ni regroupement de périodes tant qu'une pondération n'est pas validée par le porteur.
- T54 : cinq états sans valeur — à venir, absence, dispense, non rendu, note manquante (aucun résultat saisi). Aucun ne vaut zéro ; aucun traitement propre à un établissement (note éliminatoire, rattrapage, zéro pour absence injustifiée) n'est codé.
- T55 : coefficient de matière institutionnel fixé par l'administration, par année. Non défini = pas de moyenne générale, plutôt qu'un coefficient 1 supposé. Pour les matières personnelles, coefficient 1 par défaut, modifiable par l'apprenant.
- T56 : seuil « matière à surveiller » à 10/20, strict, configurable (CAMPUS_GRADE_WATCH_THRESHOLD). Le message se limite au constat chiffré.
- T57 : arithmétique exacte avec brick/math (fractions), bibliothèque déjà présente comme dépendance de Laravel. Aucune dépendance n'a été ajoutée à composer.json ; à déclarer explicitement si le porteur l'approuve, pour ne pas dépendre d'un choix interne du framework.
- T58 : un résultat institutionnel garde un historique complet (valeur, barème, auteur, motif) dans une table dédiée non modifiable. Le journal d'audit et les événements ne contiennent que des identifiants. Les notes personnelles ne produisent ni journal ni événement.
- T59 : correction ou retrait d'une évaluation publiée = motif obligatoire. Une évaluation retirée ne se republie pas.
- T60 : l'enseignant saisit pour les apprenants effectivement inscrits au groupe au moment de la saisie ; un résultat déjà saisi reste corrigeable après un changement de groupe.
- T61 : écritures de l'enseignant et coefficients de l'administration soumis à la dérogation de second facteur, comme au lot 11 (même réserve que T49).
- A32 ouvert : consultation des résultats par l'administration de l'établissement et production d'un relevé. Rien n'est construit.
- A33 ouvert : conservation des résultats institutionnels après le départ d'un apprenant ou l'archivage d'une année (lot 23).

## Décisions du Prompt 13 — 17 novembre 2026
- P13 : lot 13 = centre interne, préférences, rappels durables, courriel et Web Push. Canaux externes livrés mais non vérifiés faute de configuration.
- T62 : le centre interne est le canal de référence, toujours disponible. Courriel et Web Push sont éteints par défaut, par catégorie : ils exigent un choix de la personne.
- T63 : le Web Push n'envoie aucun contenu. Le navigateur affiche un message générique et renvoie au centre. C'est la réponse la plus stricte à « pas de contenu scolaire sensible sur écran verrouillé », et elle évite toute dépendance de chiffrement. À revoir seulement si le porteur veut des messages détaillés pour certaines catégories.
- T64 : le courriel ne porte que le titre neutre du rappel et un lien ; le détail (cours, tâche) reste dans le centre, après connexion.
- T65 : une adresse d'abonnement Web Push vient du navigateur de l'utilisateur. Le serveur n'appelle que les hôtes d'une liste fermée (Google, Mozilla, Microsoft, Apple), en HTTPS, sans redirection. Tout autre hôte est refusé à l'inscription et à l'envoi.
- T66 : durées de validité proposées — rappel de cours 6 h, rappel de tâche 12 h (jamais au-delà de l'échéance), alerte d'événement 48 h, synthèse 3 h. Au-delà, le rappel expire.
- T67 : reprises bornées à 4 tentatives (1, 5, 15 minutes), uniquement sur indisponibilité annoncée par le service. Une absence de réponse ou une interruption après réservation donne « indéterminé », sans renvoi. Toute erreur de transport du courriel donne « indéterminé ».
- T68 : la plage silencieuse retarde les canaux externes, pas le centre interne. La catégorie « compte et sécurité » l'ignore.
- T69 : changements de planning regroupés en une alerte par personne et par publication.
- T70 : rappels de tâche à heure fixe locale, la veille et le jour même (8 h par défaut). Le rappel du jour n'est pas émis si cette heure tombe après l'échéance ; aucun rappel n'est émis pour une heure antérieure à la création de la tâche.
- T71 : la synthèse quotidienne n'est envoyée que s'il y a au moins une tâche à échéance du jour ou un retour de cours à renseigner.
- T72 : l'état « accepté » signifie accepté par le fournisseur. « Lu » n'existe que pour le centre interne, sur action de la personne.
- T73 : clé Web Push générée par la commande campus:push:key, qui affiche un secret : à exécuter par l'exploitant seulement. Elle n'a pas été exécutée dans ce lot en dehors des tests, où la clé est créée en mémoire.
- A34 ouvert : fournisseur de courriel et expéditeur ; sans décision, aucun courriel de rappel ne part.
- A35 ouvert : activation du Web Push (génération de la clé, adresse de contact de l'exploitant) et essai sur de vrais appareils.
- A36 ouvert : notifications de la catégorie « compte et sécurité » dans le centre (nouvelle session, appartenance retirée). La catégorie existe ; aucun déclencheur n'est branché.

## Décisions du Prompt 14 — 17 novembre 2026
- P14 : lot 14 = pièces jointes PDF, JPEG, PNG sur tâches, devoirs et retours de cours. Antivirus livré mais non vérifié faute de démon installé.
- T74 : sans antivirus configuré, aucun fichier n'est téléchargeable, y compris par son propriétaire, y compris en local. Aucun réglage ne permet de passer outre : c'est le sens de « aucun contournement pour débloquer la démo ».
- T75 : antivirus proposé = ClamAV (libre, sans service facturé), joint par TCP local, contenu transmis par la connexion. Aucune dépendance PHP ajoutée.
- T76 : limites 5 Mo par fichier, 3 fichiers par élément (3 fichiers de groupe par devoir ; 3 fichiers personnels par personne et par élément), 100 Mo par compte, enseignants compris. Les fichiers en quarantaine comptent ; les fichiers refusés ou supprimés ne comptent plus.
- T77 : aucune adresse temporaire ni lien signé. Le téléchargement passe toujours par le contrôleur, qui recontrôle les droits ; un lien copié n'a donc aucune valeur pour un tiers.
- T78 : visibilité fixée au dépôt et non modifiable. « Groupe » est réservé à l'enseignant affecté, avec la même exigence de second facteur que la publication d'un devoir (T49).
- T79 : un fichier non diffusable répond 423 avec le code commun FILE_NOT_AVAILABLE, ajouté au format d'erreur commun.
- T80 : un fichier signalé reste disponible jusqu'à la décision ; l'administration de l'établissement ne lit que les fichiers de groupe signalés. Le retrait masque le fichier à tous sauf à l'administration ; le fichier est conservé jusqu'à la purge du devoir. À revoir si le porteur préfère un masquage préventif dès le signalement.
- T81 : pas de réencodage d'image ni de suppression des métadonnées dans ce lot.
- T82 : le contrôle de contenu actif des PDF est une recherche de mots-clés, volontairement stricte (un PDF légitime contenant un formulaire XFA ou une action d'ouverture est refusé).
- A37 ouvert : installation, mise à jour et supervision de ClamAV sur les serveurs de recette et de production (lot 24).
- A38 ouvert : notifier l'administration d'un nouveau signalement et l'auteur d'un retrait.
- A39 ouvert : suppression des métadonnées des images (position GPS) avant stockage.

## Décisions du Prompt 15 — 17 novembre 2026
- P15 : lot 15 = fiches du catalogue, administration éditoriale, signalements. Recherche au lot 16, favoris au lot 17, alertes au lot 18.
- T83 : quatre précisions de date limite, stockées distinctement : instant (UTC + fuseau de l'organisateur), date seule, clôture continue, non communiquée. Aucun fuseau par défaut à la saisie.
- T84 : règle technique pour une date seule — « dernier jour possible » à partir du début de ce jour en UTC+14, close à la fin de ce jour en UTC−12. Elle évite d'afficher « close » une fiche encore ouverte quelque part, et avertit dès que le dernier jour a commencé quelque part. Elle est affichée et présentée comme non garantie. À confirmer par le porteur ; l'alternative serait de fermer à la fin du jour à Abidjan.
- T85 : conditions minimales de publication = source, organisateur, conditions d'au moins 20 caractères, acte de vérification. Seuil de 20 caractères proposé.
- T86 : la vérification est une case cochée par l'éditeur, datée et attribuée ; le serveur n'interroge jamais la source. Toute modification d'une fiche publiée exige un nouvel acte.
- T87 : doublon bloquant sur (titre normalisé, organisateur normalisé, campagne), toutes fiches confondues ; doublon de source dans la même campagne bloquant sauf confirmation explicite, car une même page peut annoncer plusieurs offres.
- T88 : une nouvelle édition ne recopie ni la date limite ni la vérification.
- T89 : archivage automatique à la clôture technique, sans délai de grâce ; une fiche archivée ne se modifie plus et n'est plus visible du public. À revoir si le porteur souhaite garder visibles quelque temps les fiches closes.
- T90 : signaler exige un compte connecté et vérifié, pour limiter les abus : cinq signalements par jour et par compte, un seul ouvert par fiche. L'identité du signalant est conservée en base pour ces limites mais n'est affichée à personne.
- T91 : aucune dépublication automatique sur signalement, quel qu'en soit le nombre. Toute règle de seuil devra être validée par le porteur.
- T92 : un éditeur ne traite pas un signalement qu'il a lui-même émis.
- T93 : le signalant voit l'état et l'issue de son signalement, jamais le commentaire interne ni le nom de l'éditeur.
- T94 : l'administration éditoriale suit la règle commune des rôles privilégiés (second facteur). Tant qu'il n'est pas livré, aucune fiche ne peut être publiée hors local.
- A40 ouvert : liste de référence des pays et des langues (codes, libellés) et, plus largement, critères distincts pays de résidence, nationalité, destination demandés par la spécification — aujourd'hui un seul champ « pays ».
- A41 ouvert : qui alimente et vérifie le catalogue réel avant le pilote, et à quelle fréquence une fiche publiée doit être revérifiée.

## Décisions du Prompt 16 — 20 novembre 2026
- P16 : lot 16 = recherche, filtres, critères déclarés, correspondances expliquées. Démarches et favoris au lot 17.
- T95 : recherche et pertinence entièrement en MySQL (sous-chaînes sur des colonnes normalisées). Aucun moteur externe, aucune dépendance. Barème de pertinence 3/2/1 (titre, organisateur, domaine), affiché à l'écran.
- T96 : tri par défaut = clôture connue la plus proche, puis fiches sans date, par titre. Les fiches sans date limite connue restent dans la liste ouverte et ne sont jamais considérées closes.
- T97 : fiche suspendue = hors de toutes les listes, mais son adresse affiche son titre et un avertissement, sans contenu ni lien (décision du prompt 16 : « fiches expirées/suspendues identifiées »). Fiche close archivée = lisible pour mémoire, dans le filtre « clôturées », sans lien de candidature. Cela remplace le 404 du lot 15 pour ces deux cas seulement.
- T98 : la fiche distingue trois notions : pays où se déroule l'opportunité, nationalités admises, pays de résidence admis. Un champ vide signifie « non précisé par la source », jamais « ouvert à tous ». Répond en partie à A40.
- T99 : critères déclarés par l'utilisateur tous facultatifs : niveau, domaines, langues, pays de résidence, nationalité. Aucun n'est obligatoire : aucune nécessité n'a été démontrée. Rien n'est déduit de l'établissement, du groupe ou de toute autre donnée du compte.
- T100 : règle de correspondance — information absente d'un côté ou de l'autre = « à vérifier » ; valeurs présentes et concordantes = « correspond » ; valeurs présentes et contraires = « non satisfait », sauf pour les domaines (intitulés libres), où l'absence d'intitulé commun reste « à vérifier ». Verdict d'ensemble : « non satisfait » si un critère l'est, sinon « à vérifier » si un critère l'est, sinon « correspond ».
- T101 : aucune comparaison n'est affichée tant que la personne n'a déclaré aucun critère.
- T102 : les liens vers la source et vers la candidature sont directs, sans passage par Campus, sans référent, et ne laissent aucune trace. Aucune mesure de clics n'est donc possible, par choix.
- T103 : un filtre de domaine sans lettre ni chiffre ne renvoie aucune fiche.
- A40 (suite) : reste ouvert pour une liste de référence des pays et des langues avec saisie guidée.
- A42 ouvert : faut-il proposer des listes déroulantes de pays et de langues plutôt que des codes à deux lettres, pour l'éditeur comme pour l'utilisateur ?

## Décisions du Prompt 17 — 20 novembre 2026
- P17 : lot 17 = favoris et démarches privées. Alertes et rappels au lot 18.
- T104 : favori et démarche sont indépendants : commencer une démarche n'ajoute pas de favori, supprimer une démarche ne retire pas le favori.
- T105 : « bientôt clos » = clôture connue dans les 7 jours (CAMPUS_OPPORTUNITY_CLOSING_SOON_DAYS), valeur proposée. Une fiche sans date connue n'est jamais « bientôt close ».
- T106 : une démarche se commence seulement sur une fiche publiée ; elle reste lisible et modifiable par son auteur après suspension ou archivage de la fiche.
- T107 : sept états, tous choisis librement dans n'importe quel ordre ; aucun enchaînement imposé, aucun changement automatique, y compris à la clôture de la fiche.
- T108 : aucun fichier dans une démarche. La liste de pièces est une liste de lignes de texte à cocher ; la note rappelle de n'y inscrire ni numéro de pièce d'identité ni information bancaire.
- T109 : résumé de référence limité à huit champs, sans texte de la source. Il est mis à jour à chaque enregistrement de la démarche tant que la fiche est publiée, et figé pour toutes les démarches à la suspension et à l'archivage.
- T110 : suppression d'une démarche définitive et immédiate (pas de corbeille) : il s'agit d'une donnée privée que la personne veut pouvoir effacer.
- T111 : l'équipe éditoriale ne voit ni les suiveurs d'une fiche ni leur nombre.
- T112 : destinataires des futures alertes d'une fiche = comptes ayant un favori ou une démarche encore en cours (à étudier, en préparation, envoyée, en attente).
- A43 ouvert, transversal : le limiteur de débit standard de Laravel, posé sur 71 routes, a une clé par compte sans la route. Les plafonds se cumulent donc entre routes : après trente actions quelconques dans une minute, toute route plafonnée à 30 répond 429. Correction proposée, à valider : des limiteurs nommés dont la clé inclut la route (ou la famille de routes), à faire au lot 23 avec les mesures de charge. Aucun risque de sécurité : le défaut est une gêne possible pour un utilisateur très actif.

## Décisions du Prompt 18 — 20 novembre 2026
- P18 : lot 18 = recherches enregistrées, synthèses, rappels des fiches suivies, alertes de suspension et de changement de date, sur le moteur du lot 13.
- T113 : une alerte porte toujours sur les fiches ouvertes ; la clôture, le tri et la page ne font pas partie d'une recherche enregistrée. Une recherche sans aucun critère n'est pas enregistrable.
- T114 : aucune rafale. Seules les fiches publiées ou dont la date change après l'enregistrement (ou la reprise) sont annoncées ; une synthèse au plus par recherche et par période, vingt fiches au plus, dix recherches par compte. Valeurs proposées.
- T115 : « révision pertinente » = publication (clé « new ») ou changement de date limite (clé par nouvelle clôture). Les autres modifications d'une fiche ne produisent pas d'alerte.
- T116 : fréquences proposées : quotidienne ou hebdomadaire, comptées depuis la dernière vérification de la recherche. Pas d'alerte immédiate.
- T117 : canaux d'une synthèse = centre de notifications toujours ; courriel et appareils seulement si la recherche le demande ET si la catégorie « Opportunités et favoris » l'autorise dans les préférences.
- T118 : rappels des fiches suivies à 7 et 1 jours par défaut, réglables de 0 à 30. Pour une date seule, le rappel part à l'heure de rappel de la personne (celle des tâches), dans son fuseau. Aucun rappel pour une clôture continue ou une date non communiquée.
- T119 : un rappel de fiche suivie non remis dans les 24 heures expire.
- T120 : l'avis de suspension renvoie à « Mon suivi » et ne contient ni motif ni lien de source. Une suspension découverte plus de 48 heures après n'est plus annoncée (règle commune du lot 13).
- T121 : une fiche suspendue puis reprise, jamais annoncée auparavant, est annoncée comme nouvelle après sa reprise.
- A44 ouvert : faut-il un rappel de l'échéance personnelle d'une démarche (lot 17) ? Non demandé par le prompt 18, non construit.
- A45 ouvert : lien de désabonnement direct dans le courriel (un clic, sans connexion). Aujourd'hui le courriel renvoie à la page de l'alerte, qui exige la connexion.

## Décisions du Prompt 19 — 22 novembre 2026
- P19 : lot 19 = PWA installable et lecture hors connexion. Aucune écriture hors connexion (lot 20).
- T122 : le service worker ne conserve que du public (coque et fichiers de build). Aucune stratégie de cache n'existe pour les pages, les réponses Inertia ou les données : tout ce qui est privé hors connexion vit dans IndexedDB, côté page, sous le choix « appareil personnel ».
- T123 : appareil « partagé » par défaut. Le choix « personnel » est explicite, propre au compte qui le fait sur ce navigateur, et ne survit ni à la déconnexion, ni à un changement de compte, ni à l'expiration.
- T124 : contenu de la copie de lecture : planning publié et personnel sur 14 jours, tâches ouvertes (100 au plus), notes de la période en cours pour chaque source, favoris, vingt dernières fiches publiques consultées. Ni retours de cours, ni démarches, ni pièces jointes, ni notifications.
- T125 : durée sans contrôle fixée à 7 jours, paramétrable (CAMPUS_OFFLINE_TTL_DAYS) ; au-delà, effacement à l'ouverture. La limite d'une révocation à distance est affichée à l'utilisateur.
- T126 : mise à jour du service worker jamais forcée ; elle s'applique sur demande, ou d'elle-même quand toutes les fenêtres de Campus sont fermées.
- T127 : toute réponse à un compte connecté porte « no-store, private », par un middleware global.
- T128 : un seul service worker pour le site ; celui du push (lot 13) y est intégré.
- T129 : identifiant opaque du compte transmis à la page (dérivé d'un secret du serveur) pour détecter un changement de compte sans exposer le numéro du compte.
- T130 : script de contrôle en navigateur sans dépendance (tests/browser/pwa-edge.mjs), à lancer à la main ; il n'est pas dans la suite automatique, car il exige un navigateur Chromium et un port libre.
- A46 ouvert : essais sur téléphones réels (Android et iPhone) et sur Firefox et Safari, à faire en recette.
- A47 ouvert : faut-il proposer un verrou local (code) avant d'afficher la copie hors connexion sur un appareil personnel ? Non construit.

## Décisions du Prompt 20 — 22 novembre 2026
- P20 : lot 20 = brouillons personnels hors connexion et leur synchronisation. Trois types d'opération seulement : création de tâche personnelle, modification de tâche personnelle, enregistrement d'un retour privé après cours.
- T131 : point d'entrée POST /synchronisation/v1/operations, une opération par requête, sous la session du site et son jeton CSRF. Pas de jeton d'API : il n'existe pas d'accès à Campus sans session.
- T132 : idempotence par (compte, identifiant d'opération), dans une table qui ne contient aucun contenu saisi. Tout résultat définitif est enregistré (appliqué, conflit, refusé) et rendu à l'identique si l'opération revient ; l'état « actuel » d'un conflit est relu à chaque fois chez le propriétaire, jamais stocké.
- T133 : l'empreinte porte sur le type, la cible, la version connue et le contenu, dans une forme canonique calculée à l'identique par l'appareil et par le serveur ; le serveur la recalcule sur le corps reçu et refuse un envoi dont l'empreinte ne correspond pas.
- T134 : une opération déjà tentée n'est plus jamais modifiée. Une nouvelle saisie sur le même élément devient une opération suivante, envoyée après elle à partir de la version qu'elle aura produite. Un brouillon jamais envoyé est remplacé sur place.
- T135 : conflit = état durable sur l'appareil. Jamais de résolution automatique : « appliquer mon brouillon à la place » crée une nouvelle opération fondée sur la version que la personne vient de voir ; « garder la version enregistrée » abandonne le brouillon.
- T136 : un refus garde le brouillon sur l'appareil ; il n'est renvoyé que sur demande. Le serveur ne conserve pas le brouillon refusé : il reste sur l'appareil de son auteur, hors de tout partage.
- T137 : brouillons seulement sur un appareil déclaré personnel par le compte (choix du lot 19). Sur un appareil partagé, la saisie reste à l'écran et rien n'est stocké.
- T138 : une copie de lecture périmée est effacée sans toucher aux brouillons ; changement de compte, déconnexion décidée et retour en « appareil partagé » effacent tout, brouillons compris.
- T139 : synchronisation déclenchée par la page (ouverture, retour du réseau, retour sur l'onglet, bouton). L'API de synchronisation en arrière-plan du navigateur n'est pas utilisée.
- T140 : un seul code pour la file (public/offline-core.js), chargé par l'application et par la page hors ligne ; dans l'application il est demandé avec un paramètre de version pour ne jamais venir du cache de la coque.
- T141 : accusés conservés 90 jours (CAMPUS_SYNC_RETENTION_DAYS). Après leur purge, une opération rejouée reste sans effet grâce aux règles des services (clé de création, version attendue).
- T142 : un refus global du serveur (403, session fermée, trop de demandes) n'est pas un jugement sur un brouillon : l'envoi s'arrête et tout reste en attente.
- A48 ouvert : faut-il sauvegarder sur le serveur un brouillon en conflit ou refusé, pour le retrouver depuis un autre appareil ? Non construit : le brouillon ne quitte pas l'appareil.
- A49 ouvert : étendre les brouillons à la suppression de tâche, aux étapes, à l'avancement d'un devoir et aux choix « rien à signaler / plus tard / ignorer » ? Hors du périmètre demandé.
- A50 ouvert : brouillons d'un compte dont la session expire, puis autre compte sur le même appareil : effacés sans avertissement possible. Accepté pour la confidentialité ; à confirmer.

## Décisions du Prompt 21 — 22 novembre 2026
- P21 : lot 21 = accueil de prochaine action, deux parcours de démarrage, aide, états d'erreur, essais en navigateur réel.
- T143 : l'accueil est construit par un service qui relit les services des écrans (tâches, retours, notes, opportunités, planning visible) : mêmes droits, aucune requête propre sur des données privées.
- T144 : une étape de parcours est « faite » quand l'objet réel existe ; aucune case à cocher déclarative. Exception : le fuseau, confirmé par un enregistrement explicite, mémorisé dans les préférences de l'espace personnel.
- T145 : le parcours d'établissement n'est montré qu'au contexte actif d'administration de cet établissement, et ne contient que des indicateurs d'existence dans son périmètre (ni nom, ni motif, ni contenu).
- T146 : « cours du jour » = journée du fuseau personnel, toutes sources visibles par le compte, chaque ligne portant sa provenance.
- T147 : une opportunité est suggérée sur l'accueil si aucun critère déclaré n'est contredit et qu'au moins un correspond ; trois au plus ; jamais sans critères déclarés ; l'avertissement d'éligibilité accompagne toujours la liste.
- T148 : page d'erreur autonome pour 403, 404, 419, 429, 500 et 503 (500 seulement hors mode de débogage) ; elle ne lit aucune donnée partagée.
- T149 : une action refusée (403, 404, 409, 419, 429) sur un écran qui n'affiche pas lui-même l'erreur revient à cet écran avec un message global ; les écrans qui l'affichent près du formulaire gardent leur comportement.
- T150 : avertissement avant abandon d'une saisie sur les formulaires de création principaux ; pas de dialogue pour une lecture ou une navigation sans saisie.
- T151 : contact de support lu dans CAMPUS_SUPPORT_EMAIL ; vide par défaut, et la page d'aide l'indique.
- T152 : commande campus:fixtures:access, limitée à l'environnement local, à la base hako_ra_campus_dev et aux huit comptes @example.test ; mot de passe lu sans écho ou dans un fichier hors dépôt, jamais affiché. Ce n'est pas un accès de support : aucun compte réel n'est concerné et la commande se refuse partout ailleurs.
- T153 : le script des parcours travaille sur la base de développement, préfixe ce qu'il crée, referme les comptes en fin de passage et à tout arrêt, et remet le compte indépendant sans établissement.

### Anomalies du lot 21 — ouvertes et assignées
- AN21-01 — Second facteur absent : les rôles d'enseignement, d'administration et d'édition ne s'ouvrent qu'avec la dérogation locale. Assignée : porteur (décision de lot dédié). Bloque tout pilote privilégié.
- AN21-02 — Aucun service de courriel : un nouvel inscrit ne peut pas vérifier son adresse ni réinitialiser son mot de passe. Assignée : porteur (choix et configuration du service).
- AN21-03 — Contact de support non configuré : la page d'aide n'affiche aucune adresse. Assignée : porteur (CAMPUS_SUPPORT_EMAIL).
- AN21-04 — Aucun écran pour changer son mot de passe depuis le profil ; seule la réinitialisation par courriel existe, elle-même bloquée par AN21-02. Assignée : à arbitrer, lot 22 ou 23.
- AN21-05 — Création d'une invitation : après la nouvelle saisie du mot de passe, le formulaire d'invitation est vide et doit être rempli de nouveau. Assignée : lot 23 (qualité).
- AN21-06 — Essai « débutant en moins de dix minutes » non réalisé. Assignée : porteur, avec une personne qui ne connaît pas Campus.
- AN21-07 — Limite de débit commune par compte, toutes routes confondues (A43). Assignée : lot 23.
- AN21-08 — Choix du fuseau : liste complète d'environ 420 fuseaux, sans recherche ni regroupement. Assignée : lot 23 (ergonomie).
- AN21-09 — Import de lignes réelles puis application non rejoués en navigateur dans ce lot. Assignée : recette manuelle du porteur (étape 4 du parcours proposé).
- A51 ouvert : faut-il un écran de démarrage plein écran à la première connexion, plutôt que la liste d'étapes sur l'accueil ? Non construit : la liste ne bloque rien et ne demande aucune information.

## Décisions du Prompt 22 — 22 novembre 2026
- P22 : lot 22 = notice versionnée et preuve de lecture, demandes suivies, export, suppression, conservation, journal de réapplication. Sauvegardes réelles au lot 24.
- T154 : la notice vit dans le code, par versions immuables ; l'empreinte porte sur le texte de la version, hors valeurs de configuration. Responsable, contact et hébergement viennent de la configuration et s'affichent « à compléter avant ouverture » tant qu'ils sont vides.
- T155 : la prise de connaissance n'est pas un consentement et ne bloque rien. Aucun consentement facultatif nouveau n'est créé : les choix facultatifs existants (notifications, critères d'opportunités, copie sur l'appareil) gardent leurs écrans.
- T156 : revérification d'identité proportionnée : session pour lire et déposer une demande ; mot de passe confirmé récemment pour l'export ; mot de passe ressaisi dans la demande, plus un mot de confirmation, pour la suppression. Aucune pièce d'identité n'est demandée.
- T157 : l'export est construit colonne par colonne à partir d'une liste écrite dans le code ; toute nouvelle table de données personnelles doit y être ajoutée. Fichier JSON sur le stockage privé, 7 jours, trois par vingt-quatre heures.
- T158 : la suppression efface l'espace individuel et l'identité de connexion ; la ligne du compte reste, sans identité (état « erased »), pour que les données tenues par l'établissement gardent leur cohérence.
- T159 : la suppression est refusée au seul compte d'administration d'un établissement ou de la plateforme.
- T160 : journal des effacements dans un fichier signé, hors base ; écrit après validation de l'opération ; conservation de 180 jours, plancher égal à la durée des sauvegardes plus 30 jours. Il ne contient que des identifiants techniques et des dates.
- T161 : après une restauration, la réapplication ferme aussi toutes les sessions et tous les jetons de réinitialisation.
- T162 : conservation dans config/campus.php (« retention », « privacy ») ; une commande quotidienne bornée ; l'audit des purges ne porte que des nombres ; le journal d'audit est purgé par la base, le modèle interdisant toute suppression applicative.
- T163 : inactivité = nombre de comptes à examiner ; aucune suppression ni blocage automatique.
- T164 : chaque limite de débit des routes du lot a son propre compteur (troisième paramètre du limiteur).
- AN22-01 — Notice à compléter et à faire valider (responsable, contact, hébergement, bases légales, prestataires, autorité, mineurs). Assignée : porteur, avec un conseil juridique. Bloque toute ouverture.
- AN22-02 — Justification et durée de conservation des données tenues par les établissements après la suppression d'un compte. Assignée : porteur et chaque établissement.
- AN22-03 — Emplacement séparé et sauvegarde du journal des effacements. Assignée : lot 24.
- AN22-04 — Fichiers joints absents de l'export. Assignée : à arbitrer (archive complète ou maintien de la liste).
- AN22-05 — Suite à donner à la revue d'inactivité. Assignée : porteur.
- AN22-06 — Suppressions ordinaires non journalisées, donc non réappliquées après restauration. Assignée : lot 24, à arbitrer.
- AN22-07 — Les limites de débit des autres routes se cumulent toujours par compte (A43) ; la correction faite ici ne couvre que les routes du lot. Assignée : lot 23.

## Décisions du Prompt 23 — 22 novembre 2026
- P23 : lot 23 = revue corrective ciblée, mesures locales, contrôles d'accessibilité automatiques, rapport docs/audit-v1.md.
- T165 : les données de page sont écrites par la vue elle-même, chevrons et esperluette échappés, texte en UTF-8 lisible ; la directive du paquet n'est plus utilisée.
- T166 : en-têtes de sécurité par un middleware global. Politique de contenu : tout limité au site, aucun script en ligne ; les styles en ligne restent autorisés. Elle ne s'applique pas pendant le rechargement à chaud de Vite.
- T167 : hôtes de confiance = nom de APP_URL, sans sous-domaines, hors local et tests.
- T168 : limiteur de débit par compte et par route (A43 clos). Les plafonds écrits dans les routes ne changent pas.
- T169 : campus:security:check contrôle les réglages bloquants avant ouverture sans afficher de valeur ; il ne vaut pas autorisation d'ouvrir.
- T170 : le nombre de requêtes des écrans fréquents est tenu par un test (pas de croissance avec les lignes, 60 au plus).
- T171 : mesures séparées : essai court local d'un côté, mesure complète de l'autre, sur un environnement décrit ; l'outil de charge refuse une cible distante sans confirmation explicite.
- T172 : le temps de chargement est mesuré avec et sans compression ; la cible ne se juge qu'avec la compression, qui relève du serveur web.
- T173 : aucune conformité WCAG n'est revendiquée ; les contrôles automatiques sont rapportés avec leurs limites.
- A43 : clos par T168.
- AN21-07 et AN22-07 : clos par T168.
- AN23-01 — Charge de référence non mesurée. Assignée : lot 24.
- AN23-02 — Import de 1 000 lignes à 138 s sur le poste de mesure. Assignée : lot 24 pour la mesure sur l'environnement cible ; correction du contrôle des chevauchements à arbitrer ensuite.
- AN23-03 — Compression et en-têtes des fichiers statiques. Assignée : lot 24.
- AN23-04 — Essais sur téléphones, Safari, Firefox et lecteur d'écran. Assignée : porteur, recette manuelle.
- AN23-05 — Test d'intrusion indépendant. Assignée : décision du porteur.

## Décisions du Prompt 24 — 23 novembre 2026

- T174 : la sauvegarde est faite par l'application elle-même (`campus:backup`), avec `mysqldump` et le chiffrement libsodium déjà présent dans PHP : aucune dépendance ajoutée, aucun outil d'hébergeur présumé.
- T175 : la clé de sauvegarde est distincte de `APP_KEY`, écrite dans un fichier hors dépôt et jamais affichée.
- T176 : la restauration ne s'exécute que vers une base vide et un dossier vide, jamais vers la base en service. Il n'existe aucune commande qui écrase la base en service ou annule une migration.
- T177 : le journal des effacements est copié chiffré à côté des sauvegardes et non dedans, pour rester applicable à une sauvegarde plus ancienne que lui. Clôt AN22-03 côté code ; l'emplacement réel reste à choisir avec l'hébergement.
- T178 : après restauration, les envois externes encore en file sont annulés plutôt que renvoyés : ils ont pu partir avant l'incident. Les rappels programmés dont l'échéance utile n'est pas passée restent et partent une fois.
- T179 : la sonde externe est fermée (404) tant qu'aucun jeton d'au moins 32 caractères n'est configuré ; elle ne révèle que des états et des mesures, aucune donnée personnelle.
- T180 : un composant non configuré (sauvegarde, antivirus, certificat) est rapporté « non configuré » et compte comme non sain.
- T181 : les seuils de supervision et la conservation de 30 jours sont des propositions réglables par configuration.
- T182 : RPO 24 h, RTO 8 h ouvrées et disponibilité 99,5 % sont consignés comme objectifs non éprouvés, pas comme engagements.
- T183 : les migrations d'une mise en service n'ajoutent que des éléments ; un retrait se fait dans une mise en service ultérieure, pour que le retour du code reste possible sans toucher à la base.
- A52 ouvert : choix de l'hébergement, du nom de domaine, du volume de sauvegarde distant, du service de courriel et de la supervision externe ; contacts d'alerte et d'astreinte.

Anomalies et suites :
- AN24-01 — Restauration complète de la base non réalisée : aucune base isolée disponible. Assignée : porteur (créer une base vide ouverte au compte local), puis exercice à refaire.
- AN24-02 — Modèles de configuration serveur jamais éprouvés. Assignée : mise en place de l'hébergement.
- AN24-03 — Une rotation de `APP_KEY` invalide les signatures du journal des effacements ; aucun outil de renouvellement. Assignée : à arbitrer avant toute rotation.
- AN24-04 — Intégration continue jamais exécutée à distance. Assignée : envoi du dépôt, sur instruction du porteur.
- AN23-01 et AN23-02 restent ouvertes : aucune mesure possible sans environnement cible.
- AN23-03 : traitée par le modèle Nginx (compression, en-têtes des fichiers statiques), non éprouvé — voir AN24-02.

## Décisions du Prompt 25 — 23 novembre 2026

- T184 : faute de commit, la version recettée est identifiée par une empreinte du code du poste (`1.0.0-rc1+8c70dd22c420`). L'étiquette `v1.0.0-rc1` est proposée, non posée.
- T185 : l'emplacement du fichier de version est réglable (`campus.revision_file`) ; les tests n'utilisent jamais le fichier réel.
- T186 : statuts de recette limités à passé, échoué, non exécuté, non applicable ; une partie non exécutée est écrite dans le statut plutôt que cachée derrière « partiel ».
- T187 : R10 est classé échoué, pas « bloqué » : l'exigence n'est pas livrée. R54 est classé échoué pour l'import sur le poste de mesure, la cible de 120 s restant une proposition (A27).
- T188 : cohortes proposées dans l'ordre indépendants, structure du supérieur, lycée ; le lycée en dernier, le cadre des mineurs n'étant pas validé.
- T189 : les indicateurs du pilote sont des comptages hebdomadaires sur la base ; aucun outil de mesure d'audience n'est ajouté.
- A53 ouvert : instruction du porteur pour le premier commit, l'étiquette et l'envoi du dépôt.
- A54 ouvert : désignation du responsable éditorial et saisie des 20 à 30 annonces réelles (prolonge A13).

Anomalies et suites :
- AN25-01 — Un test effaçait le fichier `REVISION` réel. Corrigée et retestée.
- Restent ouvertes et bloquantes pour un pilote : A19/A34 (courriel), A20 (second facteur), A01 (hébergement), A06 (mineurs), A11 (notice), A12 (support), AN24-01 (restauration), AN23-01 (charge), A37 (antivirus).

## Décision du porteur — second facteur, 23 novembre 2026

- Décision du porteur : second facteur par code temporaire à six chiffres, avec Google Authenticator ou toute application compatible. La connexion par compte Google n'est pas retenue (elle reste hors V1). Clôt A20.
- T190 : calcul des codes écrit dans l'application (RFC 6238, SHA-1, 30 secondes, six chiffres), vérifié contre les vecteurs de la RFC ; aucune dépendance ajoutée, aucun échange avec Google.
- T191 : le second facteur est obligatoire pour utiliser un rôle d'enseignement, d'administration ou d'édition, facultatif pour les autres comptes. Un compte qui l'a activé ne voit rien avant d'avoir présenté son code, y compris après « se souvenir de moi ».
- T192 : tolérance d'un pas de 30 secondes avant et après ; un code accepté ne ressert pas ; tentatives limitées comme la connexion.
- T193 : huit codes de secours à usage unique, conservés sous forme d'empreintes, affichés une seule fois.
- T194 : récupération contrôlée par l'exploitation (`campus:second-factor:reset`, motif obligatoire, audit, sessions fermées). Le retrait ne rouvre aucun rôle.
- T195 : en traitement de fond, sans session, un compte garde ses droits privilégiés seulement s'il a un second facteur confirmé.
- T196 : la dérogation `CAMPUS_PRIVILEGED_WITHOUT_MFA` est conservée pour le poste de développement et les tests ; elle reste sans effet ailleurs.
- A55 ouvert : faut-il un écran permettant à l'administration de la plateforme de retirer le second facteur d'un compte, plutôt que la seule commande ?
- A56 ouvert : faut-il un code QR à l'activation ? Il demanderait une dépendance ou un générateur écrit à la main.

Anomalies et suites :
- AN26-01 — Aucun essai avec une vraie application d'authentification sur un téléphone. Assignée : porteur.
- AN26-02 — La première activation repose sur le seul mot de passe. Tant qu'un compte privilégié n'a pas activé son second facteur, une personne connaissant son mot de passe pourrait l'activer à sa place. Atténuation : activation dès l'ouverture du compte, trace d'audit. À arbitrer : exiger l'activation dans le parcours d'acceptation de l'invitation.
- AN24-03 étendue : une rotation de la clé de l'application rend aussi illisibles les secrets de second facteur.

## Décision du porteur — connexion par compte Google, 23 novembre 2026

- Décision du porteur : la connexion par compte Google entre dans la V1. Elle lève, pour Google seulement, l'exclusion « SSO Google/Microsoft ultérieur » (IAM08, P1). Microsoft reste hors V1.
- T197 : flux OpenID Connect par code d'autorisation avec état, nonce et PKCE, écrit avec le client HTTP du framework ; aucune dépendance ajoutée.
- T198 : la règle « aucun appel sortant » garde une seule exception : l'adresse fixe d'échange de Google, écrite dans le code, sans suivi de redirection. Aucune adresse venue d'une personne ou d'une réponse n'est appelée. Un test le vérifie.
- T199 : le jeton d'identité est contrôlé sur son émetteur, son destinataire, sa validité, le nonce de la demande et l'adresse vérifiée. Sa signature n'est pas vérifiée cryptographiquement, le jeton étant reçu directement de Google sur une liaison chiffrée et authentifiée par le secret du client. À réexaminer lors d'une revue de sécurité indépendante.
- T200 : Campus ne conserve que l'identifiant stable du compte Google et l'adresse vue au rattachement. Aucun jeton d'accès ni de renouvellement.
- T201 : un compte Google inconnu ne crée un compte Campus qu'après la même déclaration de majorité que l'inscription, et seulement si l'inscription est ouverte. Aucun rôle ne s'obtient par Google.
- T202 : un compte existant et vérifié portant la même adresse est relié automatiquement. Un compte existant jamais vérifié est relié, mais son mot de passe est annulé et ses sessions fermées : rien ne prouvait qu'il avait été choisi par le titulaire de l'adresse.
- T203 : le second facteur d'un compte reste exigé après une connexion par Google ; Google n'ouvre à lui seul aucun rôle privilégié.
- T204 : un compte créé par Google n'a pas de mot de passe utilisable. Il confirme les actions sensibles par une authentification Google de moins de cinq minutes, ou définit un mot de passe par « mot de passe oublié » (ce qui exige le courriel).
- T205 : le chemin de retour s'appelle `/connexion/google/reponse` et la route de départ `google.start`, pour ne pas ressembler aux routes de retour de cours ni à une redirection de candidature, que des tests interdisent.

Anomalies et suites :
- AN27-01 — Aucune connexion réelle par Google : identifiants à fournir par le porteur, puis essai. Assignée : porteur.
- AN27-02 — La notice de confidentialité doit nommer Google comme prestataire d'identification et dire ce qu'il transmet, avant toute ouverture. Assignée : avec A11.
- AN27-03 — Aucun écran pour détacher un compte Google. À arbitrer : il faudra exiger qu'un mot de passe existe avant de détacher.
- AN27-04 — Un compte créé par Google, sans service de courriel, ne peut pas se donner de mot de passe. Dépend de A19/A34.

## Décision du porteur — nouveau design, 24 novembre 2026

- Décision du porteur : appliquer le design « Magic Schedule » à l'application.
- T206 : le design est appliqué par la feuille de style et les composants existants (mêmes classes, mêmes écrans) ; aucune dépendance ajoutée.
- T207 : la police Plus Jakarta Sans (licence OFL) est hébergée dans l'application, la politique de sécurité interdisant toute police externe.
- T208 : le nom du produit, le vouvoiement et les textes de l'application sont conservés ; le design sert de référence visuelle, pas de source de contenu.
- T209 : là où le design passe sous les seuils d'accessibilité déjà tenus (bordure des champs, texte blanc sur rouge vif), l'accessibilité prévaut.
- T210 : la carte de recommandation du design devient « Votre prochaine action », sans calcul ni promesse : elle reprend une tâche en retard ou un retour en attente.
- A57 ouvert : écrans du design correspondant à des fonctions hors V1 ou absentes (assistant IA, Premium et paiement, documents, difficultés, absences, annonces, graphique de progression, recherche globale, connexion Microsoft). Aucun n'est construit sans décision explicite, fonction par fonction.
- AN28-01 — Icônes de l'application installée encore à l'ancienne couleur. Assignée : à refaire avec l'identité visuelle définitive (A05).

## Décision du porteur — accueil élève repensé, 24 novembre 2026

- Décision du porteur : appliquer le design « accueil élève repensé » (accueil, ajout rapide, version ordinateur).
- T211 : la police Outfit (licence OFL) remplace Plus Jakarta Sans, toujours hébergée dans l'application. T207 est remplacée.
- T212 : l'en-tête à onglets remplace la barre latérale ; les liens d'administration passent dans une bande sous l'en-tête, visible des seuls comptes concernés.
- T213 : après un changement de contexte, la personne reste sur l'écran d'origine s'il fait partie des écrans communs (accueil, planning, tâches, notes, opportunités, matières, profil). Seul le nom de la route est repris ; l'adresse reçue et ses paramètres ne servent jamais à la redirection. Sinon, retour au profil comme avant.
- T214 : l'ajout rapide n'ajoute aucune route d'écriture. Il envoie aux routes existantes des tâches, des notes et du planning, avec une clé de demande ; les contrôles de propriétaire et de contexte sont inchangés.
- T215 : les données de l'ajout rapide (fuseau, matières, période en cours du compte) ne sont chargées qu'à l'ouverture du panneau.
- T216 : cocher une tâche sur l'accueil passe par la route de modification de la tâche, avec sa version attendue et son contenu inchangé ; pour un devoir d'établissement, par la route d'avancement personnel.
- T217 : le tutoiement du design est repris sur l'accueil, l'ajout rapide et la navigation. T208 ne vaut plus pour ces écrans.
- T218 : sur l'accueil, la provenance n'est affichée que pour ce qui vient d'un établissement ; ce qui est personnel ne porte plus d'étiquette.
- A58 ouvert : passer toute l'application au tutoiement, ou revenir au vouvoiement sur l'accueil. Les deux registres cohabitent en attendant.
- A59 ouvert : traiter les autres points de la revue d'ergonomie (formulaires de création en haut de page, vocabulaire du planning, formulaire de cours allégé, bruit sur les écrans).
- AN29-01 — Sur téléphone, l'écran des tâches n'a plus d'entrée dans la navigation basse. À arbitrer avec le porteur.
- AN29-02 — Le script des parcours place son cours d'essai sur un créneau de quatre minutes calculé sur l'heure : deux lancements à moins de quatre minutes d'écart se chevauchent et échouent. Défaut du script, sans lien avec ce travail.

## Consigne du porteur — facilité, 24 novembre 2026

- Consigne du porteur : « le mot d'ordre c'est facilité » ; alléger tous les processus possibles.
- T219 : un formulaire de création ne montre que les champs indispensables ; les autres sont repliés sous « Plus d'options » avec une valeur par défaut, et s'ouvrent seuls en cas d'erreur.
- T220 : le code d'une référence (matière, période, groupe, type, année) est tiré de son nom à la création et reste unique dans son périmètre ; il se modifie ensuite. La modification exige toujours un code : il n'est jamais régénéré en silence.
- T221 : l'allègement porte sur la saisie et le vocabulaire. Les contrôles de propriétaire, d'appartenance, de permission et de version ne changent pas.
- T222 : le rappel de la notice de confidentialité est limité à l'accueil et au profil ; il reste non bloquant.
- A59 partiellement traité : restent la liste des fuseaux, les écrans d'administration et ceux de l'enseignant.

- A60 ouvert (24 novembre 2026) : images des fiches d’opportunité. Options : image déposée par l’équipe éditoriale et servie par Campus (passe par l’antivirus, absent en local) ; image chargée depuis le site source (contraire à la règle « aucun appel sortant » et à la politique de sécurité, à lever explicitement). En attendant, un visuel par catégorie.
