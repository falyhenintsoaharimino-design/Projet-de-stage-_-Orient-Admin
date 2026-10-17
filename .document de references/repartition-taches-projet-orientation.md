# Répartition des tâches — Système intelligent d'orientation des citoyens

Équipe de 2 personnes. Répartition en blocs équilibrés (backend/données vs frontend/restitution), avec un socle commun au début et à la fin du projet.

---

## Phase 0 — À faire ensemble (conception)

Cette phase est commune car une erreur de conception se répercute sur tout le reste du projet, pour les deux membres.

- **Cahier des charges finalisé** : reprendre le document fourni, clarifier les zones floues (périmètre exact du chatbot, canaux de notification retenus, etc.)
- **Diagramme de cas d'utilisation** : un cas d'utilisation par acteur (Citoyen, Agent administratif, Administrateur, Responsable de service)
- **Diagramme de classes** : lister les entités principales et leurs relations
  - Entités probables : `Utilisateur`, `Role`, `Service`, `Procedure`, `DocumentRequis`, `Demande`, `RendezVous`, `Creneau`, `Notification`, `Avis`
  - Relations à clarifier : un `Service` a plusieurs `Procedure`, une `Procedure` requiert plusieurs `DocumentRequis`, une `Demande` est liée à un `Utilisateur` et orientée vers un `Service`, etc.
- **Contrat d'API** : définir ensemble les routes REST principales et le format des réponses JSON (ex. `GET /api/services`, `POST /api/demandes`, `POST /api/rendezvous`) — **étape critique** pour que les deux puissent avancer en parallèle sans se bloquer mutuellement.

---

## Personne A — Backend & données

### 1. Modélisation et mise en place de la base de données
- Traduire le diagramme de classes en MCD/MLD PostgreSQL
- Créer le schéma (migrations Doctrine)
- Prévoir les contraintes d'intégrité (clés étrangères, unicité des emails, etc.)
- Jeux de données de test réalistes (fixtures) pour que Personne B puisse développer les interfaces sans attendre le moteur d'orientation

### 2. Authentification et gestion des rôles
- Symfony + LexikJWTAuthenticationBundle
- Inscription, connexion, récupération de mot de passe
- 4 rôles avec permissions distinctes (Citoyen, Agent, Admin, Responsable)
- Tests des accès selon le rôle (un citoyen ne doit pas accéder aux routes admin, etc.)

### 3. API REST — services et procédures
- CRUD `Service` (catalogue, missions)
- CRUD `Procedure` (étapes, délais)
- CRUD `DocumentRequis` (liste des documents par procédure)
- Documentation de l'API (Swagger/OpenAPI si le temps le permet — valorisant pour le rapport)

### 4. Moteur intelligent d'orientation
- Version simple d'abord : classification par mots-clés/règles (texte de la demande → service recommandé)
- Si le temps le permet : amélioration avec un classifieur Python (scikit-learn) exposé en micro-service, appelé par Symfony
- Boucle d'amélioration : stocker les demandes traitées pour affiner les règles/le modèle plus tard

**Livrables Personne A** : schéma BDD documenté, API fonctionnelle et sécurisée par rôle, moteur d'orientation opérationnel (même simple).

---

## Personne B — Frontend, RDV & restitution

### 1. Interfaces web
- Choix technique : React/Vue (SPA) ou Twig (plus rapide à mettre en place avec Symfony)
- Une interface par acteur :
  - **Citoyen** : formulaire de demande, résultat d'orientation, prise de RDV, historique
  - **Agent** : gestion des procédures de son service, consultation des demandes reçues
  - **Admin** : gestion des comptes, de la base de connaissances, supervision
  - **Responsable** : consultation des statistiques

### 2. Application mobile
- Flutter, consommant la même API REST que le web
- Prioriser les fonctionnalités côté citoyen (le mobile sert surtout à ce public)

### 3. Prise de rendez-vous et notifications
- Calendrier de disponibilité par service (créneaux)
- Interface de réservation côté citoyen
- Confirmation par email (Symfony Mailer) et éventuellement SMS (API tierce type Twilio)
- Rappels automatiques avant le RDV

### 4. Tableau de bord statistique
- Statistiques des demandes par service, affluence, indicateurs de satisfaction
- Graphiques (Chart.js ou équivalent côté frontend)
- Export des rapports (PDF/CSV)

**Livrables Personne B** : web + mobile fonctionnels pour les 4 acteurs, système de RDV complet, dashboard exploitable.

---

## Phase finale — À faire ensemble

- Tests de bout en bout (parcours complet : demande → orientation → RDV → notification)
- Documentation technique
- Manuel utilisateur
- Rédaction du rapport de mémoire
- Préparation de la soutenance

---

## Points de synchronisation recommandés

| Moment | Objet |
|---|---|
| Avant de coder | Validation du contrat d'API (routes + format JSON) |
| Mi-parcours | Vérifier que le frontend consomme bien l'API réelle (pas seulement des données mockées) |
| Avant les tests finaux | Intégration complète web + mobile + moteur d'orientation + notifications |

## Alternative possible

Si vous préférez éviter la dépendance frontend → backend, un découpage **par fonctionnalité complète** (chacun développe le front ET le back d'une feature, ex. Personne A = orientation + RDV, Personne B = comptes + dashboard) réduit les blocages mais demande que chacun soit à l'aise sur toute la stack.
