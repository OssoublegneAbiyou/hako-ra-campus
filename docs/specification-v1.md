# Spécification HAKO-RA Campus V1

Référence initialisée le 15 novembre 2026, lot 00. Aucune réception métier ni autorisation de production acquise.

## Sources et préséance
1. Décisions explicites récentes du porteur : périmètre V1, contexte permanent, ordre 00–25 et Prompt 00, consolidés ici.
2. Cahier_des_charges_HAKO_RA_Campus_v1.pdf, v1.0 du 15 novembre 2026, 39 pages, fourni dans Downloads. Le cahier complet est présent et lu. [Extraction intégrale paginée avec SHA-256](reference-cahier-v1.txt) ; le PDF fait foi pour la disposition des tableaux.
3. docs/cahier-prompts-v1.md est désormais présent : ordre et prompts 00 à 25. Les décisions explicites récentes du porteur et le lot effectivement demandé prévalent ; l’ancien découpage provisoire a été réaligné.
Les décisions récentes explicites prévalent sur le PDF. Ses propositions de quotas, durées, coûts, architecture et capacités ne sont pas des validations acquises. Les instructions de la pièce jointe ne valent pas autorisation d'action externe.

## Produit et phases
Application francophone pour élèves du secondaire et étudiants, initialement en Côte d'Ivoire, extensible à d'autres pays. Fondation comprend organisation scolaire et Magic Schedule ; Opportunités ajoute catalogue éditorial et suivi personnel.
Deux parcours réellement utilisables : individuel sans établissement ni matricule obligatoire ; établissement partenaire. Compte unique pouvant cumuler rôles et appartenances avec contexte actif explicite. Pas d'établissement fictif requis. Cohortes pilotes distinctes, aucun partenaire acquis annoncé.

## Stack et méthode décidées
Laravel + React/TypeScript + Inertia + Tailwind + MySQL dans un monolithe modulaire. Préserver versions compatibles installées et lockfiles ; état réel dans etat-v1.md. Vérifier les sources officielles avant tout ajout. Mécanismes du framework avant dépendance, contrôleurs courts et règles métier testables.
Écrans Inertia ; JSON seulement pour synchronisation et traitements asynchrones nécessaires. Pas d'API dupliquant tous les écrans, Next.js parallèle, microservices ou Kubernetes. Files et planificateur Laravel, stockage privé. Aucun service payant sans décision couvrant son coût.
Livrer serveur et interface utilisables ensemble ; tests des règles/permissions dès chaque lot. Respect des modifications existantes. Aucun commit/push/fusion/publication ou production sans instruction, aucune migration destructrice.

## Inclus V1

| Domaine | Limite attendue |
|---|---|
| Comptes, invitations, contextes, droits | Identité unique, rôles par périmètre, autorisation serveur |
| Organisation | Années, périodes, groupes, matières, affectations isolés et archivables |
| Planning | Personnel/institutionnel, jour/semaine, récurrences hebdomadaires, exceptions, publication versionnée |
| Import Excel | XLSX documenté, simulation, rapport, lot atomique, déduplication |
| Étude | Retour privé, devoir collectif, progression personnelle, tâches/microtâches |
| Notes | Provenance et moyennes indicatives expliquées |
| Rappels | Centre interne, courriel et Web Push conditionnels |
| Opportunités | Catalogue vérifié, recherche, favoris, alertes, démarches privées |
| PWA | Installation, lecture locale limitée et brouillons personnels synchronisables |
| Fichiers | PDF/JPEG/PNG privés, quotas et analyse antivirus |
| Administration | Audit, demandes de droits, sauvegardes, restauration, supervision |

## Exclus et évolutions
Academy, bibliothèque de cours officiels, assistant IA/tutorat/génération de cours, paiement/facturation intégrés, abonnements automatisés, applications natives, réseau social, forum/chat public, scolarité complète/bulletin officiel, présences, sanctions, frais scolaires, paie, candidature déposée au nom de l'utilisateur, collecte massive automatisée d'annonces.
Portail parental complet, SSO Google/Microsoft et ICS (SCH10 P1) sont ultérieurs. SMS et WhatsApp ne sont pas activés et ne doivent pas devenir une dépendance V1. Aucun bouton sans fonction pour ces exclusions.

## Identité, confidentialité et intégrité
Chaque requête, mutation, job, export, fichier et cache contrôle propriétaire, établissement, appartenance active et permission. institution_id venant du client ne constitue jamais une autorisation. Aucun simple users.role global ; rôles liés à établissement/groupe/plateforme. Désactivation d'appartenance : accès retirés immédiatement, identité et espace individuel conservés.
Pas de passe-droit plateforme pour les notes privées, retours et démarches. Aucune impersonation ni accès de support privé en V1 : R58 attend un refus, contrairement à la branche exceptionnelle proposée par le PDF.
Provenance personnelle/institutionnelle explicite. Aucun secret en sortie, Git, fixture ou log ; données fictives dans les tests. Pas de fetch serveur arbitraire d'URL fournie par l'utilisateur.
Erreurs JSON : code stable, message français, champs et request_id ; session absente, interdit, introuvable, validation, conflit de version, quota et fréquence distingués sans révéler une ressource étrangère.
Invitations temporaires non devinables, révocables et expirables. Proposer la connexion au compte existant avant création. Matricule local non secret et non identifiant global ; vérifier le rattachement, cas ambigu en attente. Comptes sans email personnel : invitation et récupération sûre, aucun secret partagé par classe.
Connexion/déconnexion, récupération sans oracle d'existence, révocation et expiration ; profil modifiable et préférences. Aucun rôle privilégié auto-attribué ; MFA et secours, revérification et audit des changements sensibles.

## Référentiels et planning
Établissement créé par plateforme habilitée ; années et périodes datées par établissement, chevauchements incohérents refusés. Groupes et matières avec affectations limitées. Historiser changements de groupe et départs ; archiver les référentiels utilisés sans recopier les notes/tâches personnelles à la nouvelle année.
Série de cours distincte des occurrences avec identifiant stable malgré report. Jour/semaine mobile et événements personnels tous jours, week-end compris. Fuseau du planning conservé et instants UTC ; ne pas supposer toute échéance internationale en heure d'Abidjan.
Prévisualisation avant publication, historique version/auteur/date/motif ; ancienne version conservée. Modifier une occurrence ou le futur de la série explicitement. Vacances, annulations, remplacements et reports. Un enseignant signale une indisponibilité ; l'administration publie l'exception. L'apprenant ne modifie pas le planning officiel.
Détecter conflit de groupe/enseignant/salle ; doublon exact bloqué, autres conflits soumis à confirmation motivée. Publication/retour de version recalcule les rappels futurs et avertit les seuls destinataires autorisés.

## Import XLSX
Modèle sans macro : codes groupe/matière, jour ou date, début/fin ; enseignant, salle, validité, récurrence et clé externe facultatifs documentés.
Contrôler extension, type réel, taille, feuilles, lignes et cellules. Références inconnues jamais créées implicitement. Simulation et rapport feuille/ligne/colonne/cause, erreurs et conflits.
Application transactionnelle au brouillon : aucune ligne si erreur bloquante. Publication séparée avec confirmation. Lot et empreinte dédupliquent les soumissions ; mise à jour par clé stable, comparaison explicite sinon. Retour à version antérieure réconcilie alertes sans effacer historique ; fichier brut purgé selon conservation.

## Retours et tâches
Rappel post-cours 5 minutes par défaut, configurable/désactivable ; formulaire accessible sans notification. Un retour principal par apprenant/occurrence, modifiable et historisé ; champs facultatifs sauf cours, action « rien à signaler ». Commencer/reporter/ignorer sans pénalité ; retour déjà saisi ou cours annulé supprime rappel inutile ; report sans double retour.
Résumé et difficultés privés. Agrégats seulement autorisés sans détail identifiable. Brouillon personnel hors réseau.
Tâche personnelle : titre, description/matière/échéance facultatifs, priorité, propriétaire et cours éventuel. Devoir collectif publié seulement par enseignant affecté, progression individuelle séparée. États à faire/en cours/terminé/abandonné ; retard calculé. Microtâches et prochaine action. Échéance officielle conservée avec objectif personnel distinct. Filtres et tâches sans échéance. Modification collective notifiée une fois sans perdre progressions ; confirmation suppression, restauration courte et rappels annulés pour terminé/abandonné.

## Notes et moyennes
Valeur entre 0 et barème, barème et coefficients strictement positifs, matière/période/date/provenance. Note personnelle distincte de note institutionnelle non remplaçable par apprenant ; corrections institutionnelles auditées.
Normalisation = 20 × valeur/barème.
Moyenne matière = somme(normalisée × coefficient évaluation)/somme(coefficients des notes retenues).
Moyenne générale = somme(moyenne matière non arrondie × coefficient matière)/somme(coefficients des matières disposant d'une moyenne).
Absence, dispense, non rendu et manquant distincts, jamais zéro implicite. Couverture matières/évaluations visible, résultat indicatif/provisoire ; aucun bulletin officiel. Décimaux en base et arrondi d'affichage à deux décimales. Annuel seulement avec pondération validée, pas de mélange doublement pondéré.
Recette source : 14/20 coefficient 1, 36/60 coefficient 2 → 12,67 ; maths coefficient matière 3 et français 15 coefficient 2 → général 13,60. Matière sans note ne baisse pas silencieusement la moyenne.
Recalcul après correction/suppression. Critères de difficulté expliqués, seuil configurable, sans diagnostic d'incapacité.

## Rappels
Centre interne consultable, refus d'un canal ne bloque pas le planning. Préférences catégories/canaux/silence/synthèses, sécurité distincte. Échéances calculées serveur, pas d'onglet ouvert requis.
Déduplication utilisateur/événement/occurrence/type/canal. Recontrôle date/droits/préférences avant envoi ; annulation, expiration, reprise bornée avec délais croissants et échec contrôlable. États programmé/en file/en traitement/accepté/échec/indéterminé/annulé/expiré ; « lu » uniquement si mesuré.
Pas de promesse d'exactement une fois externe ; réponse perdue réconciliée si fournisseur le permet. Plafonner/regrouper le non critique. Pas de note chiffrée ou difficulté privée sur écran verrouillé par défaut. Push par appareil, abonnements invalides révoqués, capacités et prérequis expliqués.

## Opportunités
Bourses, concours, stages, formations : titre factuel, catégorie, organisateur, source officielle, résumé, niveau, critères distincts pays/nationalité/destination, modalité, financement, frais et pièces éventuelles, dates avec précision/fuseau, date/éditeur du contrôle.
Valeurs manquantes non inventées ; date sans heure conservée, clôture continue codée. Source vérifiée ne garantit ni éligibilité ni attribution.
Brouillon → à vérifier → publié/suspendu/archivé ; contrôle explicite même si éditeur unique. Source accessible ou preuve officielle autorisée. Doublons source/organisateur/titre/campagne ; éditions distinctes. Archivage à échéance, révision datée, ancienne date conservée, alertes réconciliées.
Signalement confidentiel, catégories et file affectée avec statut/décision/commentaire interne ; suspension suspecte immédiate, historique préservé.
Catalogue publié public ; recherche titre/organisateur/domaine, filtres catégorie/niveau/pays/modalité/financement/langue/clôture, pagination et tri expliqués. Rapprochement déterministe, « à vérifier » si profil incomplet, jamais certitude d'inéligibilité inventée.
Favori unique multiappareil ; favoris clos/suspendus visibles. Recherches enregistrées avec fréquence et arrêt sans perte de favoris ; pas de répétition de la même annonce sans mise à jour identifiée.
Démarches privées, états manuels (à étudier, préparation, envoyée, attente, retenue, non retenue, abandonnée), échéance/note/listes personnelles. Cliquer ne marque pas envoyée. Candidature externe avec destination visible, aucune donnée privée transmise automatiquement ; pas de coffre passeport/document bancaire. Historique résumé daté après archivage.

## PWA et fichiers
Coque publique séparée du stockage privé lié au compte. Cache privé opt-in sur appareil personnel, non persistant par défaut sur partagé. Fenêtre limitée, âge et états local/en attente/synchronisé/conflit visibles.
Brouillons de tâches personnelles et retours ; notes déjà autorisées en lecture seulement. Administration/publication, nouvelle authentification/rattachement, saisie des notes et nouveaux fichiers en ligne.
Identifiant client, idempotence et version serveur ; conflit visible sans écrasement institutionnel. Droits recontrôlés à la synchronisation ; retour application et bouton manuel sans dépendre du background sync. Purge privée au logout/changement de compte, avertir des brouillons. Mise à jour service worker/migration locale sans perte. Révocation ne peut effacer immédiatement un appareil déconnecté, limite expliquée.
PDF/JPEG/PNG : type réel, extension, taille/nom, identifiant de stockage opaque. Stockage privé et droits sur parent à chaque accès, lien court ne suffit pas à autoriser. Antivirus/quarantaine, quotas et purge orphelins ; aucun privé devenu collectif implicitement. Liens externes contrôlés sans import automatique ; droits de partage à respecter.

## Données et ouverture
Collecte minimale, pas de biométrie/géolocalisation précise/dossier santé. Notices versionnées, finalités/responsables/destinataires/conservation/droits/contacts/transferts à préciser ; consentements facultatifs distincts.
Demandes accès/rectification/opposition/effacement suivies, identité revérifiée proportionnellement. Export proposé avant suppression et conséquences expliquées. Conservation institutionnelle justifiée distincte du privé.
Restauration réapplique révocations/effacements ; ne pas annoncer effacement instantané de toutes les sauvegardes.
Mineurs : cadre applicable et dispositif de preuve à valider avant ouverture concernée ; aucun âge légal inventé et aucune simple case d'activation.

## Interface et exploitation
États chargement/vide/erreur récupérable/interdit/hors réseau/session expirée/succès ; saisie préservée après échec, reprise possible, double clic protégé et déduplication serveur. Prévenir abandon de saisie et confirmer l'irréversible.
Mobile 360 px, clavier/focus, contrastes, statut textuel, zoom 200 %, erreurs reliées aux champs, mouvement réduit et ressources légères. Cible WCAG 2.2 AA sans conformité revendiquée avant audit ; navigateurs/appareils testés au pilote.
Local/recette/production séparés ; déploiement reproductible identifié, sauvegarde avant migration, rollback code distinct d'une restauration perdant des écritures. Contrôles de connexion/planning/tâche/fichier/worker après déploiement.
Superviser site/base/workers/scheduler/stockage/envois ; alertes file, sauvegarde, disque, erreurs, certificat et volumes. Sauvegarde base/fichiers quotidienne chiffrée hors instance, restauration isolée avant pilote, reprise sans renvoi d'anciennes alertes. Responsables, incidents, rotation de clés/prestataires et accès de secours documentés.

## Paramètres proposés, non validés
PDF p.26/38 : délai post-cours 5 min confirmé ; plage 0–120 min, expiration 2 h, silence 21 h–7 h, cache planning 14 jours/revue après 7 jours, fichier 5 Mo/3 par objet/100 Mo par apprenant, import 5 Mo/5 000 lignes, catalogue 20 par page, synthèse opportunités hebdomadaire.
Conservation proposée : revue inactivité 24 mois, invitations/imports 30 jours, notifications/livraisons 90 jours, audit 12 mois, export/corbeille 7 jours, sauvegarde 30 jours. Aucun délai légal affirmé.
Cibles p.27 proposées et non mesurées : 5 000 comptes/1 000 actifs quotidiens, 100 simultanés pendant 30 min, p95 serveur <800 ms, contenu utile <3 s en réseau 1,6 Mbit/s/latence 150 ms, 95 % rappels mis en file <2 min, erreurs techniques <1 %, import 1 000 lignes <2 min, disponibilité 99,5 %, RPO 24 h, RTO 8 h ouvrées. Aucun SLA ou capacité acquis.

## Acceptation
Droits/calculs testés au fil des lots. Compilation et tests du squelette ne valident pas le métier. P0 de chaque phase, cas associés, anomalies corrigées et sauvegarde restaurable conditionnent son ouverture ; Fondation reçue ne signifie pas Opportunités reçue.
Quatre semaines indicatives ; au-delà, démonstration fictive ou phase P0 satisfaite seulement, écarts et calendrier nommés. Voir état, recette et arbitrages.

## Index exigences / lots proposés
Les identifiants et priorités intégraux sont conservés dans la référence chap.5–22 et la matrice chap.28 ; les prescriptions récentes ci-dessus prévalent.

| Références | Lots |
|---|---|
| IAM01–IAM10 | 03–04,06,21 proposés après réception du Prompt 02 de navigation ; IAM08 SSO ultérieur |
| ORG01–ORG09 | 04–06 |
| SCH01–SCH10 | 07–08 ; SCH10 ICS P1 ultérieur |
| IMP01–IMP06 | 09 |
| CHK01–CHK06 | 10 |
| TSK01–TSK09 | 11 |
| GRD01–GRD07 | 12 |
| NTF01–NTF09 | 13–14 |
| OPP01–OPP10 | 16–17 |
| SEA01–SEA05 | 17 |
| FAV01–FAV05, APP01–APP05 | 18 |
| UX01–UX05 | chaque lot interface |
| OFF01–OFF10 | 19–20 |
| FIL01–FIL06 | 15 |
| API01–API04 | 03,09,20 et contrats concernés |
| SEC01–SEC12 | transversal 02–25 |
| PRV01–PRV06 | 21,23 |
| QUA01–QUA07 | transversal et 24 |
| OPS01–OPS09 | 23–25 |


## Précision de réalisation — Prompt 02 reçu
Le lot 02 construit navigation et composants communs, conformément au prompt explicite récent ; l'identité attribuée provisoirement à ce numéro au diagnostic reste à réaliser. Navigation mobile Accueil/Planning/Tâches/Opportunités/Profil ; Notes sur Accueil et espace d'études, contexte visible, administration seulement avec permission. Présentation proposée et modifiable, aucun achat de police/asset.
Les écrans non construits sont explicitement signalés en développement ; pas de statistique fictive publiée. Atelier de composants et exemples strictement locaux/testing, refusés côté serveur en production. Validation à 360 px, ordinateur, clavier, focus, labels et repli 200 % ; état réel des preuves et limites dans recette-v1.md.


## Précision de réalisation — Prompt 03 reçu
Le lot 03 porte sur le modèle et les frontières de visibilité, pas sur les écrans d'authentification. Socle migré et contrats futurs dans architecture-v1.md ; aucun rôle global users.role, aucun tenant implicite.
Un compte peut cumuler learner A, teacher B et espace individuel. Les NULL institution/année du scope platform sont explicitement contraints ; ils ne rendent pas une ressource publique. Les objets privés conservent un propriétaire obligatoire ; les futurs objets publics auront une publication explicite.
Les retraits sont datés et ne suppriment ni compte ni privé. La conservation actuellement vérifiée porte sur les préférences de l'espace individuel ; aucune validation de notes/retours/démarches encore absents n'est annoncée. IAM02 (provisionnement sans email), sessions, MFA et autres P0 d'identité restent à réaliser et ne sont pas supprimés du périmètre.


## Précisions fonctionnelles livrées au lot 06
Voir architecture-v1.md et arbitrages-v1.md : référentiels institutionnels dans l'année du contexte, espaces personnels propriétaires sans établissement, types de période configurables, périodes parentes explicites, archives sans suppression, reprise sélective d'année. Recherche/pagination et contrôles d'accès réels livrés ensemble ; l'administration reste fermée hors dérogation locale tant que R10/MFA est bloqué.


## Planning livré au lot 07
Planning personnel et prévisualisation institutionnelle, jour/semaine/liste, identifiants stables, récurrence hebdomadaire bornée, exceptions et historique. Paramètres réversibles explicités dans arbitrages-v1.md : 56 jours de génération, 730 jours de validité, une plage quotidienne de 12 h maximum sans minuit, rejet des heures locales ambiguës/inexistantes. Publication officielle et recalcul des rappels restent aux lots 08/13 ; aucun P0 correspondant déclaré validé par la seule gestion des brouillons.


## Décisions du porteur du 23 novembre 2026
Second facteur des comptes privilégiés : code temporaire à six chiffres (application d'authentification). Connexion par compte Google : entre dans la V1 par décision explicite, en dérogation à la ligne « SSO Google/Microsoft ultérieur » ; Microsoft reste ultérieur. Elle ne donne aucun rôle, ne dispense pas du second facteur, et n'est active qu'avec des identifiants fournis par le porteur. Détail dans arbitrages-v1.md et architecture-v1.md.
