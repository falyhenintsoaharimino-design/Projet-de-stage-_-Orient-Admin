#!/usr/bin/env python3
"""Test de charge de l'étape 10b : ENF-01 (assistant en 3 s ou moins pour 95 % des requêtes) et
ENF-02 (au moins 100 utilisateurs simultanés).

Usage (à la racine du projet, API et moteur Python lancés) :
    python scripts/test-charge.py                          # 100 utilisateurs, 10 parcours chacun
    python scripts/test-charge.py --utilisateurs 50 --parcours 5 --url http://127.0.0.1:8000

Chaque utilisateur virtuel démarre au même instant (barrière), puis enchaîne des parcours de visiteur :
  1. assistant : POST /api/orientation/tester avec une demande tirée de moteur/data/test.txt  (mesure ENF-01) ;
  2. catalogue : GET /api/services puis GET /api/procedures ;
  avec une courte pause « de lecture » entre les requêtes (--pause, 0,3 s par défaut).
Aucune écriture en base : l'assistant de test ne crée pas de demande. Pas de dépendance : bibliothèque standard seulement.

Verdict : ENF-01 réussi si 95 % des requêtes de l'assistant répondent en --seuil secondes (3 par défaut) ou moins ;
ENF-02 réussi si les N utilisateurs finissent leurs parcours avec au plus 1 % d'erreurs (toutes requêtes confondues).
Code de sortie 0 si les deux sont réussis, 1 sinon. Les temps dépendent de la machine et de la configuration du serveur
(serveur de développement = lent, mode prod avec plusieurs processus = représentatif) : toujours indiquer ces conditions.
"""
import argparse
import json
import os
import random
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request


def charger_demandes(chemin):
    """Demandes du jeu de test du moteur (lignes « service | texte » ou texte seul), hors lignes vides et commentaires."""
    demandes = []
    try:
        with open(chemin, encoding="utf-8") as f:
            for ligne in f:
                ligne = ligne.strip()
                if not ligne or ligne.startswith("#"):
                    continue
                texte = ligne.split("|", 1)[-1].strip()
                if len(texte) >= 3:
                    demandes.append(texte)
    except OSError:
        pass
    return demandes or ["je veux refaire ma carte d'identité", "acte de naissance", "permis de conduire"]


def requete(url, corps=None, delai=30):
    """Renvoie (durée en secondes, code HTTP ou 0 si erreur réseau)."""
    donnees = json.dumps(corps).encode("utf-8") if corps is not None else None
    req = urllib.request.Request(url, data=donnees, method="POST" if corps is not None else "GET",
                                 headers={"Content-Type": "application/json", "Accept": "application/json"})
    debut = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=delai) as r:
            r.read()
            code = r.status
    except urllib.error.HTTPError as e:
        e.read()
        code = e.code
    except Exception:
        code = 0
    return time.perf_counter() - debut, code


def centile(valeurs, p):
    if not valeurs:
        return float("nan")
    tri = sorted(valeurs)
    return tri[min(len(tri) - 1, max(0, int(round(p / 100 * len(tri) + 0.5)) - 1))]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", default="http://127.0.0.1:8000")
    ap.add_argument("--utilisateurs", type=int, default=100)
    ap.add_argument("--parcours", type=int, default=10, help="parcours par utilisateur")
    ap.add_argument("--pause", type=float, default=0.3, help="pause entre deux requêtes (s)")
    ap.add_argument("--seuil", type=float, default=3.0, help="temps maximal accepté pour l'assistant (s)")
    ap.add_argument("--demandes", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "moteur", "data", "test.txt"))
    a = ap.parse_args()

    demandes = charger_demandes(a.demandes)
    mesures = {"assistant": [], "services": [], "procedures": []}
    codes = {"assistant": [], "services": [], "procedures": []}
    verrou = threading.Lock()
    barriere = threading.Barrier(a.utilisateurs + 1)
    termines = []

    def utilisateur(numero):
        hasard = random.Random(numero)
        locales = []
        try:
            barriere.wait(timeout=60)
        except threading.BrokenBarrierError:
            return
        for _ in range(a.parcours):
            for nom, chemin, corps in (
                ("assistant", "/api/orientation/tester", {"texte": hasard.choice(demandes)}),
                ("services", "/api/services", None),
                ("procedures", "/api/procedures", None),
            ):
                duree, code = requete(a.url + chemin, corps)
                locales.append((nom, duree, code))
                time.sleep(a.pause * hasard.uniform(0.5, 1.5))
        with verrou:
            for nom, duree, code in locales:
                mesures[nom].append(duree)
                codes[nom].append(code)
            termines.append(numero)

    # Contrôle préalable : l'API répond-elle ?
    _, code = requete(a.url + "/api/services")
    if code != 200:
        print(f"L'API ne répond pas correctement sur {a.url} (code {code}) : lancez le serveur avant le test.")
        return 2
    _, code = requete(a.url + "/api/orientation/tester", {"texte": "carte d'identité"})
    if code != 200:
        print(f"L'assistant ne répond pas correctement (code {code}).")
        return 2

    fils = [threading.Thread(target=utilisateur, args=(n,), daemon=True) for n in range(a.utilisateurs)]
    for f in fils:
        f.start()
    barriere.wait()
    debut = time.perf_counter()
    for f in fils:
        f.join()
    total = time.perf_counter() - debut

    print(f"\nTest de charge : {a.utilisateurs} utilisateurs simultanés, {a.parcours} parcours chacun, durée {total:.1f} s")
    print(f"Serveur : {a.url}\n")
    print(f"{'requête':<12}{'nombre':>8}{'erreurs':>9}{'moy.':>8}{'méd.':>8}{'p95':>8}{'p99':>8}{'max':>8}{'≤seuil':>9}")
    toutes = erreurs = 0
    for nom in ("assistant", "services", "procedures"):
        d, c = mesures[nom], codes[nom]
        if not d:
            continue
        err = sum(1 for x in c if x != 200)
        toutes += len(d)
        erreurs += err
        dans = 100 * sum(1 for x in d if x <= a.seuil) / len(d)
        print(f"{nom:<12}{len(d):>8}{err:>9}{statistics.mean(d):>8.2f}{statistics.median(d):>8.2f}"
              f"{centile(d, 95):>8.2f}{centile(d, 99):>8.2f}{max(d):>8.2f}{dans:>8.1f}%")

    d = mesures["assistant"]
    part = (100 * sum(1 for x in d if x <= a.seuil) / len(d)) if d else 0.0
    enf01 = bool(d) and part >= 95.0
    taux_err = 100 * erreurs / toutes if toutes else 100.0
    enf02 = len(termines) == a.utilisateurs and taux_err <= 1.0
    print(f"\nENF-01 assistant ≤ {a.seuil:g} s pour 95 % des requêtes : {part:.1f} % -> {'OK' if enf01 else 'ÉCHEC'}")
    print(f"ENF-02 {a.utilisateurs} utilisateurs simultanés ({len(termines)} ont fini, {taux_err:.2f} % d'erreurs) -> {'OK' if enf02 else 'ÉCHEC'}")
    return 0 if (enf01 and enf02) else 1


if __name__ == "__main__":
    sys.exit(main())
