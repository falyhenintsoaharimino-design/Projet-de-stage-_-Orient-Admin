"""API HTTP du moteur d'orientation (FastAPI). Documentation automatique : /docs et /openapi.json

Lancer :  uvicorn app:app --port 8001
Variables d'environnement (facultatives) :
  MOTEUR_TOKEN   secret partagé avec Symfony ; s'il est défini, l'en-tête X-Moteur-Token est exigé
  MOTEUR_SEUIL   seuil de confiance par défaut (défaut 0.30)
"""
import hmac
import json
import os
from pathlib import Path

import joblib
from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from evaluer import DOSSIER
from moteur import Moteur, exemples_de_base, normaliser

MODELE = Path(DOSSIER) / "modele.joblib"
SEUIL_DEFAUT = float(os.environ.get("MOTEUR_SEUIL", "0.30"))

moteur = Moteur()


def _charger() -> None:
    if MODELE.exists():
        etat = joblib.load(MODELE)
        moteur.modele, moteur.nb_exemples, moteur.version = etat["modele"], etat["nb"], etat["version"]
    else:
        moteur.entrainer(exemples_de_base())


_charger()


def verifier_jeton(x_moteur_token: str | None = Header(default=None)) -> None:
    attendu = os.environ.get("MOTEUR_TOKEN")
    if attendu and not hmac.compare_digest(x_moteur_token or "", attendu):
        raise HTTPException(status_code=401, detail="Jeton invalide")


app = FastAPI(title="Moteur d'orientation Orient-Admin", version="1.0", dependencies=[Depends(verifier_jeton)])


class DemandeOrientation(BaseModel):
    texte: str = Field(min_length=1, max_length=1000)
    seuil: float | None = Field(default=None, ge=0, le=1, description="Seuil de confiance (défaut : MOTEUR_SEUIL)")


class Exemple(BaseModel):
    texte: str = Field(min_length=1, max_length=1000)
    service: str = Field(min_length=1, max_length=150, description="Nom exact du service")


class DemandeReentrainement(BaseModel):
    exemples: list[Exemple] = Field(default_factory=list, max_length=50000)


@app.get("/sante")
def sante() -> dict:
    return {"statut": "ok", "version": moteur.version, "nbExemples": moteur.nb_exemples}


@app.post("/orienter")
def orienter(d: DemandeOrientation) -> dict:
    if not normaliser(d.texte):
        raise HTTPException(status_code=422, detail="Texte vide après nettoyage")
    return moteur.orienter(d.texte, d.seuil if d.seuil is not None else SEUIL_DEFAUT)


@app.post("/reentrainer")
def reentrainer(d: DemandeReentrainement) -> dict:
    """Réentraîne sur les exemples de base + ceux fournis (validés et anonymisés par Symfony)."""
    exemples = exemples_de_base() + [(e.texte, e.service) for e in d.exemples]
    try:
        moteur.entrainer(exemples)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    joblib.dump({"modele": moteur.modele, "nb": moteur.nb_exemples, "version": moteur.version}, MODELE)
    return {"version": moteur.version, "nbExemples": moteur.nb_exemples, "nbSupplementaires": len(d.exemples)}


@app.get("/metriques")
def metriques() -> dict:
    """Dernières métriques calculées par `python evaluer.py` (jeu de test figé)."""
    fichier = Path(DOSSIER) / "metriques.json"
    if not fichier.exists():
        raise HTTPException(status_code=404, detail="Aucune évaluation : lancer python evaluer.py")
    return json.loads(fichier.read_text(encoding="utf-8"))
