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

### Avancement (mis à jour le 21/10/2026)

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

Tests PHPUnit : créer `.env.test.local` (ignoré par git ; `.env.local` n'est PAS lu en environnement test) avec `DATABASE_URL`
(même valeur que `.env.local`, le nom de base reçoit tout seul le suffixe `_test`) et `JWT_PASSPHRASE`. Puis, une fois :
`php bin/console doctrine:database:create --env=test --if-not-exists`, `php bin/console doctrine:migrations:migrate --env=test --no-interaction` ;
et à chaque fois : `php bin/phpunit`.

Reste à faire, dans cet ordre (décidé le 21/10/2026 après l'audit du backend et du front par rapport au cahier des charges) :

Règle d'organisation : une discussion par étape (ou par rôle pour les revues), avec un résumé de passation précis en fin de
discussion, pour économiser les tokens. Le backend est terminé et gelé AVANT la revue du front, la revue AVANT la refonte,
la refonte AVANT la connexion. Rien n'est branché à l'API tant que l'étape 16 n'est pas faite.

PHASE 1 : compléter le backend (chaque étape : patch, tests PHPUnit, script de recette, OpenAPI à jour, `doctrine:schema:validate`)
6. Sécurité et comptes : limitation des tentatives de connexion (ENF-04, `login_throttling`) ; `PATCH /api/me` (profil) avec
   téléphone, langue et canal de notification préféré (EF-01, EF-05) ; suppression du compte sur demande, consentement à l'inscription (case à cocher, date enregistrée) et export des données
   personnelles du compte, `GET /api/me/donnees` (droit d'accès, ENF-05) ;
   `GET /api/services` sans les services inactifs pour le public (l'admin voit tout) ; `DELETE /api/services/{id}` en suppression
   logique (au lieu de la suppression définitive qui peut échouer) avec une réponse 204 sans corps.
7. Catalogue et procédures : champs `source` et date de mise à jour/vérification sur chaque procédure (EF-09, ENF-13) ;
   procédures, étapes et pièces modifiables par l'agent pour SON service, l'admin pour tous (EF-11, scénario S6) ; historique des
   versions et suppression logique ; fiche du service modifiable par son agent ; recherche tolérante aux fautes et filtres par
   rubrique sur services et procédures (EF-07, EF-12) ; pièces enrichies (nombre d'exemplaires, ancienneté maximale, où l'obtenir).
8. Rendez-vous : créneaux avec durée, capacité et jours fermés, génération en lot (EF-23) ; modification d'un rendez-vous par le
   citoyen et délai limite d'annulation (EF-26) ; report par l'agent (EF-27) ; récapitulatif de confirmation avec lieu et pièces
   à apporter (EF-28).
9. Notifications : rappel automatique la veille (commande `app:rappels-rdv` à planifier, EF-31) ; alerte pièces manquantes ou
   changement de procédure (EF-32) ; SMS via un simulateur (EF-33) ; messages de l'agent vers le citoyen (page « communication »).
10. Administration et recette finale du backend : paramètres (modèles de messages, jours fériés, durée par défaut, EF-46) ;
   sauvegarde et restauration (EF-47, ENF-15) ; métriques du moteur comparées aux cibles 80 % (1re proposition) et 95 % (top 3),
   écart expliqué si non atteint ; test de charge : assistant en 3 s ou moins pour 95 % des requêtes (ENF-01), 100 utilisateurs simultanés (ENF-02) ;
   tableau de conformité EF/ENF ; script de recette des scénarios S1 à S9 ; relecture d'OpenAPI.
   À la fin : le backend est GELÉ (plus de changement sans raison).

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
