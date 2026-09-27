# Orient-Admin — Système intelligent d'orientation des citoyens

Projet de licence : backend Symfony (API REST + moteur d'orientation) et
frontend statique (HTML/CSS/JS) réunis dans un seul projet.

## Structure

```
orrientation_service_admin/
├── public/              ← webroot unique : sert à la fois le frontend et l'API
│   ├── index.php        ← point d'entrée Symfony (routes /api/*)
│   ├── index.html       ← accueil du frontend
│   ├── pages/            ← toutes les pages du frontend, par rôle
│   ├── assets/           ← CSS, JS (dont assets/js/api.js : client de l'API)
│   └── data/
├── src/
│   ├── Entity/           ← 13 entités Doctrine
│   ├── Controller/        ← contrôleurs API (Auth, Service, Procedure, Demande, Orientation)
│   ├── Service/           ← OrientationEngine (moteur d'orientation par mots-clés)
│   ├── Repository/
│   └── Command/           ← app:seed-demo, app:create-test-user
├── migrations/
└── config/
```

Le frontend et l'API tournent **sur le même serveur** (même origine) : pas de
configuration CORS à gérer en usage normal. `public/.htaccess` sert les
fichiers statiques directement et renvoie tout le reste vers `index.php`
(Apache) ; le serveur local Symfony CLI fait déjà cela nativement.

## Démarrer en local

```bash
composer install
docker compose up -d          # démarre PostgreSQL (voir compose.yaml)
php bin/console doctrine:migrations:migrate
php bin/console app:seed-demo # crée les 4 rôles + un catalogue de services avec mots-clés
php bin/console app:create-test-user  # crée test@test.com / motdepasse123 (rôle citoyen)
symfony serve                 # ou : php -S localhost:8000 -t public
```

Puis ouvrir `http://localhost:8000/` (frontend) — le frontend appelle l'API
sur `/api/...` du même serveur.

## Comptes et rôles

`app:seed-demo` crée aussi 3 comptes de test (mot de passe `motdepasse123`) :
`agent@test.com` (rattaché au premier service créé), `admin@test.com`,
`responsable@test.com`. Avec `app:create-test-user`, ça fait les 4 rôles
testables.

Les rôles (`Role.nom`) sont `citoyen`, `agent`, `admin`, `responsable`, et se
traduisent en `ROLE_CITOYEN`, `ROLE_AGENT`, `ROLE_ADMIN`, `ROLE_RESPONSABLE`
côté Symfony (`Utilisateur::getRoles()`). `ROLE_ADMIN` hérite des droits des
trois autres rôles (voir `role_hierarchy` dans `config/packages/security.yaml`).

Un agent doit être rattaché à un `Service` (`Utilisateur::service`) pour que
les pages de son espace (demandes orientées vers son service, etc.)
affichent quelque chose — c'est déjà le cas du compte `agent@test.com` créé
par `app:seed-demo`.

## Ce qui n'a pas pu être vérifié dans cet environnement

Cet environnement de développement n'a pas accès à Packagist ni à
getcomposer.org (réseau restreint), donc `composer install` n'a pas pu être
exécuté ici : `vendor/` n'existe pas, et il n'a donc pas été possible de
démarrer réellement le serveur Symfony pour un test de bout en bout réel.
Tout le code neuf a été vérifié avec `php -l` (aucune erreur de syntaxe) et
la logique du moteur d'orientation a été testée séparément en PHP pur avec
des cas réalistes. **Un vrai test avec `composer install` + `symfony serve`
reste à faire** avant de considérer cette intégration comme définitivement
validée.
