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
  (audit, n'échoue jamais l'action métier), `RendezVousService`, `AvisService`, `OrientationEngine`.
- Toute action sensible est journalisée via `JournalService` (noms d'actions en
  minuscules avec `_`, ex. `rdv_confirme`).
- Windows est insensible à la casse mais pas Linux : les types d'entités doivent
  respecter la casse (`?Service`, `?Utilisateur`...).
- Comptes de test (mot de passe `motdepasse123`) : test@test.com (citoyen),
  agent@test.com (service 1), admin@test.com, responsable@test.com.

### Avancement (mis à jour le 18/10/2026)

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

Reste à faire, dans cet ordre :
5. Statistiques + dashboard responsable + export CSV
6. Moteur d'orientation (non bloquant)
7. Tests des accès par rôle (PHPUnit) + OpenAPI
8. Front : brancher agent/creneaux, donner-avis, notifications, profil, tableaux de bord,
   responsable/*, admin/*, mot-de-passe-oublié (déjà branchés : auth, assistant, mes demandes,
   agent/demandes, prendre-rendez-vous, mes-rendez-vous, agent/rendez-vous, catalogue)
9. Mobile Flutter (citoyen), puis documentation (UML, OpenAPI, manuel, rapport)
10. Une fois tout le site achevé : déploiement en ligne (hébergement et méthode à choisir avec Claude,
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
