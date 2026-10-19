"""Moteur d'orientation : TF-IDF (caractères + mots) + régression logistique.

Les n-grammes de caractères rendent le moteur tolérant aux fautes de frappe
(« pasport », « naisance »). La confiance est la probabilité de la classe
prédite ; le seuil de décision n'est PAS appliqué ici : il est fourni à chaque
appel (paramétrable côté Symfony), le moteur indique seulement si la
confiance le dépasse (champ `compris`).
"""
import json
import re
import unicodedata
from pathlib import Path

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import FeatureUnion, Pipeline

DOSSIER = Path(__file__).parent
SERVICES = json.loads((DOSSIER / "services.json").read_text(encoding="utf-8"))


def normaliser(texte: str) -> str:
    """Minuscules, sans accents ni ponctuation (« n°3 » -> « n 3 »)."""
    texte = unicodedata.normalize("NFKD", texte.lower())
    texte = "".join(c for c in texte if not unicodedata.combining(c))
    texte = re.sub(r"[^a-z0-9\s]", " ", texte)
    return re.sub(r"\s+", " ", texte).strip()


def lire_sections(chemin: Path) -> dict[str, list[str]]:
    """Lit un fichier à sections « # cle » : {cle: [demandes]} (les autres # sont des commentaires)."""
    sections: dict[str, list[str]] = {}
    courante = None
    for ligne in chemin.read_text(encoding="utf-8").splitlines():
        ligne = ligne.strip()
        if not ligne:
            continue
        if ligne.startswith("# ") and " " not in ligne[2:].strip() and ligne[2:].strip().replace("_", "").isalpha():
            courante = ligne[2:].strip()
            sections[courante] = []
        elif ligne.startswith("#"):
            continue
        elif courante:
            sections[courante].append(ligne)
    return sections


def exemples_de_base() -> list[tuple[str, str]]:
    """[(texte, nom du service)] depuis data/exemples.txt."""
    sections = lire_sections(DOSSIER / "data" / "exemples.txt")
    return [(t, SERVICES[cle]) for cle, textes in sections.items() if cle in SERVICES for t in textes]


def _pipeline() -> Pipeline:
    return Pipeline([
        ("tfidf", FeatureUnion([
            ("car", TfidfVectorizer(analyzer="char_wb", ngram_range=(2, 5), sublinear_tf=True, preprocessor=normaliser)),
            ("mots", TfidfVectorizer(analyzer="word", ngram_range=(1, 2), sublinear_tf=True, preprocessor=normaliser)),
        ])),
        ("clf", LogisticRegression(C=20, max_iter=2000)),
    ])


class Moteur:
    def __init__(self) -> None:
        self.modele: Pipeline | None = None
        self.nb_exemples = 0
        self.version = 0

    def entrainer(self, exemples: list[tuple[str, str]]) -> None:
        if len({s for _, s in exemples}) < 2:
            raise ValueError("Il faut des exemples pour au moins 2 services")
        textes, services = zip(*exemples)
        modele = _pipeline()
        modele.fit(list(textes), list(services))
        self.modele, self.nb_exemples = modele, len(exemples)
        self.version += 1

    def orienter(self, texte: str, seuil: float = 0.30, nb: int = 3) -> dict:
        if self.modele is None:
            raise RuntimeError("Moteur non entraîné")
        probas = self.modele.predict_proba([texte])[0]
        classes = self.modele.classes_
        ordre = sorted(range(len(classes)), key=lambda i: probas[i], reverse=True)[:nb]
        top = [{"service": str(classes[i]), "confiance": round(float(probas[i]), 4)} for i in ordre]
        meilleur = top[0]
        return {
            "service": meilleur["service"],
            "confiance": meilleur["confiance"],
            "compris": meilleur["confiance"] >= seuil,
            "seuil": seuil,
            "alternatives": top[1:],
            "version": self.version,
        }
