# Pilote — HAKO-RA Campus V1

Lot 25, 23 novembre 2026. Document de préparation. Aucun pilote n'est ouvert, aucun partenaire n'est acquis, aucune personne réelle n'a encore utilisé Campus.

## 1. Avant d'ouvrir quoi que ce soit

Le pilote ne peut pas démarrer en l'état. Les conditions sont détaillées dans docs/bilan-livraison-v1.md. En résumé :

| Condition | Nécessaire pour |
|---|---|
| Hébergement, nom de domaine, HTTPS, sauvegardes configurées | tout pilote |
| Service d'envoi de courriels essayé pour de vrai | tout pilote : sans lui, aucun compte ne peut vérifier son adresse ni retrouver son mot de passe |
| Restauration complète réussie dans un environnement isolé | tout pilote |
| Notice de confidentialité complétée et validée | tout pilote |
| Canal de support et personne qui répond | tout pilote |
| Second facteur activé par chaque compte d'enseignement, d'administration et d'édition (livré ; à essayer avec une vraie application) | établissements et catalogue |
| Antivirus installé | toute pièce jointe téléchargeable |
| Cadre d'accueil des mineurs validé par des personnes qualifiées | lycée |
| Responsable éditorial désigné, 20 à 30 annonces réelles vérifiées par lui | ouverture du catalogue |

## 2. Cohortes proposées

Ce sont des propositions. Aucun établissement, aucune personne n'a donné son accord.

| Étape | Qui | Taille | Ce qu'on y apprend | Condition pour passer à la suivante |
|---|---|---|---|---|
| 1 | Personnes majeures, sans établissement | 30 à 50 | Inscription, planning personnel, tâches, notes, retours, usage sur téléphone | Aucune anomalie bloquante ouverte ; sauvegarde quotidienne constatée ; indicateurs relevés deux semaines de suite |
| 2 | Une structure du supérieur | 50 à 100 actifs | Invitations, import du planning, publication, devoirs, cloisonnement | Second facteur activé par chaque compte privilégié ; un import réel réussi avec les fichiers de la structure ; capacité mesurée |
| 3 | Un lycée | 50 à 100 actifs | Mêmes parcours, public mineur | Cadre des mineurs validé et dispositif de preuve en place ; rien ne s'ouvre avant |

L'ordre met le lycée en dernier parce que le parcours des mineurs est fermé dans le logiciel tant qu'un cadre validé n'est pas référencé. La montée se fait par paliers : on n'ajoute une cohorte que si la précédente tourne sans alerte de supervision et si la charge mesurée le permet. La mesure de charge de référence n'a pas encore été faite.

Le catalogue des opportunités suit son propre calendrier. Il s'ouvre quand un responsable éditorial désigné a saisi et vérifié lui-même 20 à 30 annonces réelles et pertinentes. Les fiches fictives de la base de développement ne doivent jamais servir à le remplir. La validation du logiciel ne couvre pas ce travail éditorial.

## 3. Installer Campus sur son appareil

Campus s'utilise dans le navigateur. L'installation ajoute seulement un raccourci.

- **Android, Chrome ou Edge** : ouvrir Campus, se connecter, ouvrir « Hors connexion » en bas de page, toucher « Installer le raccourci Campus ». Si le bouton n'apparaît pas : menu du navigateur, « Ajouter à l'écran d'accueil ».
- **iPhone, Safari** : ouvrir Campus dans Safari, bouton de partage, « Sur l'écran d'accueil ».
- **Ordinateur, Chrome ou Edge** : même écran, même bouton, ou l'icône d'installation dans la barre d'adresse.

À dire à chaque personne :
- Sur un appareil **à soi seul**, on peut activer la lecture hors connexion sur ce même écran : planning des prochains jours, tâches ouvertes, notes en lecture seule. On peut alors écrire une tâche ou un retour de cours sans réseau ; ils partent au retour du réseau.
- Sur un appareil **partagé ou prêté**, ne pas l'activer.
- Refuser les notifications ne bloque rien : tout arrive dans le centre de notifications de Campus.
- Se déconnecter efface les données gardées sur l'appareil ; Campus prévient s'il reste des brouillons non envoyés.

Non vérifié : l'installation n'a été essayée que dans Edge sur ordinateur. Android, iPhone, Safari et Firefox restent à essayer pendant l'étape 1.

## 4. Guides de démarrage

### Apprenant

1. Créer son compte (nom, adresse, mot de passe), puis ouvrir le lien reçu par courriel. Ou, si elle est activée, « Continuer avec Google » : l'adresse est alors déjà vérifiée.
2. Sur l'accueil, suivre la liste d'étapes de démarrage : fuseau horaire, une matière, un cours, une tâche.
3. **Planning** : ajouter ses cours, ponctuels ou chaque semaine. Les cours d'un établissement apparaissent avec la mention « Établissement » et ne se modifient pas.
4. **Après un cours** : l'accueil propose « Retours à renseigner ». Le retour est privé : ni enseignant ni administration ne le lisent.
5. **Tâches** : ajouter, découper en étapes, terminer. Les devoirs d'un groupe arrivent ici ; l'avancement reste personnel.
6. **Notes** : saisir ses notes ; la moyenne est indicative et expliquée. Les résultats publiés par un enseignant s'affichent à part et ne se modifient pas.
7. **Rejoindre un établissement** : ouvrir le lien d'invitation en étant connecté, puis choisir le contexte en haut de l'écran. Chaque établissement a ses propres données.
8. **Opportunités** : chercher, mettre en favori, enregistrer une recherche pour être prévenu, suivre sa démarche. Campus ne dépose jamais de candidature.
9. **Ses données** : « Confidentialité » en bas de page pour exporter ou demander la suppression ; Profil pour quitter un établissement.

### Enseignant

1. Accepter l'invitation de l'établissement, puis activer le second facteur : Profil, « Protéger mon compte par un code ». Il faut une application d'authentification sur son téléphone (Google Authenticator ou compatible). Noter les codes de secours sur papier.
2. Choisir le contexte de l'établissement.
3. **Planning** : consulter ses cours publiés. En cas d'empêchement : « Signaler mon indisponibilité » sur le cours ; l'administration décide et publie.
4. **Devoirs de mes groupes** : publier un devoir pour un groupe où l'on est affecté. On ne voit pas l'avancement individuel des apprenants.
5. **Notes** : créer une évaluation, saisir les résultats, publier. Une correction après publication est tracée.
6. Un enseignant ne lit ni les retours de cours ni les notes personnelles des apprenants.

### Administration d'un établissement

1. Le compte est ouvert par l'administration de la plateforme. Activer le second facteur depuis le profil avant toute chose : sans lui, les écrans d'administration restent fermés.
2. Suivre les sept étapes de mise en route de l'accueil : année, périodes, groupes, matières, affectations, planning, publication.
3. **Inviter** : un lien à usage unique par personne, affiché une seule fois. Pour un apprenant sans adresse : compte provisionné avec identifiant et code d'activation.
4. **Planning** : saisie manuelle, ou import Excel à partir du modèle fourni. L'import s'analyse d'abord, sans rien modifier ; son rapport indique chaque ligne en erreur.
5. **Publier** : l'écran montre les différences, demande un motif et une confirmation. Le retour à une version antérieure est possible.
6. **Retirer un accès** : l'appartenance se ferme, le compte et l'espace personnel de la personne restent.
7. L'administration ne lit ni les retours de cours, ni les tâches, ni les notes personnelles, ni les démarches. Il n'existe pas d'accès « support » à ces données.
8. Avant un import réel : essayer le modèle avec un vrai fichier de l'établissement (Excel, LibreOffice). Cet essai n'a pas encore été fait.

### Édition du catalogue

1. Compte ouvert par l'administration de la plateforme. Activer le second facteur depuis le profil : sans lui, le catalogue éditorial reste fermé.
2. Choisir le contexte « Édition du catalogue ».
3. **Nouvelle fiche** : titre factuel, organisateur, campagne, adresse de la source officielle, conditions telles que publiées. Ne rien inventer : une date sans heure reste sans heure, un champ inconnu reste vide.
4. **Vérifier** : ouvrir soi-même la source, puis cocher l'acte de vérification. Il est daté et enregistré à son nom. Campus n'interroge jamais la source.
5. **Publier**, puis suivre : prolongation (les personnes qui suivent la fiche sont prévenues une fois), suspension immédiate en cas de doute, archivage à la clôture.
6. **Signalements** : les traiter dans la file dédiée.
7. Une nouvelle année est une nouvelle édition, pas une modification.

### Second facteur, pour les trois rôles ci-dessus

- À chaque connexion, Campus demande le code à six chiffres affiché par l'application.
- Téléphone perdu : utiliser un code de secours, puis en créer de nouveaux.
- Téléphone et codes perdus : le support retire le second facteur après avoir vérifié l'identité par un autre moyen (`php artisan campus:second-factor:reset`, motif obligatoire, tracé). La personne en active ensuite un nouveau.
- Il n'y a pas de code QR : la clé se saisit à la main dans l'application, ou s'ouvre par un lien sur le téléphone.

## 5. Support

- Canal de support : **à renseigner** (`CAMPUS_SUPPORT_EMAIL`). Tant qu'il est vide, l'écran d'aide l'indique et aucun contact n'est affiché.
- Personne qui répond, suppléant, délai de réponse visé : **à renseigner**.
- Le support n'a aucun accès aux données privées et ne peut pas se connecter à la place de quelqu'un. Il ne demande jamais un mot de passe.

## 6. Signaler une anomalie

Formulaire à reprendre tel quel dans le canal de support choisi.

```text
Signalement d'anomalie — HAKO-RA Campus

Ne mettez ici aucun mot de passe, aucun code d'activation, aucun lien d'invitation,
aucune note ni aucun texte privé. Sur une capture d'écran, masquez les noms des autres personnes.

1. Date et heure approximative :
2. Appareil et navigateur (par exemple : téléphone Android, Chrome) :
3. Version affichée en bas de page :
4. Écran concerné (Planning, Tâches, Notes, Opportunités, Profil, autre) :
5. Votre rôle sur cet écran (apprenant, enseignant, administration, édition) :
6. Ce que vous vouliez faire :
7. Ce qui s'est passé à la place :
8. Message affiché, recopié tel quel (et le numéro de requête s'il y en a un) :
9. Cela se reproduit-il ? (toujours / parfois / une seule fois)
10. Étiez-vous hors connexion ou sur un réseau faible ?
11. Gravité selon vous : je suis bloqué / je contourne / c'est gênant
```

Tri proposé : accès à la donnée d'un autre, perte de données, calcul faux ou rappel erroné = arrêt du palier en cours et correction avant de continuer. Le reste est classé puis traité par lot.

## 7. Indicateurs

Aucun outil de mesure d'audience n'est installé et aucun tableau de bord n'existe. Ces indicateurs se relèvent chaque semaine par des comptages sur la base, faits par la personne chargée de l'exploitation. Ce sont des nombres : aucun contenu privé n'est lu.

| Indicateur | Définition | Source |
|---|---|---|
| Activation | Part des comptes créés qui, sous 7 jours, ont au moins un cours et une tâche | comptes, cours, tâches |
| Usage récurrent | Comptes vus au moins 3 semaines sur 4 | dernière activité du compte, relevée chaque semaine |
| Retours après cours | Part des cours terminés suivis d'un retour, d'un « rien à signaler » ou d'un report | retours de cours (nombre seulement) |
| Tâches | Tâches créées et terminées par compte actif | tâches |
| Imports | Lots déposés, valides au premier essai, appliqués ; lignes en erreur par cause | lots d'import |
| Publications | Publications, retours à une version antérieure | versions du planning |
| Rappels | Programmés, remis au centre, expirés, en échec ; délai de mise en file | notifications et envois |
| Hors connexion | Appareils personnels activés ; opérations refusées ou en conflit | accusés de synchronisation |
| Opportunités | Favoris, recherches enregistrées, signalements, délai de traitement | favoris, recherches, signalements |
| Fiabilité | Alertes de supervision, anomalies par gravité, âge de la dernière sauvegarde | supervision, support |

« Lu » n'est pas mesuré pour les courriels ; seul le centre de notifications connaît l'état lu.

## 8. Retour des utilisateurs

- Après deux semaines : cinq à huit entretiens courts par cohorte. Questions : ce que la personne a fait la première fois, où elle s'est arrêtée, ce qu'elle utilise chaque semaine, ce qu'elle n'a pas compris, ce qui lui manque.
- Essai chronométré avec une personne qui découvre Campus : créer un cours puis une tâche. Cet essai n'a jamais été fait avec une vraie personne.
- Essai avec un lecteur d'écran et au clavier seul, sur téléphone et ordinateur.
- Les retours sont consignés sans nom, avec la cohorte et la date.

## 9. Déroulé d'un palier

1. Vérifier les conditions du § 1 pour ce palier.
2. Mise en service selon docs/exploitation-v1.md ; `php artisan campus:security:check` sans réglage bloquant.
3. Ouvrir à cinq personnes pendant trois jours, puis au reste de la cohorte.
4. Chaque semaine : indicateurs, anomalies, supervision, sauvegarde vérifiée.
5. Fin de palier : bilan écrit, décision du porteur de continuer, de corriger ou d'arrêter.
