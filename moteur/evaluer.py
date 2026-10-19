"""Mesure le moteur sur le jeu de test figé (data/test.txt) -> metriques.json.

Usage : python evaluer.py [seuil]      (seuil par défaut : 0.30)
Le jeu de test n'est jamais utilisé pour l'entraînement ni pour choisir le seuil.
"""
import json
import sys
from datetime import datetime, timezone

from sklearn.metrics import classification_report, confusion_matrix

from moteur import DOSSIER, SERVICES, Moteur, exemples_de_base, lire_sections


def evaluer(seuil: float = 0.30) -> dict:
    moteur = Moteur()
    moteur.entrainer(exemples_de_base())
    test = lire_sections(DOSSIER / "data" / "test.txt")
    cas = [(t, SERVICES[c]) for c, ts in test.items() if c in SERVICES for t in ts]
    hors_sujet = test.get("hors_sujet", [])

    vrais, predits, top3_ok, erreurs = [], [], 0, []
    for texte, attendu in cas:
        r = moteur.orienter(texte, seuil)
        vrais.append(attendu)
        predits.append(r["service"])
        if attendu in [r["service"]] + [a["service"] for a in r["alternatives"]]:
            top3_ok += 1
        if r["service"] != attendu:
            erreurs.append({"texte": texte, "attendu": attendu, "obtenu": r["service"], "confiance": r["confiance"]})

    labels = sorted(set(vrais))
    rapport = classification_report(vrais, predits, labels=labels, output_dict=True, zero_division=0)
    rejetes = sum(1 for t in hors_sujet if not moteur.orienter(t, seuil)["compris"])
    sous_seuil = sum(1 for t, a in cas if not moteur.orienter(t, seuil)["compris"])

    return {
        "date": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "seuil": seuil,
        "nbExemplesEntrainement": moteur.nb_exemples,
        "nbDemandesTest": len(cas),
        "precisionPremiere": round(sum(v == p for v, p in zip(vrais, predits)) / len(cas), 4),
        "precisionTop3": round(top3_ok / len(cas), 4),
        "demandesValidesSousLeSeuil": sous_seuil,
        "horsSujet": {"total": len(hors_sujet), "rejetes": rejetes, "tauxRejet": round(rejetes / max(1, len(hors_sujet)), 4)},
        "parService": {l: {k: round(rapport[l][k], 3) for k in ("precision", "recall", "f1-score", "support")} for l in labels},
        "f1Macro": round(rapport["macro avg"]["f1-score"], 4),
        "matriceConfusion": {"labels": labels, "valeurs": confusion_matrix(vrais, predits, labels=labels).tolist()},
        "erreurs": erreurs,
    }


if __name__ == "__main__":
    seuil = float(sys.argv[1]) if len(sys.argv) > 1 else 0.30
    m = evaluer(seuil)
    (DOSSIER / "metriques.json").write_text(json.dumps(m, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Seuil {seuil} | entraînement : {m['nbExemplesEntrainement']} | test : {m['nbDemandesTest']}")
    print(f"Précision 1re proposition : {m['precisionPremiere']:.1%} (cible ≥ 80 %)")
    print(f"Précision top 3           : {m['precisionTop3']:.1%} (cible ≥ 95 %)")
    print(f"F1 macro                  : {m['f1Macro']:.3f}")
    print(f"Hors sujet rejetés        : {m['horsSujet']['rejetes']}/{m['horsSujet']['total']}")
    print(f"Demandes valides sous le seuil : {m['demandesValidesSousLeSeuil']}/{m['nbDemandesTest']}")
    for e in m["erreurs"]:
        print(f"  erreur : « {e['texte']} » attendu={e['attendu']} obtenu={e['obtenu']} ({e['confiance']})")
