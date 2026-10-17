# CAHIER DES CHARGES

Conception et réalisation d'un système intelligent d'orientation des citoyens vers les services administratifs

Rubrique | Information Projet | Projet de licence \- Système intelligent d'orientation des citoyens (web et mobile) Établissement / filière | \[à compléter\] Équipe projet | \[noms, matricules et rôles des membres\] Encadrant(s) | \[à compléter\] Année universitaire | \[à compléter\] Version / date | 1.0 \- 21 septembre 2026 Statut | Projet de cahier des charges à valider par l'encadrant

Suivi des versions Version | Date | Auteur | Modification 1.0 | 21/09/2026 | \[équipe\] | Première rédaction complète

Sommaire

1. Introduction : contexte, problématique, objectifs, état de l'existant  
     
2. Périmètre du projet  
     
3. Acteurs et rôles  
     
4. Catalogue des services administratifs à couvrir  
     
5. Besoins fonctionnels  
     
6. Besoins non fonctionnels  
     
7. Exigences du moteur d'orientation  
     
8. Architecture et choix techniques proposés  
     
9. Données principales  
     
10. Contraintes  
      
11. Livrables  
      
12. Planning et jalons  
      
13. Organisation de l'équipe  
      
14. Recette et critères d'acceptation  
      
15. Risques  
      
16. Points à valider avec l'encadrant  
      
17. Références Annexes : glossaire, validation  
      
18. Introduction 1.1 Contexte L'administration publique propose de nombreux services aux citoyens : état civil, identité, justice, fiscalité, foncier, transport, éducation, santé, création d'entreprise, etc. Cette diversité de services et de procédures rend difficile, pour le citoyen, l'identification du bon service, des pièces à fournir et des étapes à suivre. Faute d'orientation préalable, les citoyens se déplacent plusieurs fois, patientent dans de longues files d'attente et surchargent les guichets. Les administrations n'ont pas non plus de vision statistique des besoins réels des usagers. Le présent projet prend pour cadre l'administration publique malgache, dont les démarches sont documentées dans des sources publiques (voir chapitre 17). La conception reste générique : le catalogue de services est une donnée modifiable, et non du code, afin de pouvoir adapter la plateforme à une autre administration.

1.2 Problématique Comment concevoir un système intelligent capable d'orienter efficacement les citoyens vers les services administratifs appropriés, en fonction de leurs besoins et des procédures en vigueur ?

1.3 Objectifs Objectif général : concevoir et réaliser une plateforme web et mobile qui identifie automatiquement le service administratif adapté à la demande du citoyen, centralise l'information sur les procédures, réduit les déplacements inutiles et fournit aux responsables des indicateurs de pilotage. Objectifs spécifiques :

- Développer une application web et mobile d'orientation des citoyens.  
- Concevoir une base de connaissances des services, procédures et pièces requises.  
- Développer un moteur d'orientation (assistant conversationnel) mesuré et améliorable.  
- Intégrer un système de prise de rendez-vous en ligne avec gestion des créneaux.  
- Mettre en place des notifications (e-mail, SMS, application) et des rappels.  
- Assurer la sécurité et la protection des données personnelles des utilisateurs.  
- Fournir un tableau de bord statistique aux responsables administratifs.

1.4 État de l'existant Plusieurs initiatives publiques répondent déjà en partie au besoin d'information administrative. Elles constituent la base de l'état de l'art du mémoire et une source de contenu pour la base de connaissances.

Initiative | Ce qu'elle propose (d'après les sources consultées) | Apport du présent projet Torolalana (portail d'information sur les démarches) | Fiches de procédures classées par thèmes (citoyenneté, état civil, justice, transport, foncier, fiscalité, entreprises, éducation, santé, etc.) avec pièces requises, étapes et parfois frais. | Orientation par question libre plutôt que navigation par catégories ; rendez-vous ; suivi ; statistiques. Ivotoro (Centre d'informations administratives et d'orientation) | Centre de télé-conseil créé en novembre 2020 (numéro court 640, jours ouvrés 8h-17h) et site web de recherche de démarches ; informe, oriente et recueille les réclamations. | Orientation automatisée disponible en continu, allègement de la charge des conseillers, données sur les demandes. Portail unique des services publics (Unité de Gouvernance Digitale) | Environnement numérique regroupant les services de l'administration publique et l'information sur les démarches de la vie du citoyen. | Complémentarité : le projet peut s'y greffer ou s'en inspirer. Réforme de l'état civil et de l'identité (PRODIGY) | Modernisation de l'état civil et mise en place d'un identifiant unique de la population. | Hors périmètre technique, mais définit le contexte d'évolution (identifiant unique, interopérabilité). Guichet unique de l'EDBM | Centralise les formalités de création de société ; pour Analamanga, dépôt sur rendez-vous après validation en ligne. | Exemple de service à intégrer et de logique de rendez-vous.

Les fonctions de réservation de rendez-vous par service, de notifications de rappel et de tableau de bord statistique n'ont pas été identifiées dans les sources consultées. Cette observation est à confirmer lors de l'état de l'art.

2. Périmètre du projet 2.1 Inclus dans le périmètre  
- Portail web responsive (public, citoyen, agent, responsable, administrateur) et application mobile ou PWA.  
- Base de connaissances des services : au moins 10 services prioritaires documentés (chapitre 4).  
- Assistant d'orientation en français, avec score de confiance et gestion des cas incertains.  
- Prise de rendez-vous, gestion des créneaux, notifications par e-mail, SMS (si disponible) et dans l'application.  
- Suivi des demandes, avis des citoyens, tableau de bord et rapports exportables.  
- Documentation, tests, manuel utilisateur et mémoire.

2.2 Hors périmètre

- Délivrance de documents administratifs officiels ou de signatures électroniques ayant valeur légale.  
- Paiement en ligne des frais et timbres.  
- Interconnexion avec les systèmes informatiques des administrations (registres d'état civil, applications judiciaires, etc.).  
- Reconnaissance biométrique ou vérification d'identité officielle.  
- Traitement du contenu des dossiers des citoyens (le système oriente et informe, il ne traite pas le dossier).

Le système fournit une information indicative. La décision administrative reste de la compétence exclusive du service concerné.

3. Acteurs et rôles Acteur | Description | Droits principaux Visiteur | Utilisateur non connecté | Consulter le catalogue et les procédures ; essayer l'assistant sans historique. Citoyen | Utilisateur inscrit | Exprimer un besoin, être orienté, consulter pièces et procédures, prendre/modifier/annuler un rendez-vous, recevoir des notifications, suivre ses demandes, donner un avis. Agent administratif | Personnel rattaché à un service | Gérer les informations de son service, traiter et réorienter les demandes, gérer créneaux et rendez-vous, communiquer avec les citoyens. Responsable des services publics | Décideur | Consulter statistiques, affluence, satisfaction, performance de l'orientation ; exporter des rapports. Administrateur système | Responsable technique et fonctionnel | Gérer comptes et rôles, base de connaissances, moteur d'orientation, sécurité, paramètres et sauvegardes.  
     
4. Catalogue des services administratifs à couvrir Cette section répond à la question « quels services le site doit-il proposer ? ». Elle s'appuie sur les rubriques du portail Torolalana et sur les fiches de procédures publiées. Les frais, délais et pièces indiqués sont indicatifs : ils proviennent de sources publiques et doivent être revalidés auprès de chaque administration avant toute mise en service.

4.1 Rubriques du catalogue Le catalogue est organisé selon les 16 rubriques publiées par Torolalana : Citoyenneté, État civil, Habitat et foncier, Urgences, Sécurité et justice, Découverte de Madagascar, Études supérieures, Santé, Entreprises et industries, Fiscalité, Fonction publique, Conduite et transport, Tourisme et voyage, Doléances, Éducation, Agriculture et élevage.

4.2 Services prioritaires (première version) Rubrique | Service | Organisme / lieu | Pièces et informations indicatives État civil | Acte de naissance (copie intégrale ou extrait) | Mairie / arrondissement du lieu de naissance | Livret de famille, certificat de résidence ou ancienne copie. Toute naissance est déclarée à la commune dans les 30 jours. État civil | Acte de décès | Commune | À documenter à partir de la fiche Torolalana. Citoyenneté | Carte nationale d'identité (CIN) : première demande, duplicata, renouvellement | Bureau compétent (commune, district ou tribunal selon le cas) | Copie ou bulletin de naissance de moins de 3 mois, certificat de résidence avec photo de moins d'un mois ; duplicata : photocopie de la CIN perdue, fiche mère si la première CIN a été faite hors de Tana I. Validité indiquée : 20 ans. Citoyenneté | Certificats délivrés par le fokontany (dont certificat de résidence) | Fokontany | Livret de famille ou bulletin de naissance ; coût indicatif de 200 à 1 000 Ar. Sécurité et justice | Casier judiciaire (bulletin n°3) | Greffe du tribunal (bureau État civil du tribunal) | Demande, copie certifiée de la CIN ou du passeport, acte de naissance ; frais indicatifs 2 000 Ar ; délai indicatif environ 24 h ; validité indicative 3 mois. Tourisme et voyage | Demande de passeport | Autorité compétente | Formulaire, copie intégrale d'acte de naissance de moins de 6 mois, 4 photos, CIN certifiée, certificat de résidence, situation vis-à-vis du service national (hommes 18-50 ans), motif du voyage, attestation de travail ou de scolarité, vignette indicative de 190 000 Ar ; autorisations parentales pour les mineurs. Conduite et transport | Permis de conduire ; certificat d'authenticité ; visite médicale ; immatriculation ; mutation de véhicule | Services des transports | À documenter à partir des fiches Torolalana. Fiscalité | Numéro d'identification fiscale (NIF) / carte d'identification fiscale | Direction générale des impôts, centre fiscal | Pièces à documenter ; pour une société, la demande passe par le guichet unique de l'EDBM. Entreprises et industries | Créer une entreprise individuelle ou une société | Centre fiscal, INSTAT, tribunal (RCS) ; guichet unique EDBM pour les sociétés | Entreprise individuelle : déclaration d'activité au centre fiscal, inscription à l'INSTAT (droit indicatif 20 000 Ar), RCS. Société : dossier validé en ligne puis dépôt sur rendez-vous à l'EDBM (Analamanga). Habitat et foncier | Acheter un terrain auprès d'un particulier ; transformer un cadastre en titre ; acte de partage | Services fonciers (guichet unique du lieu) | À documenter à partir des fiches Torolalana et Ivotoro.

4.3 Services de la deuxième phase

- Éducation et études supérieures : résultats du baccalauréat, création d'un établissement d'enseignement, inscriptions et concours.  
- Fonction publique : concours et recrutements.  
- Santé, agriculture et élevage, urgences (numéros et lieux utiles).  
- Doléances : mécanisme de gestion des plaintes (par exemple Koragna en état civil).  
- Découverte de Madagascar : parcs nationaux, régions et districts (information générale).

4.4 Modèle de fiche à renseigner pour chaque service Champ | Description Intitulé et rubrique | Nom du service, catégorie, synonymes et mots-clés (dont formulations populaires) Organisme compétent | Administration, bureau, adresse, coordonnées, horaires, jours de rendez-vous Procédure | Étapes ordonnées, ordre imposé entre démarches (par exemple certificat de résidence avant CIN) Pièces requises | Libellé, obligatoire ou facultatif, nombre d'exemplaires, ancienneté maximale, où l'obtenir Délai, frais, validité | Valeurs indicatives avec la date de vérification Source | Lien vers la source officielle, date de dernière mise à jour, responsable de la mise à jour Exemples de demandes | Au moins 30 formulations citoyennes servant à entraîner et évaluer le moteur

5. Besoins fonctionnels Priorités selon la méthode MoSCoW : M \= indispensable (Must), S \= important (Should), C \= souhaitable (Could). Chaque exigence porte un identifiant unique, repris dans les tests et le mémoire.

5.1 Authentification et comptes ID | Exigence | Prio. | Acteur EF-01 | Inscription d'un citoyen (nom, e-mail ou téléphone, mot de passe) avec validation des champs. | M | Citoyen EF-02 | Connexion et déconnexion sécurisées ; session à durée limitée. | M | Tous EF-03 | Récupération du mot de passe par e-mail ou SMS avec lien ou code à durée limitée. | M | Tous EF-04 | Gestion des rôles (citoyen, agent, responsable, administrateur) et contrôle d'accès sur chaque page et chaque point d'accès de l'API. | M | Tous EF-05 | Consultation et modification du profil et des préférences (canal de notification, langue). | S | Citoyen EF-06 | Utilisation de l'assistant sans compte (mode visiteur), sans conservation d'historique. | S | Visiteur

5.2 Catalogue des services et procédures ID | Exigence | Prio. | Acteur EF-07 | Catalogue classé par rubrique avec recherche et filtres. | M | Tous EF-08 | Fiche service : missions, adresse, horaires, contacts, procédures liées. | M | Tous EF-09 | Fiche procédure : étapes ordonnées, pièces requises (obligatoires ou non), délai, frais indicatifs, service compétent, date de mise à jour et source. | M | Tous EF-10 | Liste de contrôle des pièces, cochable et imprimable ou exportable en PDF. | S | Citoyen EF-11 | Création, modification, suppression logique des services, procédures et pièces par les agents (leur service) et l'administrateur (tous), avec historique des versions. | M | Agent, Admin EF-12 | Recherche par mot-clé tolérante aux fautes de frappe. | S | Tous

5.3 Moteur d'orientation et assistant ID | Exigence | Prio. | Acteur EF-13 | Saisie de la demande en texte libre en français ; prévoir l'ajout du malagasy (phase ultérieure). | M | Tous EF-14 | Analyse de la demande et recommandation du service compétent avec score de confiance. | M | Tous EF-15 | Propositions alternatives (les 3 meilleures) lorsque la confiance est moyenne. | S | Tous EF-16 | Question de clarification ou message de repli (renvoi vers le catalogue ou le contact) lorsque la confiance est inférieure au seuil. | M | Tous EF-17 | Affichage du résultat : service, procédure, pièces, délai, frais, adresse et bouton de prise de rendez-vous. | M | Tous EF-18 | Retour du citoyen sur la pertinence (« C'était le bon service ? »). | M | Citoyen EF-19 | Réorientation d'une demande par l'agent ; le service final est enregistré comme exemple d'apprentissage. | M | Agent EF-20 | Journalisation de chaque demande : texte, service recommandé, confiance, service final, date. | M | Système EF-21 | Mise à jour du moteur à partir des exemples validés, déclenchée par l'administrateur. | S | Admin EF-22 | Anonymisation des textes de demande avant leur réutilisation pour l'apprentissage. | S | Système

5.4 Rendez-vous ID | Exigence | Prio. | Acteur EF-23 | Définition des créneaux par service : durée, capacité, jours fermés. | M | Agent EF-24 | Calendrier de disponibilité par service. | M | Citoyen EF-25 | Prise de rendez-vous en ligne avec motif et vérification de capacité en temps réel (aucune double réservation). | M | Citoyen EF-26 | Modification et annulation par le citoyen jusqu'à un délai fixé avant le rendez-vous. | M | Citoyen EF-27 | Gestion par l'agent : confirmer, reporter, marquer présent ou absent. | S | Agent EF-28 | Récapitulatif de confirmation : date, lieu, pièces à apporter. | M | Citoyen EF-29 | Blocage de créneaux par l'agent (absence, fermeture exceptionnelle). | S | Agent

5.5 Notifications ID | Exigence | Prio. | Acteur EF-30 | E-mail de confirmation de rendez-vous. | M | Système EF-31 | Rappel automatique avant le rendez-vous (par exemple la veille). | M | Système EF-32 | Alerte de pièces manquantes ou de changement de procédure. | S | Système EF-33 | Notifications par SMS (via passerelle ou simulateur pendant le développement). | S | Système EF-34 | Centre de notifications dans l'application avec statut lu ou non lu. | S | Citoyen EF-35 | Notifications push sur mobile. | C | Système

5.6 Suivi des demandes et avis ID | Exigence | Prio. | Acteur EF-36 | Historique des demandes avec statut : soumise, orientée, rendez-vous pris, traitée, clôturée, annulée. | M | Citoyen EF-37 | Détail chronologique d'une demande. | S | Citoyen, Agent EF-38 | Note et commentaire après le rendez-vous. | S | Citoyen

5.7 Tableau de bord statistique ID | Exigence | Prio. | Acteur EF-39 | Statistiques des demandes par service, période et type. | M | Responsable EF-40 | Analyse de l'affluence par jour et par heure. | S | Responsable EF-41 | Indicateurs de satisfaction (notes, évolution). | S | Responsable EF-42 | Indicateurs de performance de l'orientation : taux de bonne orientation, réorientations, demandes non comprises. | M | Responsable, Admin EF-43 | Export des rapports en PDF, Excel ou CSV. | S | Responsable

5.8 Administration et mobile ID | Exigence | Prio. | Acteur EF-44 | Gestion des utilisateurs : création, désactivation, rôles, rattachement à un service. | M | Admin EF-45 | Journal d'audit : connexions, actions sensibles, tentatives échouées. | S | Admin EF-46 | Paramétrage : modèles de messages, jours fériés, durée par défaut des créneaux. | C | Admin EF-47 | Sauvegarde et restauration des données. | S | Admin EF-48 | Application mobile ou PWA : assistant, catalogue, rendez-vous, notifications, suivi. | M | Citoyen EF-49 | Consultation hors connexion des fiches déjà chargées. | C | Citoyen

6. Besoins non fonctionnels ID | Domaine | Exigence ENF-01 | Performance | Réponse de l'assistant en 3 secondes ou moins pour 95 % des requêtes ; pages principales chargées en 3 secondes ou moins sur une connexion 3G (cibles à valider par des mesures). ENF-02 | Capacité | Au moins 100 utilisateurs simultanés lors de la démonstration (à valider par un test de charge). ENF-03 | Disponibilité | Disponibilité cible de 99 % pendant la période d'évaluation. ENF-04 | Sécurité | HTTPS ; mots de passe hachés (bcrypt ou argon2) ; jetons d'authentification à durée limitée ; contrôle d'accès par rôle ; protection contre les injections SQL, XSS et CSRF ; limitation des tentatives de connexion. ENF-05 | Protection des données | Conformité à la loi malgache n°2014-038 sur la protection des données à caractère personnel : collecte limitée aux besoins, finalité annoncée, consentement, droit d'accès et d'opposition, suppression du compte sur demande. Aucune collecte de numéro de CIN ou d'autre donnée sensible non nécessaire. ENF-06 | Ergonomie | Vocabulaire courant ; parcours principal en 5 étapes ou moins ; interface responsive. ENF-07 | Accessibilité | Contrastes conformes à WCAG 2.1 AA, navigation au clavier, textes alternatifs. ENF-08 | Langues | Français obligatoire ; textes externalisés pour permettre l'ajout du malagasy. ENF-09 | Faible connectivité | Pages légères, mise en cache, SMS comme canal de repli, PWA pour usage mobile. ENF-10 | Compatibilité | Navigateurs récents (Chrome, Firefox, Edge, Safari) ; Android 8 et versions plus récentes ; iOS récent. ENF-11 | Maintenabilité | Code modulaire versionné sous Git, commenté, avec tests automatisés sur les parties critiques. ENF-12 | Évolutivité | Séparation front, API et moteur d'orientation ; ajout d'un service ou d'une procédure sans modification du code. ENF-13 | Fiabilité de l'information | Chaque procédure affiche sa date de mise à jour et sa source ; mention que l'information est indicative. ENF-14 | Traçabilité | Journalisation des actions sensibles et des décisions d'orientation. ENF-15 | Sauvegarde | Sauvegarde quotidienne de la base et restauration testée au moins une fois.  
     
7. Exigences du moteur d'orientation Le moteur est le composant central et innovant du projet. Ses exigences sont chiffrées afin de pouvoir démontrer sa performance. Les valeurs ci-dessous sont des cibles proposées, à valider avec l'encadrant. Sujet | Exigence Couverture | Au moins 10 services prioritaires reconnus (chapitre 4.2). Données | Au moins 30 exemples de demandes par service pour l'entraînement ; jeu de test distinct d'au moins 200 demandes, constitué avant l'entraînement et jamais utilisé pour l'ajuster. Approche | Démarrer par une base simple mesurée (par exemple TF-IDF avec classifieur), puis comparer avec une approche sémantique (représentations vectorielles) ; toute approche générative doit s'appuyer sur la base de connaissances et ne pas inventer de procédure. Précision cible | Service correct en première proposition : 80 % ou plus ; service correct parmi les trois premières propositions : 95 % ou plus. Gestion de l'incertitude | Seuil de confiance paramétrable ; en dessous, question de clarification ou message de repli (EF-16). Robustesse | Gestion des fautes de frappe, abréviations, expressions populaires et demandes hors sujet. Évaluation | Métriques publiées dans le mémoire : précision, rappel, F1, matrice de confusion, analyse des erreurs. Amélioration continue | Réorientations des agents et retours citoyens enrichissent le jeu d'exemples après anonymisation (EF-19, EF-22). Transparence | Chaque réponse indique que la recommandation est automatique et que le service concerné reste compétent pour trancher.  
     
8. Architecture et choix techniques proposés Couche | Proposition | Justification Web | React ou Vue, CSS utilitaire | Interfaces par rôle, composants réutilisables Mobile | PWA, ou Flutter / React Native | PWA : un seul code, installation simple ; natif si le temps le permet API | Python (Flask ou FastAPI) ou Node.js ; REST et JWT | Documentation OpenAPI automatique Moteur d'orientation | Python, scikit-learn puis représentations sémantiques | Service séparé, testable et remplaçable Base de données | PostgreSQL ou MySQL | Intégrité relationnelle, migrations versionnées Notifications | E-mail SMTP ; SMS par passerelle ou simulateur ; tâches planifiées | Rappels automatiques sans intervention Déploiement | Docker, Git, intégration continue simple | Reproductibilité et démonstration stable

Ces choix sont proposés à titre indicatif et pourront être ajustés selon les compétences de l'équipe, à condition de conserver la séparation front, API et moteur.

9. Données principales Entité | Attributs clés Utilisateur | identifiant, nom, e-mail, téléphone, mot de passe haché, rôle, service (agents), langue, statut Service | identifiant, nom, rubrique, missions, adresse, horaires, contact, actif Procédure et étapes | identifiant, service, titre, description, délai, frais indicatifs, source, date de vérification, version ; étapes ordonnées Pièce requise | identifiant, procédure, libellé, obligatoire, ancienneté maximale, remarque Demande | identifiant, citoyen (facultatif), texte, service recommandé, confiance, service final, statut, date Exemple d'orientation | identifiant, texte anonymisé, service, origine (manuel ou retour), date Créneau et rendez-vous | créneau : service, début, fin, capacité ; rendez-vous : demande, citoyen, créneau, statut Notification | identifiant, utilisateur, type, canal, message, statut d'envoi, date Avis | identifiant, rendez-vous, note, commentaire Journal | identifiant, utilisateur, action, date, adresse IP  
     
10. Contraintes Contrainte de délai : environ 16 semaines, avec une version démontrable dès la semaine 10\. Contrainte de données : aucune donnée personnelle réelle ; jeux de démonstration et informations publiques uniquement. Contrainte de coût : services payants (SMS, hébergement) limités ; recours à des simulateurs ou à des offres gratuites pour la démonstration. Contrainte juridique : respect de la loi n°2014-038 sur les données personnelles et des textes sur la cybercriminalité (loi n°2014-006 modifiée en 2016). Contrainte de contenu : les procédures évoluent ; chaque fiche indique sa date de vérification et sa source. Contrainte technique : usage sur mobiles d'entrée de gamme et connexions lentes.  
      
11. Livrables Livrable | Contenu | Échéance Cahier des charges | Le présent document, validé | Semaine 2 Diagrammes UML | Cas d'utilisation, classes, séquences, activités, états | Semaines 2-5 Base de données | Modèle, scripts, dictionnaire de données, jeu de démonstration | Semaines 3-6 Maquettes | Écrans web et mobile, parcours principal | Semaines 3-5 Moteur d'orientation | Modèle, jeu de test, rapport d'évaluation | Semaines 5-11 Code source | Dépôt Git structuré avec README | Semaines 5-14 Application web et mobile | Portail par rôle, application ou PWA | Semaines 6-14 Documentation API | OpenAPI et collection de tests | Au fil du développement Documentation technique | Architecture, installation, sécurité, tests | Semaines 13-15 Manuel utilisateur | Guide illustré par rôle | Semaines 14-15 Rapport de mémoire | Mémoire complet | Semaines 14-16  
      
12. Planning et jalons Phase | Semaines | Objectif | Jalon de sortie Cadrage | 1 | Équipe, outils, dépôt Git, règles | Backlog créé Analyse | 1-2 | Besoins, priorités | Cahier des charges validé Conception | 2-5 | UML, base, maquettes, architecture | Dossier de conception validé Base de connaissances | 4-6 | Services, procédures, exemples de demandes | Jeu de données v1 et jeu de test figé Noyau (MVP) | 5-10 | Comptes, catalogue, moteur v1, chatbot, rendez-vous, e-mail | Parcours principal démontrable Extensions | 10-13 | Espace agent, tableau de bord, SMS, avis, mobile | Version complète Tests et sécurité | 12-14 | Tests, évaluation du moteur, corrections | Rapport de tests Documentation | 13-15 | Documentation technique, API, manuel | Documents finaux Mémoire et soutenance | 14-16 | Rédaction et répétitions | Mémoire déposé, démonstration stabilisée  
      
13. Organisation de l'équipe Rôle | Responsabilités | Titulaire Chef de projet et analyste | Cahier des charges, planning, suivi, mémoire | \[à compléter\] Développeur back-end | API, base de données, sécurité, notifications | \[à compléter\] Développeur front-end et UX | Maquettes, interface web, accessibilité | \[à compléter\] Développeur mobile | Application mobile ou PWA | \[à compléter\] Responsable IA et données | Base de connaissances, jeu d'exemples, moteur, évaluation | \[à compléter\]

Réunion hebdomadaire de 30 minutes ; sprints de 2 semaines avec démonstration interne. Revue de code avant chaque fusion ; tableau de tâches partagé ; journal de bord du projet.

14. Recette et critères d'acceptation Le projet est accepté lorsque les exigences de priorité M sont satisfaites et que les scénarios suivants s'exécutent de bout en bout devant l'encadrant. Scénario | Description | Critère de réussite S1 | Un visiteur décrit un besoin et obtient une orientation | Service correct, pièces et procédure affichées, score de confiance visible S2 | Demande ambiguë ou hors sujet | Question de clarification ou message de repli, sans réponse inventée S3 | Inscription, connexion, récupération du mot de passe | Comptes créés et rôles appliqués ; accès refusé hors rôle S4 | Prise, modification et annulation d'un rendez-vous | Aucune double réservation ; e-mails de confirmation et de rappel reçus S5 | Un agent réoriente une demande | Service final enregistré et pris en compte dans les statistiques S6 | Un agent modifie une procédure | Nouvelle version visible côté citoyen avec sa date de mise à jour S7 | Un responsable consulte et exporte un rapport | Chiffres cohérents avec les données ; fichier exporté lisible S8 | Évaluation du moteur sur le jeu de test | Cibles du chapitre 7 atteintes ou écart expliqué dans le mémoire S9 | Test de sécurité de base | Aucun accès non autorisé ; entrées invalides rejetées ; mots de passe hachés  
      
15. Risques Risque | Impact | Parade Périmètre trop large | Retard, projet inachevé | Priorisation MoSCoW, gel des nouveautés après la semaine 10 Données insuffisantes pour le moteur | Moteur peu fiable | Réduire le nombre de services, enrichir les exemples, comparer plusieurs approches Informations administratives périmées ou incohérentes | Information erronée | Source et date sur chaque fiche, avertissement d'information indicative, revalidation SMS payants ou indisponibles | Notification incomplète | Simulateur, e-mail comme canal principal Intégration tardive des composants | Retards | Contrat d'API écrit tôt, données simulées, intégration continue Travail inégal dans l'équipe | Qualité variable | Tâches nominatives, historique Git, rôles clairs Panne pendant la soutenance | Démonstration impossible | Environnement de secours, données réinitialisables, vidéo de secours  
      
16. Points à valider avec l'encadrant Administration cible : le contexte malgache a été retenu ; confirmer ce choix. Mobile : application native ou PWA suffisante. SMS : passerelle réelle ou simulateur ; budget disponible. Langues : français seul en première version, ou français et malagasy. Nombre de services à documenter et niveau de détail exigé pour la base de connaissances. Cibles chiffrées du moteur (chapitre 7\) et taille du jeu de test. Possibilité de rencontrer un agent ou un usager pour valider les procédures et le parcours.  
      
17. Références Sources consultées le 21 septembre 2026\. Les informations de frais, délais et pièces sont à revalider auprès des administrations.  
      
- Torolalana, portail des démarches administratives (rubriques, fiches acte de naissance, CIN, passeport, permis de conduire, création de société).  
- Ivotoro, Centre d'informations administratives et d'orientation (missions, fiche bulletin n°3).  
- Unité de Gouvernance Digitale, Portail unique des services publics  
- Projet PRODIGY (état civil et identité)  
- Loi n°2014-038 du 9 janvier 2015 sur la protection des données à caractère personnel  
- EDBM, guichet unique de création d'entreprise  
- We Are Tech, numérisation du casier judiciaire (juin 2026\)

Annexe A \- Glossaire Terme | Définition CIN | Carte d'identité nationale Fokontany | Plus petite division administrative, qui délivre notamment le certificat de résidence NIF | Numéro d'identification fiscale EDBM | Economic Development Board of Madagascar, qui gère le guichet unique de création d'entreprise MVP | Version minimale démontrable du produit MoSCoW | Méthode de priorisation : Must, Should, Could, Won't PWA | Application web installable sur mobile API | Interface de programmation utilisée par le web et le mobile JWT | Jeton d'authentification signé à durée limitée RAG | Génération appuyée sur la recherche dans une base de connaissances

Annexe B \- Validation Rôle | Nom | Date | Signature Chef de projet | | | Encadrant | | | Responsable de filière | | |