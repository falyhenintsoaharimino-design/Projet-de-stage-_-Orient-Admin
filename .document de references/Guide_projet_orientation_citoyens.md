

Guide complet du projet
Système intelligent d'orientation des
citoyens vers les services
administratifs
Livrables expliqués, structure complète du site, maquette et plan de réalisation
Projet de licence - Document de cadrage et d'accompagnement

Système intelligent d'orientation des citoyens - Guide du projetPage 2
## Sommaire
-1. Ce que le projet attend réellement
-2. Les livrables expliqués un par un
-3. Structure complète du site (plan du site, pages, contenu)
-4. Application mobile et assistant intelligent
-5. Architecture technique et modèle de données proposés
## -6. Maquette Figma (prototype)
-7. Plan détaillé pour mener le projet à bien
-8. Risques, critères d'évaluation et conseils pour la soutenance

Système intelligent d'orientation des citoyens - Guide du projetPage 3
- Ce que le projet attend réellement
Le sujet demande de concevoir et de réaliser une plateforme qui guide un citoyen vers le bon service
administratif à partir de sa demande exprimée en langage naturel, puis qui l'accompagne : documents à
fournir, étapes de la procédure, prise de rendez-vous, notifications et suivi. Côté administration, elle fournit
un tableau de bord statistique.
1.1 Les 5 piliers du projet
PilierCe qu'on attend concrètement
Orientation intelligenteUn moteur qui comprend la demande (ex. : « je veux un acte de naissance ») et
recommande le service compétent, avec un niveau de confiance.
Base de connaissancesUn catalogue centralisé des services, missions, procédures, documents requis, horaires,
lieux et frais.
AccompagnementChatbot de guidance, liste des pièces à fournir, étapes claires, alertes sur les documents
manquants.
Rendez-vous et suiviCalendrier de disponibilité, créneaux, confirmation, rappels e-mail et SMS, historique des
demandes.
PilotageTableau de bord : demandes par service, affluence, satisfaction, rapports exportables.
1.2 Ce que les évaluateurs regardent généralement
-La cohérence entre problématique, objectifs, fonctionnalités et résultats (tout ce qui est annoncé doit
être démontrable).
-La partie intelligente : un moteur d'orientation réellement fonctionnel, avec une mesure de
performance (précision, taux de bonne orientation).
-La conception : diagrammes UML corrects, modèle de données propre, choix techniques justifiés.
-La réalisation : application web et mobile utilisable, rôles gérés, sécurité de base (mots de passe
hachés, contrôle d'accès, validation des entrées).
-La documentation : cahier des charges, documentation technique et API, manuel utilisateur, mémoire
rédigé.
-La soutenance : démonstration fluide d'un scénario complet, du besoin exprimé jusqu'au rendez-vous
confirmé.
Astuce : le sujet liste beaucoup de fonctionnalités. Il vaut mieux un noyau complet et solide (orientation
+ rendez-vous + notifications e-mail + tableau de bord) qu'un projet trop large et inachevé. Le plan de la
section 7 le priorise (MVP d'abord, extensions ensuite).

Système intelligent d'orientation des citoyens - Guide du projetPage 4
- Les livrables expliqués un par un
Le sujet énumère dix livrables. Pour chacun : à quoi il sert, ce qu'il doit contenir et comment le réussir.
2.1 Cahier des charges
Rôle : Document contractuel qui fixe ce que le système doit faire et dans quelles limites. C'est la référence
pour toute la suite (développement, tests, évaluation).
Contenu attendu :
-Contexte, problématique, objectifs général et spécifiques.
-Périmètre : ce qui est inclus et ce qui est exclu.
-Acteurs et rôles (administrateur, agents, citoyens, responsables).
-Besoins fonctionnels (authentification, orientation, rendez-vous...) et non fonctionnels (sécurité,
performance, disponibilité, ergonomie, évolutivité).
-Contraintes (techniques, délais, budget, langues), planning et livrables.
-Critères d'acceptation : comment on saura que chaque fonctionnalité est réussie.
Conseil : Numérotez chaque exigence (EF-01, EF-02, ENF-01...) pour pouvoir les relier aux tests et au
mémoire.
2.2 Documentation technique
Rôle : Explique comment le système est construit, pour qu'un autre développeur puisse le comprendre,
l'installer et le faire évoluer.
Contenu attendu :
-Architecture globale (schéma des composants : front web, mobile, API, base, moteur IA, services de
notification).
-Technologies choisies et justification.
-Structure du projet (dossiers, modules) et conventions de code.
-Guide d'installation et de déploiement (variables d'environnement, migrations, lancement).
-Stratégie de sécurité (authentification, rôles, chiffrement, sauvegardes).
-Stratégie de tests et résultats.
Conseil : Ajoutez un schéma d'architecture et un « démarrage en 5 minutes » : c'est ce qui est le plus
apprécié.
2.3 Diagrammes UML
Rôle : Modélisation visuelle du système avant et pendant le développement ; montre la rigueur de la
conception.
Contenu attendu :
-Diagramme de cas d'utilisation : acteurs et fonctionnalités (un par rôle, puis global).
-Diagrammes de séquence : au minimum orientation via chatbot, prise de rendez-vous, envoi de
notification.
-Diagramme de classes : Utilisateur, Service, Procédure, Document, Demande, RendezVous,
## Notification, Avis...
-Diagrammes d'activité : parcours d'une demande citoyenne.
-Diagramme d'états : cycle de vie d'une demande (soumise, orientée, rendez-vous pris, traitée,
clôturée).

Système intelligent d'orientation des citoyens - Guide du projetPage 5
-Diagramme de déploiement (optionnel mais valorisé).
Conseil : Restez cohérent avec la base de données et le code : les évaluateurs comparent les
diagrammes à la réalisation.
2.4 Moteur intelligent d'orientation
Rôle : Le coeur innovant du projet : à partir d'un texte libre, il identifie le service adapté.
Contenu attendu :
-Prétraitement du texte (normalisation, suppression des mots vides, gestion des fautes et de la langue
locale si besoin).
-Modèle de classification/recommandation : approche simple (TF-IDF + classifieur), ou par similarité
sémantique (embeddings), ou LLM avec récupération dans la base de connaissances (RAG).
-Score de confiance et réponse de repli (« Pouvez-vous préciser ? ») quand la confiance est faible.
-Jeu de données d'exemples de demandes par service (à construire vous-mêmes) et jeu de test séparé.
-Métriques : précision, rappel, taux de bonne orientation ; boucle d'amélioration à partir des retours
citoyens.
Conseil : Commencez par une baseline simple et mesurée, puis améliorez. Un moteur simple mais
évalué vaut mieux qu'un moteur complexe non mesuré.
2.5 Code source
Rôle : L'ensemble du logiciel, versionné et exécutable.
Contenu attendu :
-Dépôt Git structuré (front web, mobile, back-end/API, moteur IA, scripts de base de données).
-README avec installation, lancement, comptes de démonstration.
-Historique de commits clair, branches par fonctionnalité.
-Code commenté, tests unitaires sur les parties critiques, fichier d'exemple de configuration (jamais de
mots de passe dans le dépôt).
Conseil : Faites des commits réguliers dès le premier jour : l'historique prouve le travail réel de chaque
membre.
2.6 Base de données
Rôle : Stockage centralisé et cohérent de toutes les informations.
Contenu attendu :
-Modèle conceptuel et logique (MCD/MLD ou diagramme entité-association).
-Script de création (schéma), scripts de migration.
-Jeu de données de démonstration réaliste (services, procédures, documents, créneaux).
-Contraintes d'intégrité, index utiles, stratégie de sauvegarde.
-Dictionnaire de données (tables, champs, types, rôles).
Conseil : Prévoyez dès le départ les tables de journalisation des demandes : elles alimentent le tableau
de bord et l'amélioration du moteur.
2.7 Documentation API
Rôle : Décrit les points d'accès (endpoints) utilisés par le web, le mobile et éventuellement des tiers.
Contenu attendu :

Système intelligent d'orientation des citoyens - Guide du projetPage 6
-Liste des endpoints par domaine : auth, services, procédures, orientation, rendez-vous, notifications,
statistiques.
-Pour chacun : méthode, URL, paramètres, exemple de requête et de réponse, codes d'erreur, droits
requis.
-Authentification (jetons JWT), pagination, format des erreurs.
-Documentation interactive générée (Swagger/OpenAPI) et collection de tests (Postman).
Conseil : Générez la documentation automatiquement depuis le code (OpenAPI) pour qu'elle reste
toujours à jour.
2.8 Application web et mobile
Rôle : Les interfaces réellement utilisées par les citoyens, agents et responsables.
Contenu attendu :
-Web : portail citoyen, espace agent, espace responsable, espace administrateur (voir section 3).
-Mobile : au minimum l'assistant, le catalogue, les rendez-vous, les notifications et le suivi ; peut être
une application native/multiplateforme ou une PWA responsive.
-Interface cohérente, accessible, en français (et langue locale si possible), utilisable sur petit écran et
connexion lente.
-Gestion des états vides, erreurs et chargements.
Conseil : Si le temps manque, une PWA installable qui réutilise le web est une réponse acceptable pour
le « mobile » ; précisez ce choix dans le mémoire.
2.9 Manuel utilisateur
Rôle : Guide d'usage pour des personnes non techniques.
Contenu attendu :
-Un chapitre par profil : citoyen, agent, responsable, administrateur.
-Procédures illustrées par des captures d'écran : créer un compte, poser sa question, prendre
rendez-vous, consulter ses notifications, mettre à jour une procédure, exporter un rapport.
-FAQ et résolution des problèmes courants.
-Glossaire.
Conseil : Rédigez-le en fin de développement avec l'application stable, en suivant exactement les
parcours de la démonstration.
2.10 Rapport de mémoire
Rôle : Document académique qui raconte et justifie tout le projet ; il est noté avec la soutenance.
Contenu attendu :
-Introduction, contexte, problématique, objectifs.
-État de l'art : systèmes de recommandation, chatbots, e-administration, solutions existantes.
-Analyse des besoins et conception (cahier des charges, UML, base de données).
-Réalisation : choix techniques, moteur d'orientation, captures, extraits de code significatifs.
-Tests et résultats : métriques du moteur, tests fonctionnels, retours d'utilisateurs.
-Conclusion, limites, perspectives ; bibliographie ; annexes.
Conseil : Tenez un journal de bord dès le début : vous en tirerez 60 % du mémoire sans effort à la fin.

Système intelligent d'orientation des citoyens - Guide du projetPage 7
Récapitulatif des livrables
LivrableQuand le produireFormeLié à
Cahier des chargesSemaines 1-2DocumentTous les autres
Diagrammes UMLSemaines 2-4Fichiers et imagesCode, base, mémoire
Base de donnéesSemaines 3-5Scripts et jeu de démoAPI, moteur, dashboard
Code sourceSemaines 4-14Dépôt GitTout
Moteur d'orientationSemaines 5-10Module + évaluationChatbot, mémoire
Documentation APIAu fil du développementOpenAPI/SwaggerWeb, mobile
Application web et mobileSemaines 6-14ApplicationsManuel
Documentation techniqueSemaines 12-15DocumentSoutenance
Manuel utilisateurSemaines 14-15Document illustréDémonstration
Rapport de mémoireRédaction continue,
finalisation semaines 14-16
DocumentSoutenance

Système intelligent d'orientation des citoyens - Guide du projetPage 8
- Structure complète du site
Le site est un portail unique avec des espaces selon le rôle. Voici l'arborescence complète, puis le détail
de chaque page : ce qu'elle contient et ce qu'elle propose.
3.1 Plan du site (arborescence)
EspacePages
Public (visiteur)Accueil - Assistant d'orientation (essai) - Catalogue des services - Fiche service - Fiche
procédure - Comment ça marche - FAQ - Contact - Connexion - Inscription - Mot de passe
oublié - Mentions légales et confidentialité
CitoyenTableau de bord personnel - Assistant (chat) - Mes demandes - Détail d'une demande -
Prendre rendez-vous - Mes rendez-vous - Notifications - Mon profil et préférences - Donner un
avis
Agent administratifTableau de bord du service - Demandes orientées - Détail d'une demande - Gestion des
rendez-vous et calendrier - Gestion des créneaux - Fiche service (procédures, documents) -
Messages/notifications aux citoyens
Responsable des
services
Tableau de bord statistique - Statistiques par service - Analyse d'affluence - Satisfaction -
Performance de l'orientation - Rapports exportables
Administrateur systèmeGestion des utilisateurs et rôles - Gestion des services (catalogue) - Base de connaissances -
Entraînement et suivi du moteur - Journal d'activité et sécurité - Paramètres (e-mail, SMS,
créneaux) - Rapports globaux - Sauvegardes
3.2 Pages publiques
PageContenuCe qu'elle propose
AccueilBandeau d'accroche, barre « Que
souhaitez-vous faire ? », services les plus
demandés, comment ça marche en 3 étapes,
chiffres clés, pied de page.
Poser sa question immédiatement, sans
compte ; accès rapide aux services fréquents
(état civil, fiscalité, urbanisme, affaires
sociales).
## Assistant
d'orientation
Fenêtre de discussion, suggestions de
questions, résultat d'orientation.
Recommandation du service, niveau de
confiance, documents requis, bouton «
Prendre rendez-vous ».
Catalogue des
services
Liste filtrable et recherchable par catégorie, lieu,
mot-clé.
Découvrir les missions de chaque service et
ses coordonnées.
Fiche serviceMissions, adresse, horaires, contacts,
procédures liées, prochaines disponibilités.
Aller directement de l'information au
rendez-vous.
Fiche procédureÉtapes numérotées, documents requis (cases à
cocher), délais, frais, service compétent.
Préparer son dossier ; imprimer/enregistrer la
liste de contrôle.
FAQ, ContactQuestions fréquentes par thème ; formulaire de
contact.
Réponses rapides et désengorgement des
guichets.
## Connexion,
Inscription, Mot de
passe oublié
Formulaires avec validation, choix du canal de
notification.
Compte sécurisé ; récupération par e-mail.
3.3 Espace citoyen

Système intelligent d'orientation des citoyens - Guide du projetPage 9
PageContenuCe qu'elle propose
Tableau de bordProchain rendez-vous, demandes en cours,
alertes de documents manquants, raccourci
vers l'assistant.
Vue d'ensemble et prochaines actions.
Assistant (chat)Historique de conversation, réponses guidées,
boutons de suggestion.
Orientation personnalisée et enregistrement
de la demande.
Mes demandesListe avec statut (soumise, orientée,
rendez-vous pris, en traitement, clôturée), filtres.
Suivi de l'avancement et historique.
Détail d'une
demande
Chronologie, service concerné, documents
fournis/manquants, messages.
Transparence sur l'état du dossier ;
téléversement éventuel de pièces.
## Prendre
rendez-vous
Choix du service, calendrier, créneaux libres,
motif, récapitulatif.
Réservation en ligne, confirmation immédiate,
ajout au calendrier.
Mes rendez-vousÀ venir / passés, modifier, annuler, itinéraire.Gestion autonome des rendez-vous.
NotificationsCentre de notifications (confirmation, rappels,
documents manquants).
Rester informé par e-mail, SMS et dans
l'application.
Profil et préférencesInformations personnelles, langue, canaux de
notification, mot de passe.
Contrôle de ses données.
AvisNote et commentaire après le rendez-vous.Retour d'expérience alimentant les indicateurs
de satisfaction.
3.4 Espace agent administratif
PageContenuCe qu'elle propose
Tableau de bord du
service
Rendez-vous du jour, nouvelles demandes
orientées, indicateurs du service.
Organiser sa journée.
Demandes orientéesListe triable avec priorité et statut.Traiter, réorienter (si erreur du moteur :
donnée précieuse pour l'amélioration),
clôturer.
Rendez-vous et
créneaux
Calendrier, définition des disponibilités,
blocage de plages, capacité par créneau.
Piloter l'affluence.
Fiche service et
procédures
Édition des missions, horaires, procédures,
documents requis.
Garder la base de connaissances à jour sans
passer par l'administrateur.
CommunicationEnvoi de rappels et messages ciblés.Alerter en cas de dossier incomplet ou de
changement.
3.5 Espace responsable des services publics
PageContenuCe qu'elle propose
Tableau de bord
statistique
Cartes indicateurs, graphiques par période,
service et type de demande.
Vision globale des besoins des citoyens.
AffluenceHeatmap jours/heures, temps d'attente
estimé.
Adapter les effectifs et les horaires.
SatisfactionNotes moyennes, commentaires, évolution.Mesurer la qualité perçue.
Performance de
l'orientation
Taux de bonne orientation, réorientations,
demandes non comprises.
Évaluer et améliorer le moteur.

Système intelligent d'orientation des citoyens - Guide du projetPage 10
PageContenuCe qu'elle propose
RapportsExport PDF/Excel/CSV, périodes
personnalisées.
Produire des rapports officiels.
3.6 Espace administrateur système
PageContenuCe qu'elle propose
Utilisateurs et rôlesCréation, désactivation, attribution des rôles
et services.
Contrôle des accès.
Catalogue et base de
connaissances
Services, catégories, procédures,
documents, mots-clés, exemples de
demandes.
Alimenter l'orientation.
Moteur d'orientationExemples d'entraînement, réentraînement,
métriques, demandes mal comprises.
Amélioration continue.
Sécurité et journalConnexions, actions sensibles, tentatives
échouées.
Traçabilité et audit.
ParamètresModèles d'e-mails/SMS, jours fériés, durée
des créneaux, sauvegardes.
Configuration sans toucher au code.
3.7 Parcours principal (à démontrer en soutenance)
-Le citoyen ouvre le site et écrit : « Je dois faire légaliser un document ».
-L'assistant identifie le service compétent, affiche la procédure et les pièces à fournir.
-Le citoyen crée un compte (ou se connecte) et choisit un créneau.
-Confirmation par e-mail/SMS ; rappel automatique avant le rendez-vous.
-L'agent voit la demande, la traite et la clôture ; le citoyen donne son avis.
-Le responsable voit la statistique apparaître dans son tableau de bord.

Système intelligent d'orientation des citoyens - Guide du projetPage 11
- Application mobile et assistant intelligent
4.1 Application mobile (citoyen en priorité)
ÉcranContenu
AccueilBarre de question, raccourcis services, prochain rendez-vous.
AssistantConversation, suggestions, résultat d'orientation en carte.
ServicesCatalogue, fiche, procédure avec liste de contrôle.
Rendez-vousCalendrier, créneaux, mes rendez-vous, rappel.
SuiviMes demandes et leur statut.
NotificationsCentre de notifications, alertes de documents manquants.
ProfilCompte, langue, canaux de notification.
4.2 Fonctionnement de l'assistant d'orientation
-Entrée : texte libre du citoyen (voix en option).
-Analyse : prétraitement, détection de l'intention, extraction d'informations (type de document,
situation).
-Recommandation : service le plus probable + alternatives + score de confiance.
-Clarification : si confiance faible, l'assistant pose une question ciblée.
-Réponse : service, procédure, pièces requises, délais, frais, bouton de rendez-vous.
-Apprentissage : le retour (« C'était bien le bon service ? ») et les réorientations des agents
enrichissent le jeu de données.

Système intelligent d'orientation des citoyens - Guide du projetPage 12
- Architecture technique et modèle de données
proposés
## 5.1 Architecture
CouchePropositionRemarque
WebReact (ou Vue) + CSS utilitaireInterface par rôle, responsive
MobileFlutter ou React Native (ou PWA)Réutiliser l'API commune
APIPython (Flask ou FastAPI) ou Node.js, REST + JWTDocumentation OpenAPI automatique
Moteur IAPython : scikit-learn (TF-IDF + classifieur) puis
embeddings ; option LLM avec RAG
Service séparé appelé par l'API
Base de donnéesPostgreSQL (ou MySQL)Migrations versionnées
NotificationsE-mail (SMTP) ; SMS via passerelle/API ; notifications
push mobile
Tâches planifiées pour les rappels
DéploiementDocker, dépôt Git, CI simpleEnvironnement de démonstration
accessible
Ces choix sont des propositions : le principe est de séparer le front, l'API et le moteur d'orientation pour
pouvoir tester et faire évoluer chaque partie.
5.2 Modèle de données (tables principales)
TableChamps clés
utilisateurid, nom, email, téléphone, mot_de_passe_haché, rôle, service_id (agents), langue, statut
serviceid, nom, catégorie, missions, adresse, horaires, contact, actif
procedureid, service_id, titre, description, délai, frais, version
etape_procedureid, procedure_id, ordre, description
document_requisid, procedure_id, libellé, obligatoire, remarque
demandeid, citoyen_id, texte, service_recommandé, confiance, service_final, statut, date
exemple_orientationid, texte, service_id, source (manuel/retour) - jeu d'entraînement
creneauid, service_id, début, fin, capacité
rendez_vousid, demande_id, creneau_id, citoyen_id, statut
notificationid, utilisateur_id, type, canal, message, statut, date
avisid, rendez_vous_id, note, commentaire
journalid, utilisateur_id, action, date, adresse_ip
5.3 Sécurité et données personnelles
-Mots de passe hachés (bcrypt/argon2), jetons JWT à durée limitée, contrôle d'accès par rôle sur
chaque endpoint.
-HTTPS, validation et échappement des entrées, protection contre les injections SQL et le XSS,
limitation du nombre de tentatives.

Système intelligent d'orientation des citoyens - Guide du projetPage 13
-Minimisation des données personnelles, consentement, possibilité de supprimer son compte, journal
d'audit.
-Sauvegardes régulières et procédure de restauration testée.

Système intelligent d'orientation des citoyens - Guide du projetPage 14
## 6. Maquette Figma (prototype)
Une maquette du site et de l'application mobile a été créée dans Figma. Elle contient les écrans clés :
accueil, assistant d'orientation, résultat d'orientation, prise de rendez-vous, tableau de bord du
responsable et écran mobile.
Lien Figma : https://www.figma.com/design/WKWuwmSDV8VSlPVDnSDqqf
La maquette est un point de départ : elle sert à valider la structure et le parcours avant de coder.
Complétez-la avec les écrans de l'espace agent et administrateur en suivant la section 3, et reliez les
écrans entre eux (mode Prototype de Figma) pour la démonstration.
Charte graphique proposée
ÉlémentChoix
Couleur principaleBleu (#1D4ED8) - confiance, institution
Couleur d'accentVert (#16A34A) pour les validations, orange (#F59E0B) pour les alertes
TypographieInter (titres en semi-gras, texte en régulier)
StyleCartes arrondies, beaucoup d'espace, contrastes conformes à l'accessibilité (WCAG
## AA)

Système intelligent d'orientation des citoyens - Guide du projetPage 15
- Plan détaillé pour mener le projet à bien
Plan sur 16 semaines (à ajuster selon votre calendrier réel). Principe : livrer un MVP fonctionnel tôt, puis
enrichir.
7.1 Vue d'ensemble
PhaseSemainesObjectifLivrables
- Cadrage et organisation1Équipe, outils, dépôt Git, règles de
travail
Charte d'équipe, backlog
- Analyse des besoins1-2Comprendre, cadrer, prioriserCahier des charges v1
- Conception2-5UML, base de données, maquettes,
choix techniques
UML, MCD/MLD, maquette
Figma, architecture
- Base de connaissances4-6Collecter services, procédures,
documents et exemples de demandes
Jeu de données v1
- Développement du
noyau (MVP)
5-10Auth, catalogue, moteur v1, chatbot,
rendez-vous, e-mail
MVP web démontrable
- Extensions10-13Espace agent, notifications SMS,
dashboard, avis, mobile
Version complète
- Tests et sécurité12-14Tests fonctionnels, évaluation du
moteur, correction
Rapport de tests, métriques
- Documentation13-15Doc technique, API, manuelDocuments finaux
- Mémoire et soutenance14-16Rédaction, répétitions, démonstrationMémoire, présentation
7.2 Détail des phases
Phase 0 - Cadrage et organisation (semaine 1)
-Répartir les rôles : chef de projet, back-end/API, front-end/UX, mobile, IA/données, base de
données/tests (une personne peut cumuler).
-Créer le dépôt Git, les branches, un tableau de tâches (Trello, GitHub Projects ou Notion) et un canal
de communication.
-Fixer les règles : nommage, revue de code, réunion hebdomadaire de 30 minutes.
Terminé quand : tout le monde peut cloner le projet et voit ses tâches.
Phase 1 - Analyse des besoins (semaines 1-2)
-Lister les acteurs et leurs besoins (entretiens ou observation d'un guichet si possible, sinon recherche
documentaire).
-Rédiger les exigences fonctionnelles et non fonctionnelles, numérotées.
-Prioriser avec la méthode MoSCoW : Must (orientation, catalogue, rendez-vous, e-mail, rôles), Should
(dashboard, SMS, avis), Could (voix, multi-langue, push), Won't (paiement en ligne, intégration aux
systèmes d'État).
Terminé quand : le cahier des charges est relu et validé par l'encadrant.
## Phase 2 - Conception (semaines 2-5)
-UML : cas d'utilisation, classes, séquences, activités, états.
-Modèle de données et dictionnaire de données.
-Maquettes Figma des écrans principaux et du parcours citoyen ; validation avec quelques utilisateurs.

Système intelligent d'orientation des citoyens - Guide du projetPage 16
-Architecture technique et choix des outils ; contrat d'API (endpoints, formats) écrit avant le code.
Terminé quand : les diagrammes, le schéma de base et la maquette sont cohérents entre eux.
Phase 3 - Base de connaissances (semaines 4-6, en parallèle)
-Choisir 6 à 10 services réalistes (état civil, fiscalité, urbanisme, affaires sociales, etc.).
-Pour chacun : missions, procédures, étapes, pièces requises, horaires, lieu, frais.
-Construire le jeu d'exemples de demandes : au moins 30 à 50 formulations par service (variantes,
fautes, langage courant), plus un jeu de test séparé de 20 % non utilisé à l'entraînement.
Terminé quand : la base est chargée par script et le jeu de test est figé.
Phase 4 - Développement du noyau MVP (semaines 5-10)
-Sprint 1 (sem. 5-6) : authentification, rôles, gestion des comptes, catalogue des services (lecture).
-Sprint 2 (sem. 7-8) : moteur d'orientation v1 (TF-IDF + classifieur), endpoint /orientation, interface de
chat, affichage du résultat et des pièces.
-Sprint 3 (sem. 9-10) : calendrier, créneaux, prise de rendez-vous, e-mails de confirmation et de rappel,
suivi des demandes.
Terminé quand : le parcours principal (3.7) fonctionne de bout en bout en démonstration.
## Phase 5 - Extensions (semaines 10-13)
-Espace agent : demandes orientées, calendrier, édition des procédures, réorientation.
-Tableau de bord statistique et rapports exportables ; avis des citoyens.
-Notifications SMS et push ; alertes de documents manquants.
-Application mobile (ou PWA) reprenant les écrans essentiels ; amélioration du moteur (embeddings,
retours agents).
Terminé quand : chaque rôle dispose de son espace et des statistiques s'affichent avec de vraies
données.
Phase 6 - Tests et sécurité (semaines 12-14)
-Tests unitaires (API, moteur), tests d'intégration et scénarios de bout en bout.
-Évaluation du moteur : précision, rappel, matrice de confusion, exemples d'échecs et corrections.
-Test d'utilisabilité avec 5 à 10 personnes ; correction des points bloquants.
-Revue de sécurité : accès par rôle, injections, mots de passe, limitation de débit.
Terminé quand : le rapport de tests est rédigé et les défauts majeurs sont corrigés.
## Phase 7 - Documentation (semaines 13-15)
-Documentation technique et API (OpenAPI), README, guide de déploiement.
-Manuel utilisateur illustré par rôle.
Terminé quand : une personne extérieure peut installer et utiliser le système avec vos documents.
Phase 8 - Mémoire et soutenance (semaines 14-16)
-Finaliser le mémoire (plan en 2.10), relectures croisées, vérification des références.
-Préparer la présentation (15 à 20 diapositives), le scénario de démonstration, une vidéo de secours si
la démonstration en direct échoue.
-Répéter au moins deux fois avec chronométrage et questions pièges.
Terminé quand : le mémoire est déposé et la démonstration est stabilisée.
7.3 Organisation de l'équipe (exemple pour 4 à 5 personnes)
RôleResponsabilités principales
Chef de projet / analysteCahier des charges, planning, suivi, coordination, mémoire

Système intelligent d'orientation des citoyens - Guide du projetPage 17
RôleResponsabilités principales
Développeur back-endAPI, base de données, sécurité, notifications
Développeur front-end / UXMaquettes, interface web, accessibilité
Développeur mobileApplication mobile ou PWA
Responsable IA / donnéesBase de connaissances, jeu d'exemples, moteur d'orientation, évaluation
Tous participent aux tests, à la documentation et à la soutenance : chacun doit savoir expliquer l'ensemble
du projet.
7.4 Rituels de travail
-Une réunion hebdomadaire de 30 minutes : fait, à faire, blocages.
-Sprints de 2 semaines avec démonstration interne à la fin.
-Revue de code avant chaque fusion dans la branche principale.
-Journal de bord partagé : décisions, difficultés, captures (matière du mémoire).

Système intelligent d'orientation des citoyens - Guide du projetPage 18
- Risques, critères d'évaluation et conseils
8.1 Risques principaux et parades
RisqueParade
Périmètre trop largePrioriser le MVP (MoSCoW), geler les nouvelles idées après la semaine 10.
Pas assez de données pour le
moteur
Générer et varier les exemples, utiliser la similarité sémantique, limiter le nombre
de services au départ.
Moteur peu fiableScore de confiance, questions de clarification, réorientation par l'agent,
évaluation chiffrée.
SMS payants ou indisponiblesSimuler avec une passerelle de test/journal ; l'e-mail reste le canal principal.
Retards d'intégrationContrat d'API écrit tôt, données simulées (mocks) pour le front, intégration
continue.
Travail inégal dans l'équipeTâches nominatives, historique Git, rôles clairs.
Panne pendant la démonstrationEnvironnement de secours, jeu de données réinitialisable, vidéo de secours.
8.2 Grille probable d'évaluation
CritèreCe qui fait la différence
Analyse et conceptionCahier des charges précis, UML cohérents, modèle de données solide
Innovation et intelligenceMoteur mesuré, amélioration continue, gestion de l'incertitude
RéalisationParcours complet, rôles, sécurité, qualité de l'interface
DocumentationManuel, API, documentation technique exploitables
MémoireStructure claire, argumentation, résultats chiffrés, limites assumées
SoutenanceDémonstration maîtrisée, réponses argumentées, travail d'équipe visible
8.3 Check-list finale avant de rendre le projet
-Cahier des charges, UML et base de données cohérents avec l'application.
-Dépôt propre, README testé sur une machine neuve, comptes de démonstration.
-Métriques du moteur d'orientation présentes dans le mémoire.
-Documentation API générée et manuel utilisateur illustré.
-Sécurité de base vérifiée ; aucune clé ou mot de passe dans le code.
-Scénario de démonstration écrit et répété ; vidéo de secours enregistrée.
-Mémoire relu, bibliographie complète, annexes (UML, captures, jeu de tests).