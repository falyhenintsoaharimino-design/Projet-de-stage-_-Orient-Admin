import os
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
os.environ["MOTEUR_TOKEN"] = "secret-test"

import app as appli  # noqa: E402
from moteur import SERVICES, exemples_de_base, lire_sections, normaliser  # noqa: E402

H = {"X-Moteur-Token": "secret-test"}


@pytest.fixture(autouse=True)
def modele_propre(tmp_path, monkeypatch):
    monkeypatch.setattr(appli, "MODELE", tmp_path / "modele.joblib")  # ne touche pas au vrai modèle
    appli.moteur.entrainer(exemples_de_base())


client = TestClient(appli.app)


def test_normalisation():
    assert normaliser("  Bulletin N°3 d'Acte déçu ! ") == "bulletin n 3 d acte decu"


def test_donnees_coherentes():
    base = lire_sections(Path(appli.DOSSIER) / "data" / "exemples.txt")
    test = lire_sections(Path(appli.DOSSIER) / "data" / "test.txt")
    assert set(base) == set(SERVICES)
    assert all(len(v) >= 30 for v in base.values())
    assert sum(len(v) for k, v in test.items() if k in SERVICES) >= 200
    # jamais d'exemple de test dans l'entraînement
    entrainement = {normaliser(t) for v in base.values() for t in v}
    assert not [t for k, v in test.items() for t in v if normaliser(t) in entrainement]


def test_orientation_et_alternatives():
    r = client.post("/orienter", json={"texte": "je veux un pasport pour voyager"}, headers=H).json()
    assert r["service"] == SERVICES["passeport"] and r["compris"] is True
    assert len(r["alternatives"]) == 2 and r["confiance"] > r["alternatives"][0]["confiance"]


def test_seuil_parametrable():
    t = {"texte": "carte grise"}
    assert client.post("/orienter", json={**t, "seuil": 0.0}, headers=H).json()["compris"] is True
    assert client.post("/orienter", json={**t, "seuil": 1.0}, headers=H).json()["compris"] is False


def test_hors_sujet_peu_confiant():
    r = client.post("/orienter", json={"texte": "asdfgh qwerty"}, headers=H).json()
    assert r["confiance"] < 0.5


def test_validation_et_jeton():
    assert client.post("/orienter", json={"texte": ""}, headers=H).status_code == 422
    assert client.post("/orienter", json={"texte": "!!!"}, headers=H).status_code == 422
    assert client.post("/orienter", json={"texte": "cin"}).status_code == 401
    assert client.post("/orienter", json={"texte": "cin"}, headers={"X-Moteur-Token": "faux"}).status_code == 401
    assert client.post("/orienter", json={"texte": "cin", "seuil": 2}, headers=H).status_code == 422


def test_reentrainement_apprend():
    nom = SERVICES["fiscal"]
    avant = client.post("/orienter", json={"texte": "zorglub machin"}, headers=H).json()
    ex = [{"texte": "zorglub machin", "service": nom}] * 6 + [{"texte": "zorglub truc", "service": nom}] * 4
    r = client.post("/reentrainer", json={"exemples": ex}, headers=H).json()
    assert r["nbSupplementaires"] == 10 and r["version"] > avant["version"]
    apres = client.post("/orienter", json={"texte": "zorglub machin"}, headers=H).json()
    assert apres["service"] == nom and apres["confiance"] > avant["confiance"]
    assert (appli.MODELE).exists()
