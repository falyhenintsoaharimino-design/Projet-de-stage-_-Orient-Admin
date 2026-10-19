# Moteur d'orientation (service Python)

Service séparé du site Symfony (cahier des charges, chapitre 7) : il reçoit le texte libre d'un citoyen
et renvoie le service recommandé, un score de confiance et deux alternatives.

- Approche : TF-IDF de caractères (tolérant aux fautes de frappe) + de mots, puis régression logistique (scikit-learn).
- Confiance = probabilité de la classe prédite. Le **seuil** est fourni à chaque appel (`seuil`, ou variable `MOTEUR_SEUIL`,
  défaut 0.30, valeur provisoire à valider avec l'encadrant) ; en dessous, `compris` vaut `false` (message de repli, EF-16).
- Les services sont identifiés par leur **nom exact** (voir `services.json`, identique à `app:seed-demo`).

## Installation et lancement (Windows, Git Bash)

```bash
cd moteur
python -m venv .venv
source .venv/Scripts/activate        # PowerShell : .venv\Scripts\Activate.ps1
pip install -r requirements.txt
python -m pytest -q tests            # 7 tests
python evaluer.py                    # métriques sur le jeu de test -> metriques.json
export MOTEUR_TOKEN=un-secret        # partagé avec Symfony (facultatif en local)
python -m uvicorn app:app --port 8001   # documentation interactive : http://127.0.0.1:8001/docs
```

## Points d'accès

| Méthode | Chemin | Rôle |
|---|---|---|
| GET | `/sante` | état, version du modèle, nombre d'exemples |
| POST | `/orienter` | `{"texte": "...", "seuil": 0.3}` -> service, confiance, compris, alternatives |
| POST | `/reentrainer` | `{"exemples": [{"texte","service"}]}` : base + exemples fournis (déjà anonymisés), modèle sauvegardé |
| GET | `/metriques` | dernières métriques de `evaluer.py` |

Si `MOTEUR_TOKEN` est défini, l'en-tête `X-Moteur-Token` est obligatoire.

## Données et évaluation

- `data/exemples.txt` : 30 exemples par service (entraînement).
- `data/test.txt` : 23 demandes par service (207) + 20 hors sujet, **figées**, jamais utilisées pour entraîner ni régler.
- Limite à indiquer dans le mémoire : ces deux jeux ont été rédigés par la même personne ; les scores obtenus sont donc
  optimistes. Pour une évaluation honnête, remplacer/compléter `test.txt` par des demandes réelles de personnes extérieures
  (famille, camarades) avant de publier les métriques.
