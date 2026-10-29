# Conformité du backend aux exigences du cahier des charges

État au gel du backend (étape 10b, 28/10/2026). Preuves : suite PHPUnit, scripts de recette `scripts/smoke-*.sh`, recette finale `scripts/recette-finale.sh` (scénarios S1 à S9), test de charge `scripts/test-charge.py`, métriques du moteur `moteur/metriques.json`.

Légende : ✅ fait côté backend et prouvé · 🟡 partiel (reste précisé) · 🖥️ revient au front · 🚀 dépend du déploiement · ❌ non fait.

## Résumé

| | ✅ | 🟡 | 🖥️ | 🚀 | ❌ | Total |
|---|---|---|---|---|---|---|
| Exigences fonctionnelles (EF) | 37 | 8 | 3 | 0 | 1 | 49 |
| Exigences non fonctionnelles (ENF) | 7 | 4 | 3 | 1 | 0 | 15 |

Exigences « Doit » (M) : 27 ; ✅ 21, 🟡 5, 🖥️ 1.

## Exigences fonctionnelles

| ID | Exigence | Prio. | État | Preuve ou reste à faire |
|---|---|---|---|---|
| EF-01 | Inscription d'un citoyen avec validation | M | ✅ | `smoke-comptes.sh`, `ComptesTest`, recette S3 (consentement obligatoire, e-mail unique) |
| EF-02 | Connexion / déconnexion, session limitée | M | ✅ | JWT RS256 de 1 h (durée par défaut de Lexik, à régler au besoin) ; déconnexion = le front jette le jeton ; comptes désactivés refusés même avec un ancien jeton (`smoke-admin.sh`) |
| EF-03 | Mot de passe oublié par e-mail ou SMS | M | 🟡 | E-mail : lien à usage unique valable 1 h (S3, `smoke-admin.sh`) ; en développement le lien est lu avec `app:lien-reinitialisation` (`MAILER_DSN=null://null`). Par SMS : non fait. Page front de saisie du nouveau mot de passe à créer |
| EF-04 | Rôles et contrôle d'accès partout | M | ✅ | `AccesParRoleTest` (chaque route × chaque rôle), recettes S3, S5, S7, S9 |
| EF-05 | Profil et préférences (canal, langue) | S | ✅ | `PATCH /api/me` (`smoke-comptes.sh`) |
| EF-06 | Assistant sans compte, sans historique | S | ✅ | `POST /api/orientation/tester` public, ne crée aucune demande (S1, S2, test de charge) |
| EF-07 | Catalogue par rubrique, recherche, filtres | M | ✅ | `GET /api/services`, `/rubriques`, `q`, `rubrique` (`smoke-catalogue.sh`) |
| EF-08 | Fiche service (missions, adresse, horaires, contacts, procédures) | M | ✅ | `GET /api/services/{id}` (`smoke-catalogue.sh`) |
| EF-09 | Fiche procédure complète (étapes, pièces, délai, frais, mise à jour, source) | M | ✅ | `GET /api/procedures` (S1 vérifie chaque champ) |
| EF-10 | Liste de contrôle des pièces, cochable, imprimable ou PDF | S | 🖥️ | L'API fournit les pièces (`documentsRequis`, récapitulatif de rendez-vous) ; cases à cocher, impression et PDF sont à faire dans le front |
| EF-11 | Création/modification/suppression logique, historique des versions | M | ✅ | `smoke-catalogue.sh`, S6 (version 2, historique réservé aux agents) |
| EF-12 | Recherche tolérante aux fautes de frappe | S | ✅ | `RechercheServiceTest`, `smoke-catalogue.sh` |
| EF-13 | Demande en texte libre en français (malagasy plus tard) | M | 🟡 | Français : fait. Malagasy : la préférence `langue` (fr/mg) existe, mais le moteur n'a pas de modèle malagasy (phase ultérieure prévue par le cahier) |
| EF-14 | Recommandation du service avec score de confiance | M | ✅ | Moteur Python + repli par mots-clés (`smoke-moteur.sh`, S1, S5, S8) |
| EF-15 | Trois meilleures propositions alternatives | S | ✅ | champ `alternatives` de la réponse (S8 : le bon service est dans les 3 premiers dans 100 % des cas du jeu de test) |
| EF-16 | Clarification ou repli sous le seuil de confiance | M | 🟡 | L'API renvoie `service: null` sous le seuil (S2). Le texte du message de repli (renvoi vers le catalogue ou le contact) est à afficher par le front |
| EF-17 | Résultat : service, procédure, pièces, délai, frais, adresse, bouton de rendez-vous | M | 🟡 | Données disponibles (service dans la réponse, procédure via `GET /api/procedures?serviceId=`, S1) ; assemblage de l'écran et bouton dans le front |
| EF-18 | Retour du citoyen « bon service ? » | M | ✅ | `POST /api/demandes/{id}/retour` (`smoke-moteur.sh`) |
| EF-19 | Réorientation par l'agent, exemple d'apprentissage | M | ✅ | `PATCH /api/demandes/{id}/reorienter` (S5, `smoke-moteur.sh`) |
| EF-20 | Journalisation de chaque demande | M | ✅ | entité Demande + `HistoriqueStatut` + `/api/journal` (`smoke-journal.sh`) |
| EF-21 | Mise à jour du moteur sur demande de l'admin | S | ✅ | `POST /api/admin/moteur/reentrainer` (`smoke-moteur.sh`) |
| EF-22 | Anonymisation avant réutilisation | S | ✅ | `AnonymisationService` (e-mails, numéros, nom du citoyen) (`smoke-moteur.sh`) |
| EF-23 | Créneaux : durée, capacité, jours fermés | M | ✅ | `POST /api/creneaux/generer`, réglages du service (`smoke-rendez-vous.sh`) ; durée par défaut et jours fériés ajoutés en 10a |
| EF-24 | Calendrier de disponibilité par service | M | ✅ | `GET /api/creneaux` avec `placesRestantes` (`smoke-rendez-vous.sh`) ; l'affichage du calendrier est dans le front |
| EF-25 | Réservation en ligne, capacité temps réel, aucune double réservation | M | ✅ | S4 (créneau complet = 409), `RendezVousTest` |
| EF-26 | Modification et annulation par le citoyen avant un délai | M | ✅ | S4 ; délai `RDV_DELAI_ANNULATION_HEURES` (24 h) |
| EF-27 | Agent : confirmer, reporter, présent / absent | S | ✅ | `PATCH /api/rendez-vous/{id}/decision`, `/reporter`, `/statut` (`smoke-rendez-vous.sh`) |
| EF-28 | Récapitulatif de confirmation | M | ✅ | `GET /api/rendez-vous/{id}/recapitulatif` |
| EF-29 | Blocage de créneaux par l'agent | S | ✅ | champ `disponible` (`smoke-rendez-vous.sh`) |
| EF-30 | E-mail de confirmation de rendez-vous | M | 🟡 | Notification créée et e-mail envoyé par le Mailer Symfony (S4, `smoke-notifications.sh`) ; en développement le transport est `null://null` : un vrai serveur SMTP reste à brancher au déploiement |
| EF-31 | Rappel automatique la veille | M | ✅ | `app:rappels-rdv` à planifier chaque jour (S4) |
| EF-32 | Alerte de pièces manquantes ou de changement de procédure | S | 🟡 | Pièces manquantes (liées à un rendez-vous) et `procedure_modifiee` : faits. Non fait : alerte pièces manquantes sans rendez-vous |
| EF-33 | Notifications par SMS (simulateur) | S | 🟡 | Simulateur `GET /api/sms-simules` (`smoke-notifications.sh`) ; vraie passerelle non branchée (voulu par le cahier pendant le développement) |
| EF-34 | Centre de notifications lu / non lu | S | ✅ | `/api/notifications`, `/compteur`, `/lu` (`NotificationsTest`) |
| EF-35 | Notifications push mobiles | C | ❌ | Non fait (priorité « pourrait ») |
| EF-36 | Historique des demandes avec statut | M | ✅ | `GET /api/demandes` (statuts soumise, orientée, rendez-vous pris, traitée, clôturée, annulée) |
| EF-37 | Détail chronologique d'une demande | S | ✅ | `GET /api/demandes/{id}` avec `historique` |
| EF-38 | Note et commentaire après le rendez-vous | S | ✅ | `/api/avis` (`smoke-avis.sh`) |
| EF-39 | Statistiques des demandes par service, période, type | M | ✅ | `/api/statistiques/demandes` (S7, `smoke-stats.sh`) |
| EF-40 | Affluence par jour et par heure | S | ✅ | `/api/statistiques/affluence` |
| EF-41 | Indicateurs de satisfaction | S | ✅ | `/api/statistiques/satisfaction` |
| EF-42 | Indicateurs de performance de l'orientation | M | ✅ | `/api/statistiques/orientation` (S5, `smoke-stats.sh`) |
| EF-43 | Export PDF, Excel ou CSV | S | 🟡 | CSV (ouvrable dans Excel, BOM UTF-8, sans texte de demande ni identité) : S7. PDF et Excel natif : non faits ; si le PDF est exigé, le front peut imprimer le tableau de bord |
| EF-44 | Gestion des utilisateurs | M | ✅ | `/api/admin/utilisateurs`, `/roles` (`smoke-admin.sh`) |
| EF-45 | Journal d'audit (connexions, actions sensibles, échecs) | S | ✅ | `/api/journal` (`smoke-journal.sh`) |
| EF-46 | Paramétrage : messages, jours fériés, durée par défaut | C | ✅ | `/api/admin/parametres` (étape 10a : `ParametresTest`, `smoke-parametres.sh`) |
| EF-47 | Sauvegarde et restauration | S | ✅ | `app:sauvegarde`, `app:restauration`, `/api/admin/sauvegardes` (10a : `SauvegardeTest`, `smoke-parametres.sh`) |
| EF-48 | Application mobile ou PWA | M | 🖥️ | Hors backend : phases 2 à 4 de la feuille de route |
| EF-49 | Consultation hors connexion des fiches | C | 🖥️ | Hors backend (cache du service worker de la PWA) |

## Exigences non fonctionnelles

| ID | Exigence | État | Preuve ou reste à faire |
|---|---|---|---|
| ENF-01 | Performance : assistant ≤ 3 s pour 95 % des requêtes ; pages ≤ 3 s en 3G | 🟡 | Assistant : mesuré à 1,87 s au 95e centile avec 100 utilisateurs simultanés sur une machine à 1 cœur (`scripts/test-charge.py`). Pages en 3G : à mesurer sur le front (phase 4) et sur le serveur de démonstration |
| ENF-02 | Capacité : 100 utilisateurs simultanés | ✅ | Test de charge : 100 utilisateurs × 5 parcours, 0 erreur sur 1 500 requêtes (même machine à 1 cœur). À rejouer sur le serveur de démonstration (`python scripts/test-charge.py --url …`) |
| ENF-03 | Disponibilité 99 % | 🚀 | Dépend de l'hébergement et de la supervision (étape 19) |
| ENF-04 | Sécurité : HTTPS, hachage, jetons limités, rôles, injections, tentatives | 🟡 | Mots de passe hachés : aucun en clair en base (S9) ; jeton de 1 h ; rôles : `AccesParRoleTest` ; SQL : Doctrine et paramètres liés (aucune valeur saisie concaténée dans une requête) ; XSS : l'API ne renvoie que du JSON, l'échappement se fait dans le front ; CSRF : sans objet (jeton dans l'en-tête `Authorization`, pas de cookie) ; essais répétés : 429 après 5 échecs en 1 minute (S9) ; entrées mal formées : 400, jamais 500 (`EntreesInvalidesTest`). HTTPS : au déploiement (étape 19) |
| ENF-05 | Protection des données (loi 2014-038) | ✅ | Consentement à l'inscription, collecte limitée, droit d'accès (`GET /api/me/donnees`), suppression du compte (`DELETE /api/me`), anonymisation avant apprentissage, export statistique sans identité. Mentions légales et finalité à afficher dans le front |
| ENF-06 | Ergonomie : parcours en 5 étapes, responsive | 🖥️ | Front |
| ENF-07 | Accessibilité WCAG 2.1 AA | 🖥️ | Front (contrastes, clavier, textes alternatifs) |
| ENF-08 | Langues : français, textes externalisés pour le malagasy | 🟡 | Préférence `langue` en base ; textes du front à externaliser (phase 3) |
| ENF-09 | Faible connectivité : pages légères, cache, SMS de repli, PWA | 🟡 | SMS de repli : simulé (`canalNotification`) ; réponses JSON légères ; cache et PWA : front |
| ENF-10 | Compatibilité navigateurs, Android 8+, iOS | 🖥️ | Front |
| ENF-11 | Maintenabilité : Git, code modulaire, tests automatisés | ✅ | PHPUnit (tests critiques : accès par rôle, rendez-vous, notifications, paramètres, sauvegarde), 12 scripts de recette (dont la recette finale S1 à S9), OpenAPI validé (78 opérations), moteur Python testé (`pytest`) |
| ENF-12 | Évolutivité : front / API / moteur séparés, ajout de service sans code | ✅ | Trois composants distincts ; services et procédures ajoutés par l'API (S1, S6) |
| ENF-13 | Fiabilité : date de mise à jour, source, mention « indicative » | ✅ | Champs `misAJourLe`, `source`, `avertissement` sur chaque procédure (S1, S6) |
| ENF-14 | Traçabilité des actions sensibles et décisions d'orientation | ✅ | Journal d'audit (`smoke-journal.sh`) + `HistoriqueStatut` des demandes + service recommandé, confiance et service final enregistrés |
| ENF-15 | Sauvegarde quotidienne, restauration testée | ✅ | `app:sauvegarde` à planifier ; restauration vérifiée dans une base à part, comptes identiques (`smoke-parametres.sh`, `SauvegardeTest`) |

## Exigences du moteur d'orientation (chapitre 7)

| Cible | Mesure | État |
|---|---|---|
| Bon service en 1re proposition ≥ 80 % | 97,1 % (207 demandes de test) | ✅ |
| Bon service parmi les 3 premiers ≥ 95 % | 100 % | ✅ |

Réserve à dire telle quelle au jury : le jeu de test (`moteur/data/test.txt`, figé, jamais utilisé pour entraîner) a été écrit par l'équipe, dans le même style que les exemples d'entraînement. Les chiffres sont donc plus favorables que ce qu'on obtiendra avec des demandes réelles de personnes extérieures. Le moteur écarte 13 des 20 demandes hors sujet (65 %) ; la cible sur ce point n'est pas chiffrée dans le cahier. Pour une mesure honnête, ajouter des demandes de testeurs extérieurs à `test.txt` puis lancer `python evaluer.py`.

## Mesures de charge (ENF-01, ENF-02)

Conditions : machine à 1 cœur, serveur PHP en mode production (16 processus), moteur Python (uvicorn, 1 processus), PostgreSQL local, client de test sur la même machine (donc un cas défavorable). 100 utilisateurs simultanés, chacun enchaînant 5 parcours (assistant, catalogue des services, procédures) avec une pause de lecture d'environ 0,3 s.

| Requête | Nombre | Erreurs | Moyenne | 95e centile | Maximum |
|---|---|---|---|---|---|
| Assistant (`POST /api/orientation/tester`) | 500 | 0 | 1,55 s | 1,87 s | 2,19 s |
| Catalogue (`GET /api/services`) | 500 | 0 | 1,51 s | 1,78 s | 1,92 s |
| Procédures (`GET /api/procedures`) | 500 | 0 | 1,60 s | 1,93 s | 2,05 s |

Les temps sont presque égaux d'une requête à l'autre : l'unique cœur est saturé et les requêtes font la queue. Avec plusieurs cœurs les temps baisseront. Ces chiffres ne remplacent pas une mesure sur le serveur de démonstration (même commande avec `--url`).
