# AGENTS.md

## Projet Orient-Admin : contexte, règles et avancement

Projet de licence : système intelligent d'orientation des citoyens vers les
services administratifs (API Symfony + frontend HTML/CSS/JS servi depuis
`public/`, puis application mobile Flutter). Les questions de la section
« Ask before generating » ont déjà leur réponse : Doctrine ORM + PostgreSQL,
API JSON (pas de Twig), SecurityBundle avec JWT Lexik. Ne pas les reposer.

### Documents de référence

Dossier `.document de references/` (point au début et espaces dans le nom :
mettre le chemin entre guillemets) :
`Cahier_des_charges_orientation_citoyens.md` (provisoire, sera refait),
`Guide_projet_orientation_citoyens.md`, `projet_3_licence.md` (sujet),
`repartition-taches-projet-orientation.md`. À lire avant toute étape
fonctionnelle ; ne pas les relire en entier à chaque fois.

### Méthode de travail (voir aussi CLAUDE.md)

- Réponses en français, changements minimaux et ciblés, pas de réécriture complète.
- Claude ne peut pas lancer la base : il livre un patch (`git apply`) et un script
  de recette `scripts/smoke-XXX.sh` (curl, affiche OK / ÉCHEC) lancé par l'étudiant.
- Vérification uniquement en ligne de commande ; le front sera refait une fois le
  backend opérationnel.
- Chaque étape se termine par : `doctrine:schema:validate` OK, commit, push.
- Schéma : migrations uniquement (jamais `doctrine:schema:update`).

### Conventions du code

- Erreurs métier : `\DomainException` dont le code est le statut HTTP ; le contrôleur
  la convertit en réponse JSON `{"error": ...}`.
- Rôles : `ROLE_CITOYEN`, `ROLE_AGENT`, `ROLE_RESPONSABLE`, `ROLE_ADMIN` ;
  l'admin hérite des trois autres (`role_hierarchy`). Un agent est rattaché à un `Service`.
- Services réutilisables : `NotificationService::notifier($utilisateur, $type, $sujet, $message)`
  (ligne en base + e-mail asynchrone), `JournalService::enregistrer($action, ?$utilisateur, ?$details)`
  (audit, n'échoue jamais l'action métier), `RendezVousService`, `AvisService`, `OrientationEngine`, `StatistiquesService` (lecture seule, SQL agrégé via DBAL).
- Toute action sensible est journalisée via `JournalService` (noms d'actions en
  minuscules avec `_`, ex. `rdv_confirme`).
- Windows est insensible à la casse mais pas Linux : les types d'entités doivent
  respecter la casse (`?Service`, `?Utilisateur`...).
- Comptes de test (mot de passe `motdepasse123`) : test@test.com (citoyen),
  agent@test.com (service 1), admin@test.com, responsable@test.com.

### Avancement (mis à jour le 28/10/2026, étape 10b : BACKEND GELÉ)

Fait, testé et poussé :
- 13 entités, rôles, auth JWT, services/procédures, demandes, orientation par mots-clés
- Créneaux et rendez-vous (statuts : en_attente, confirme, refuse, annule, termine, absent)
- Étape 1 Notifications (`scripts/smoke.sh`)
- Étape 2 Avis (`scripts/smoke-avis.sh`)
- Étape 3 Journal d'audit, créneaux inclus (`scripts/smoke-journal.sh`) :
  `GET /api/journal` (admin ; filtres action, utilisateurId, du, au ; pagination) et `GET /api/journal/actions`
- Étape 4 Gestion admin + mot de passe oublié (`scripts/smoke-admin.sh`, testée sans erreur) :
  `/api/admin/utilisateurs` (liste filtrée/paginée, détail, création, modification, `/{id}/actif`) et `/api/admin/roles`,
  réservés à l'admin ; compte désactivé refusé à la connexion et sur les jetons déjà émis (`App\Security\UserChecker`) ;
  `POST /api/mot-de-passe/oublie` et `/reinitialiser` (lien signé HMAC, 1 h, usage unique, sans table) ;
  commande `php bin/console app:lien-reinitialisation <email> [--jeton] [--expire]` (tests) ; variable `APP_URL`.
  Le lien pointe vers `/pages/auth/reinitialiser-mot-de-passe.html?token=...` (page front à créer).
- Étape 5 Statistiques + dashboard responsable + export CSV (`scripts/smoke-stats.sh`, testée en vert, aucune migration) :
  `/api/statistiques/{tableau-de-bord, demandes, rendez-vous, affluence, satisfaction, orientation}` et
  `/api/statistiques/export?type=synthese|demandes|rendez-vous|avis` (CSV `;`, UTF-8 BOM), réservés au responsable
  (admin inclus) ; filtres `du`, `au` (AAAA-MM-JJ, inclus), `serviceId`, `pas=jour|mois`. Export journalisé (`export_rapport`).
  Définitions (voir `App\Service\StatistiquesService`) : « type » d'une demande = catégorie de son service ; bonne orientation =
  recommandée par le moteur et non réorientée vers un autre service ; non comprise = moteur sollicité sans recommandation
  (`confiance` renseignée, pas de `serviceRecommande`) ; choix du citoyen (confiance nulle) exclu des taux ; l'affluence compte
  les rendez-vous en_attente/confirme/termine/absent. Non fait : temps d'attente estimé, export PDF/Excel natif (CSV ouvrable dans Excel).

- Étape 5bis Moteur d'orientation (service Python séparé, choix de l'étudiant) :
  - Partie A (testée) : dossier `moteur/` (FastAPI + TF-IDF/scikit-learn, voir `moteur/README.md`) : `/orienter`, `/reentrainer`,
    `/metriques`, `/sante` ; 270 exemples d'entraînement, 207 demandes de test + 20 hors sujet ; jeton `X-Moteur-Token` ; services
    identifiés par leur nom exact. Lancement : `cd moteur`, venv, `pip install -r requirements.txt`, `python -m uvicorn app:app --port 8001`.
  - Partie B (testée : `scripts/smoke-moteur.sh` en vert en mode normal et en mode `REPLI=1` (sans le moteur Python),
    `doctrine:schema:validate` vert ; migration `Version20261019120000`) :
    `OrientationEngine` interroge `MoteurClient` (variables `MOTEUR_URL`, `MOTEUR_TOKEN`, `MOTEUR_SEUIL` dans `.env`) et se rabat sur les
    mots-clés si le service Python est arrêté/incohérent ; `/api/orientation/tester` renvoie `source` (`ia` ou `mots_cles`).
    Retour citoyen EF-18 : `POST /api/demandes/{id}/retour` `{"pertinent": true|false}` (champ `retourPertinent`, 409 si aucune recommandation).
    Entité `ExempleOrientation` (1 par demande, texte anonymisé par `AnonymisationService` : e-mails, numéros de 6 chiffres ou plus,
    nom/prénom du citoyen) alimentée par les réorientations d'agent (priorité) et les « oui » des citoyens (EF-19, EF-22).
    Admin (EF-21) : `GET /api/admin/moteur` (état, métriques, résumé des exemples), `GET /api/admin/moteur/exemples`,
    `DELETE /api/admin/moteur/exemples/{id}`, `POST /api/admin/moteur/reentrainer`. Journal : `retour_orientation`,
    `exemple_supprime`, `moteur_reentraine`. Non fait : `ExempleOrientation` n'est pas encore utilisé dans les statistiques de
    « bonne orientation » (qui reposent sur les réorientations, pas sur `retourPertinent`).

- Étape 5ter Tests des accès par rôle (PHPUnit) + documentation OpenAPI (aucune migration) :
  - `tests/Controller/AccesParRoleTest.php` : pour statistiques, export CSV, administration du moteur, journal, utilisateurs admin,
    réorientation et retour citoyen, vérifie sans jeton (401), citoyen / agent / responsable / admin (403 ou accès). Comptes
    `phpunit-<rôle>@orient-admin.test` créés au besoin dans la base de TEST. `.env.test` vide `MOTEUR_URL` (pas de service Python).
    Lancement : voir « Tests PHPUnit » ci-dessous. À confirmer : première exécution chez l'étudiant.
  - `docs/openapi.yaml` (OpenAPI 3.0.3, 58 opérations, validé) ; `tests/Controller/OpenApiTest.php` échoue si une route `/api`
    n'est pas documentée (ou l'inverse).
  - Règle d'accès constatée : agent et responsable ne s'héritent pas (le responsable ne peut pas réorienter, l'agent n'a pas les statistiques) ;
    l'admin a tout ; `retour` demande ROLE_CITOYEN (donc refusé à l'agent et au responsable).

- Étape 6 Sécurité et comptes (migration `Version20261021120000`, dépendance `symfony/rate-limiter` à installer avec
  `composer require symfony/rate-limiter`) : à confirmer chez l'étudiant (PHPUnit, `scripts/smoke-comptes.sh`, schema:validate).
  - `login_throttling` sur le pare-feu `login` (ENF-04) : 5 échecs par minute et par couple e-mail + IP (25 pour toute l'IP, compteur
    global jamais remis à zéro par une réussite) ; au-delà : 429 + `Retry-After` via `App\Security\LoginFailureHandler`. À passer à
    `'15 minutes'` au déploiement (étape 19). En test, le compteur reste dans le cache fichier (un cache en mémoire est vidé entre deux requêtes) : `ComptesTest` utilise un e-mail aléatoire.
  - Utilisateur : `telephone`, `langue` (`fr`|`mg`), `canalNotification` (`email`|`sms`, le SMS réel/simulé vient à l'étape 9),
    `consentementLe`. `POST /api/register` exige `consentement: true` (date enregistrée), valide l'e-mail, accepte `telephone`.
    Front minimal adapté (case à cocher + `api.register(..., consentement)`) pour ne pas casser l'inscription déjà branchée.
  - `App\Service\CompteService` : `PATCH /api/me` (nom, prenom, telephone, langue, canalNotification ; le reste est ignoré ; sms exige un
    téléphone), `GET /api/me/donnees` (export JSON : profil, demandes + historique, rendez-vous, avis, notifications, 500 dernières lignes de
    journal ; jamais le mot de passe), `DELETE /api/me` `{"motDePasse"}` (citoyens seulement, 204 sans corps) : supprime demandes, historique,
    rendez-vous, avis, notifications ; garde les `ExempleOrientation` (anonymisés, détachés de la demande) et le journal (détaché du compte).
    Journal : `profil_modifie`, `donnees_exportees`, `compte_supprime`, `service_desactive`.
  - `GET /api/services` et `/{id}` : services inactifs cachés au public (404), visibles pour l'admin ; `DELETE /api/services/{id}` =
    désactivation (`actif=false`), 204 sans corps, répétable ; `PUT {"actif": true}` réactive. Non fait : les procédures d'un service inactif
    restent listées (traité à l'étape 7) ; changement d'e-mail et de mot de passe depuis le profil ; admin non supprimable par `DELETE /api/me`.
  - Tests : `ComptesTest`, `ServicesVisibiliteTest`, `OutilsDeTestTrait` (comptes `ctest-...@orient-admin.test` supprimés en fin de test),
    lignes ajoutées à `AccesParRoleTest` ; OpenAPI à 61 opérations.

- Étape 7 Catalogue et procédures (migration `Version20261022120000`, aucune dépendance Composer) : à confirmer chez l'étudiant
  (PHPUnit, `scripts/smoke-catalogue.sh`, schema:validate). Vérifiée avant livraison : migration jouée sur des données existantes,
  PHPUnit 202 tests OK, `smoke-catalogue.sh` 70 OK.
  - Procédure : `source`, `misAJourLe` (renouvelée à chaque modification du contenu), `verifieLe`, `actif`, `numeroVersion` ; nom jusqu'à
    150 caractères. Pièces : `exemplaires` (1 à 20), `ancienneteMaxMois`, `ouObtenir`. Réponses avec `avertissement` (information indicative, ENF-13).
    Correction : `GET` d'une procédure qui avait des étapes plantait (méthode `getDescripton` mal nommée sur `EtapeProcedure`, renommée).
  - `App\Service\CatalogueService` (validation, droits, versions, journal) : `POST/PUT /api/procedures` pour l'agent (SON service) et l'admin ;
    `etapes` (liste de textes, l'ordre = la position) et `documentsRequis` (liste d'objets) remplacent toute la liste quand ils sont envoyés ;
    `serviceId` modifiable par l'admin seulement ; `null` vide un champ facultatif. Erreurs de validation : 400.
  - Historique `ProcedureVersion` : instantané complet à chaque changement réel (aucune version si le contenu est identique) ;
    `GET /api/procedures/{id}/versions` (agent du service, admin). Une procédure antérieure à l'historique reçoit sa version « État initial »
    à sa première modification. Résumés : Création, Modification : champs, Désactivation, Réactivation, État initial.
  - Suppression logique : `DELETE /api/procedures/{id}` = `actif=false` (204 sans corps, réactivable par `PUT {"actif": true}`). Une procédure
    inactive, ou d'un service inactif, est cachée au public (404 + absente des listes) ; `?inclureInactifs=true` la montre à l'admin et à
    l'agent de son service. Les pièces et étapes retirées restent dans les instantanés de l'historique.
  - `POST /api/procedures/{id}/verification` : « information vérifiée » (date `verifieLe`, pas de nouvelle version).
  - Fiche service : `PUT /api/services/{id}` ouvert à l'agent de SON service (mission, adresse, horaires, contact, motsCles ; 403 s'il envoie
    nom, categorie ou actif), à l'admin pour tout. Journal : `service_modifie`, `procedure_creee`, `procedure_modifiee`,
    `procedure_desactivee`, `procedure_reactivee`, `procedure_verifiee`.
  - Recherche (EF-07, EF-12) : `GET /api/services` et `/api/procedures` acceptent `q` (tolérante aux fautes : 1 à 2 lettres d'écart selon la
    longueur du mot, accents et casse ignorés, deux lettres inversées = 1 faute ; tri par pertinence ; `App\Service\RechercheService`, en PHP,
    sans extension PostgreSQL) et `rubrique` (= catégorie du service). `GET /api/services/rubriques` liste les rubriques avec leur nombre de services.
  - Tests : `CatalogueTest`, `RechercheServiceTest`, lignes ajoutées à `AccesParRoleTest` ; OpenAPI à 64 opérations.
    Non fait : restauration d'une ancienne version en un clic, procédures dans l'export de comptes (sans objet), export PDF de la liste de pièces (étape 16).

- Étape 8 Rendez-vous (migration `Version20261026120000`, aucune dépendance Composer) : vérifiée avant livraison : migration jouée sur des
  données existantes, PHPUnit 241 tests OK, `smoke-rendez-vous.sh` 85 OK, recettes `smoke`, `smoke-avis`, `smoke-journal`, `smoke-stats` toujours vertes.
  - Créneau : `capacite` (rendez-vous simultanés) et `dureeMinutes` ; sortie avec `heureFin`, `reservations`, `placesRestantes`, `reservable`.
    `disponible` signifie désormais « ouvert par l'agent » (false = bloqué, EF-29) : réserver ne le ferme plus, c'est le nombre de rendez-vous
    actifs (en_attente, confirme) qui occupe les places. La migration rouvre les créneaux qui avaient été fermés par une réservation.
    Le citoyen ne voit que les créneaux ouverts avec au moins une place. `PATCH /api/creneaux/{id}` : `disponible` et/ou `capacite` (jamais sous le
    nombre de rendez-vous actifs) ; on peut rouvrir un créneau qui a déjà un rendez-vous.
  - Service : `dureeCreneauMinutes` (5 à 240), `capaciteCreneau` (1 à 100) et `joursFermes` (1 = lundi ... 7 = dimanche, week-end par défaut), modifiables
    par l'agent de SON service (`PUT /api/services/{id}`).
  - `POST /api/creneaux/generer` (agent, admin avec `serviceId`) : période (93 jours au plus), plage horaire, pause, durée, capacité, jours fermés,
    `datesFermees` ; réglages du service par défaut ; créneaux déjà existants ignorés, créneaux déjà passés non créés ; journal `creneaux_generes`.
    Calculs purs dans `GenerateurCreneaux`. `POST /api/creneaux` accepte aussi `capacite` et `dureeMinutes`.
  - Réservation (`RendezVousService`) : capacité vérifiée sous verrou, créneau passé refusé à l'heure près (409), « complet » (409).
    Transaction manuelle : une erreur métier annule sans fermer l'EntityManager.
  - Fuseau : `APP_TIMEZONE` (`Indian/Antananarivo` par défaut, dans `.env`) via `App\Service\Fuseau`. Les heures des créneaux sont lues dans ce fuseau
    (`Creneau::getDebut($fuseau)`) pour décider qu'un créneau est passé ou que le délai limite est dépassé, même si le serveur PHP est en UTC.
    Les dates déjà enregistrées en base et le fuseau global de PHP ne changent pas. À réutiliser pour les rappels (étape 9). Les recettes calculent
    « demain » avec ce même fuseau (leçon : le shell Windows et PHP peuvent être à des dates différentes).
  - Délai limite (EF-26) : variable `RDV_DELAI_ANNULATION_HEURES` (24 par défaut, dans `.env`). Le citoyen ne peut plus modifier ni annuler son
    rendez-vous passé ce délai (409) ; l'agent et l'admin ne sont pas concernés. Sorties : `modifiableJusquau`, `modifiableParLeCitoyen`.
  - `PATCH /api/rendez-vous/{id}` (citoyen auteur ou admin) : autre créneau du même service et/ou `procedureId` ; un autre créneau remet le rendez-vous
    en attente (reconfirmation par l'agent) ; journal `rdv_modifie`. `PATCH /api/rendez-vous/{id}/reporter` (agent de ce service, admin) : `creneauId`,
    `motif` facultatif ; rendez-vous confirmé sur le nouveau créneau, citoyen prévenu ; journal `rdv_reporte`.
  - Récapitulatif (EF-28) : `GET /api/rendez-vous/{id}/recapitulatif` (date, heures de début et de fin, lieu avec adresse, horaires et contact, pièces à
    apporter de la procédure choisie, date limite de modification). `procedureId` facultatif à la réservation. Les notifications (et e-mails) de réservation,
    confirmation, modification et report indiquent le lieu et les pièces à apporter (EF-30).
  - Tests : `RendezVousTest`, `GenerateurCreneauxTest`, `CreneauTest`, lignes ajoutées à `AccesParRoleTest` ; OpenAPI à 68 opérations.
    Non fait (volontairement) : blocage d'une journée entière en une seule requête (se fait créneau par créneau), jours fériés globaux (étape 10, paramètres),
    motif libre propre au rendez-vous (le texte de la demande en tient lieu).

- Étape 9 Notifications (migration `Version20261027120000`, aucune dépendance Composer) : vérifiée avant livraison : migration jouée, PHPUnit 266 tests OK,
  `smoke-notifications.sh` 59 OK (rejouable), recettes `smoke-rendez-vous`, `smoke-avis`, `smoke-journal`, `smoke-comptes` toujours vertes.
  - SMS simulé (EF-33) : `App\Service\SmsSimulateur`, aucun SMS réel. Un citoyen qui a choisi `canalNotification = sms` (téléphone obligatoire, vérifié à l'étape 6)
    reçoit un SMS simulé à la place de l'e-mail ; la notification de l'application est toujours créée. Le SMS est enregistré comme notification de canal `sms`
    (statut `envoye`, 320 caractères au plus, préfixe « Orient-Admin : ») : il n'apparaît pas dans le centre de notifications. `GET /api/sms-simules`
    (admin, `limite`) liste la boîte d'envoi. Une vraie passerelle remplacerait seulement `SmsSimulateur`.
  - Rappel de la veille (EF-31) : commande `php bin/console app:rappels-rdv` (options `--jours=1`, `--date=AAAA-MM-JJ`, `--dry-run`). Elle prévient les citoyens
    dont le rendez-vous CONFIRMÉ a lieu demain (jour calculé dans le fuseau `APP_TIMEZONE`). Une seule fois par rendez-vous (`RendezVous::rappelEnvoyeLe`), donc
    sans danger si elle est lancée plusieurs fois ; un rendez-vous modifié ou reporté sera rappelé de nouveau. Journal `rappels_envoyes`. À PLANIFIER : cron
    (`0 8 * * * cd /chemin/du/projet && php bin/console app:rappels-rdv`) ou planificateur de tâches Windows (programme `php`, arguments `bin/console app:rappels-rdv`).
  - Alertes (EF-32) : `POST /api/rendez-vous/{id}/pieces-manquantes` (agent de ce service, admin ; `pieces` 1 à 20 textes, `message` facultatif ; 409 si le rendez-vous
    n'est plus actif ; journal `rdv_pieces_manquantes`). Changement de procédure : quand une procédure change vraiment (autre chose que `source` ou le libellé de version),
    est désactivée, les citoyens qui ont un rendez-vous à venir (en attente ou confirmé) choisi avec cette procédure (`procedureId`) sont prévenus, avec les pièces à
    apporter ; pas d'alerte pour une réactivation. Types de notification : `rdv_rappel`, `pieces_manquantes`, `procedure_modifiee`, `message_agent`.
  - Messages de l'agent (page « communication ») : entité `MessageAgent`. `POST /api/demandes/{id}/messages` (agent du service de la demande, admin ; `message` 1 à
    2000 caractères, `sujet` facultatif) ; `GET /api/demandes/{id}/messages` (citoyen auteur, agent du service, responsable, admin), du plus ancien au plus récent.
    Le citoyen reçoit chaque message comme notification (application + e-mail ou SMS). Journal `message_agent_envoye`.
  - Tests : `NotificationsTest` (rappels via CommandTester, SMS, pièces manquantes, alerte de procédure, messages), lignes ajoutées à `AccesParRoleTest`, utilitaires partagés
    dans `OutilsRendezVousTrait` ; OpenAPI à 72 opérations.
    Non fait (volontairement) : vraie passerelle SMS, notifications push (EF-35, priorité C), alerte « pièces manquantes » liée à une demande sans rendez-vous, modèles de messages
    modifiables (étape 10, paramètres).

- Étape 10a Paramètres + sauvegarde/restauration (migration `Version20261028120000`, aucune dépendance Composer) : vérifiée avant livraison : migration jouée,
  PHPUnit 302 tests OK, `smoke-parametres.sh` 71 OK (rejouable), recettes `smoke-notifications`, `smoke-rendez-vous`, `smoke-catalogue`, `smoke-journal` toujours vertes.
  - Paramètres (EF-46) : entité `Parametre` (clé / valeur JSON), `App\Service\ParametreService`. `GET` et `PUT /api/admin/parametres` (admin ; `PUT` partiel, tout est validé
    avant d'écrire ; journal `parametres_modifies`) : `dureeCreneauDefautMinutes` (5 à 240, 30 par défaut) = durée d'un NOUVEAU service créé sans `dureeCreneauMinutes`
    (les services existants gardent leur réglage) ; `joursFeries` (liste complète de `{date, libelle}`, 100 au plus) exclus de `POST /api/creneaux/generer`
    (réponse : `joursFeriesExclus`) ; `modelesMessages` : sujet + texte des 8 notifications adressées au citoyen (`rdv_en_attente`, `rdv_confirme`, `rdv_modifie`,
    `rdv_reporte`, `rdv_refuse`, `rdv_termine`, `rdv_rappel`, `pieces_manquantes`), avec variables `{quand}`, `{lieu}`, `{service}`, `{pieces}`, `{ancien}`, `{motif}`,
    `{liste}`, `{message}` selon le type (variable inconnue = 400) ; `null` = retour au texte par défaut (les défauts sont dans `ParametreService::MODELES`, identiques aux
    anciens textes). `GET /api/jours-feries` (agent, admin ; `du`, `au`). Non fait : jours fériés hors génération (un créneau créé à la main un jour férié reste possible),
    modèles pour les notifications destinées aux agents, `rdv_annule`, `procedure_modifiee` et `message_agent`, jours fériés officiels préremplis.
  - Sauvegarde et restauration (EF-47, ENF-15) : `App\Service\SauvegardeService` (pg_dump / pg_restore, format personnalisé, fichiers `orient-admin-AAAAMMJJ-HHMMSS.dump` dans
    `SAUVEGARDE_DIR`, par défaut `var/sauvegardes`, hors git ; variables `PG_DUMP_BIN` et `PG_RESTORE_BIN` si les programmes ne sont pas dans le PATH, ex. chemin complet
    de `pg_dump.exe` sous Windows). Commande `php bin/console app:sauvegarde [--garder=14]` (crée puis ne garde que les N plus récentes ; journal `sauvegarde_creee`)
    À PLANIFIER chaque jour : cron `0 2 * * * cd /chemin/du/projet && php bin/console app:sauvegarde` ou planificateur de tâches Windows (programme `php`, arguments
    `bin/console app:sauvegarde`). Commande `php bin/console app:restauration <nom|chemin> [--base=AUTRE_BASE] [--oui]` : avec `--base`, restaure dans une autre base (créée
    si besoin) et affiche deux lignes « Contrôle » (courante / restaurée) à comparer : c'est la restauration TESTÉE (ENF-15) sans toucher à la base courante ; sans `--base`,
    remplace la base courante après confirmation (`--oui`), en une seule transaction (échec = rien de modifié), serveur web arrêté. Journal `sauvegarde_restauree`.
    API admin : `GET` et `POST /api/admin/sauvegardes` (liste, sauvegarde immédiate, 503 si pg_dump introuvable), `GET /api/admin/sauvegardes/{nom}` (téléchargement, journal
    `sauvegarde_telechargee`). La RESTAURATION n'est volontairement pas dans l'API (elle remplace toutes les données) : ligne de commande seulement. Le mot de passe de la base
    passe par `PGPASSWORD`, jamais dans la ligne de commande. Non fait : chiffrement des fichiers, copie hors serveur (à prévoir au déploiement, étape 19), sauvegarde des
    exemples du moteur Python (le dossier `moteur/` n'a pas de base propre).
  - Tests : `ParametresTest`, `SauvegardeTest` (sautés si pg_dump est absent ; dossier `var/sauvegardes_test`, base temporaire `orient_admin_phpunit_verif` supprimée en fin de test),
    lignes ajoutées à `AccesParRoleTest` ; OpenAPI à 78 opérations.

- Étape 10b Recette finale du backend (aucune migration, aucune dépendance Composer) : vérifiée avant livraison : PHPUnit toute la suite OK, `doctrine:schema:validate` OK,
  OpenAPI validé avec `openapi-spec-validator` (78 opérations, aucune référence cassée), `scripts/recette-finale.sh` 102 vérifications OK sur 9 scénarios (rejouable),
  recettes `smoke-*` toujours vertes.
  - Défaut trouvé par la recette et corrigé : `POST /api/orientation/tester` (public) et `POST /api/demandes` renvoyaient 500 si `texte` était un tableau ou un objet, et
    n'avaient aucune longueur maximale. Maintenant : `texte` doit être une chaîne de 1 à `OrientationEngine::TEXTE_MAX` (2000) caractères, sinon 400 ; un corps JSON qui n'est
    pas un objet donne aussi 400 (`EntreesInvalidesTest`, 23 cas). Un `texte` numérique (ex. 123), accepté autrefois, est maintenant refusé (400).
  - `scripts/recette-finale.sh [URL]` : scénarios S1 à S9 du cahier avec un verdict par scénario (S1 visiteur et procédure, S2 demande ambiguë ou hors sujet, S3 compte et mot de
    passe oublié, S4 rendez-vous complet de la réservation au rappel, S5 réorientation, S6 versions d'une procédure, S7 statistiques et export CSV, S8 qualité du moteur,
    S9 sécurité). Les rendez-vous de test sont annulés ; restent en base des comptes `recette-finale-…`, des demandes, des créneaux et des procédures de test.
  - `scripts/test-charge.py [--url U --utilisateurs 100 --parcours 5]` (Python standard, sans installation) : ENF-01 et ENF-02. Mesure de référence : 100 utilisateurs
    simultanés, 1 500 requêtes, 0 erreur, assistant au 95e centile en 1,87 s (machine à 1 cœur, PHP en mode prod avec 16 processus, client sur la même machine). À rejouer sur
    le serveur de démonstration. Sous Windows, `symfony serve` suffit ; le serveur intégré `php -S` ne traite qu'une requête à la fois (résultats pessimistes).
  - Moteur : 97,1 % en 1re proposition (cible 80 %), 100 % dans les 3 premières (cible 95 %), 65 % des demandes hors sujet écartées. Jeu de test écrit par l'équipe : chiffres
    optimistes, à dire tels quels au jury (voir `docs/conformite.md`).
  - `docs/conformite.md` : tableau des 49 exigences fonctionnelles et 15 non fonctionnelles (état, preuve, reste à faire) : EF 37 faites, 8 partielles, 3 pour le front, 1 non faite
    (push, priorité C) ; ENF 7 faites, 4 partielles, 3 pour le front, 1 pour le déploiement.
  - Restes connus du backend (décidés, non bloquants) : mot de passe oublié par SMS (EF-03), alerte pièces manquantes sans rendez-vous (EF-32), export PDF/Excel natif (EF-43),
    malagasy dans le moteur (EF-13), notifications push (EF-35), vraie passerelle SMS et vrai SMTP (au déploiement), jours fériés hors génération de créneaux (10a).

Tests PHPUnit : créer `.env.test.local` (ignoré par git ; `.env.local` n'est PAS lu en environnement test) avec `DATABASE_URL`
(même valeur que `.env.local`, le nom de base reçoit tout seul le suffixe `_test`) et `JWT_PASSPHRASE`. Puis, une fois :
`php bin/console doctrine:database:create --env=test --if-not-exists`, `php bin/console doctrine:migrations:migrate --env=test --no-interaction` ;
et à chaque fois : `php bin/phpunit`.

Reste à faire, dans cet ordre (décidé le 21/10/2026 après l'audit du backend et du front par rapport au cahier des charges) :

Règle d'organisation (décidée le 25/10/2026) : 2 à 3 étapes par discussion selon la longueur des tâches, car relire le dépôt à
chaque nouvelle discussion consomme beaucoup de tokens ; un patch, un script de recette et un commit par étape ; un résumé de
passation seulement à la fin de la discussion (pas entre deux étapes d'une même discussion). Le backend est terminé et gelé AVANT la revue du front, la revue AVANT la refonte,
la refonte AVANT la connexion. Rien n'est branché à l'API tant que l'étape 16 n'est pas faite.

PHASE 1 : compléter le backend (chaque étape : patch, tests PHPUnit, script de recette, OpenAPI à jour, `doctrine:schema:validate`)
6. FAIT (voir Avancement) : sécurité et comptes.
7. FAIT (voir Avancement) : catalogue et procédures. Cahier : champs `source` et date de mise à jour/vérification sur chaque procédure (EF-09, ENF-13) ;
   procédures, étapes et pièces modifiables par l'agent pour SON service, l'admin pour tous (EF-11, scénario S6) ; historique des
   versions et suppression logique ; fiche du service modifiable par son agent ; recherche tolérante aux fautes et filtres par
   rubrique sur services et procédures (EF-07, EF-12) ; pièces enrichies (nombre d'exemplaires, ancienneté maximale, où l'obtenir).
8. FAIT (voir Avancement) : rendez-vous. Cahier : créneaux avec durée, capacité et jours fermés, génération en lot (EF-23) ; modification d'un rendez-vous par le
   citoyen et délai limite d'annulation (EF-26) ; report par l'agent (EF-27) ; récapitulatif de confirmation avec lieu et pièces
   à apporter (EF-28).
9. FAIT (voir Avancement) : notifications. Cahier : rappel automatique la veille (commande `app:rappels-rdv` à planifier, EF-31) ; alerte pièces manquantes ou
   changement de procédure (EF-32) ; SMS via un simulateur (EF-33) ; messages de l'agent vers le citoyen (page « communication »).
10. Administration et recette finale du backend, en deux sous-étapes :
   10a. FAIT (voir Avancement) : paramètres (modèles de messages, jours fériés, durée par défaut, EF-46) ; sauvegarde et restauration (EF-47, ENF-15).
   10b. FAIT (voir Avancement) : métriques du moteur (cibles atteintes), test de charge, tableau de conformité EF/ENF, recette S1 à S9, relecture d'OpenAPI.
   LE BACKEND EST GELÉ : plus de changement sans raison (bogue prouvé, besoin découvert pendant la revue du front, ou déploiement).

PHASE 2 : revue du front, une discussion par rôle, sans brancher l'API (revoir chaque page : contenu, ergonomie, accessibilité
WCAG 2.1 AA, responsive, besoins backend oubliés ; résultat : liste d'améliorations validée par l'étudiant, notée ici)
11. Revue des pages de l'administrateur (tableau de bord, utilisateurs, sécurité-journal, services, base de connaissances,
    moteur, paramètres)
12. Revue des pages de l'agent
13. Revue des pages du responsable
14. Revue des pages du citoyen
15. Revue des pages publiques et d'authentification (visiteur, connexion, inscription, mot de passe oublié, page de
    réinitialisation à créer)

PHASE 3 : refonte du front
16. Nouveau logo et charte graphique, puis refonte complète du front (toutes les pages, une discussion par rôle) à partir des
    revues des étapes 11 à 15 ; liste de contrôle des pièces cochable, imprimable ou exportable en PDF (EF-10) ;
    PWA pour l'usage mobile (EF-48) ; textes externalisés pour ajouter le malagasy (ENF-08).

PHASE 4 : connexion back-front (tout à la fin, sur le front refondu ; `api.js` complété : avis, notifications, retour citoyen,
mot de passe, créneaux, statistiques, journal, admin, moteur, écritures sur services et procédures)
17. a) auth, visiteur et citoyen ; b) agent ; c) responsable (statistiques, export CSV) ; d) admin. Déjà branchés avant la
    refonte (à rebrancher) : auth, assistant, mes demandes, agent/demandes, prendre-rendez-vous, mes-rendez-vous,
    agent/rendez-vous, catalogue. Un script `scripts/smoke-front-<rôle>.sh` par sous-étape.

PHASE 5 : suite du projet
18. Mobile Flutter (citoyen), puis documentation (UML, OpenAPI, manuel utilisateur, rapport).
19. Une fois tout le site achevé : déploiement en ligne (hébergement et méthode à choisir avec Claude,
    pour que ça ressemble à un vrai site web), puis version Android de l'application mobile.
    Claude doit aider pour le déploiement et pour l'app Android.

Mettre cette section à jour à la fin de chaque étape, dans le même commit.

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
