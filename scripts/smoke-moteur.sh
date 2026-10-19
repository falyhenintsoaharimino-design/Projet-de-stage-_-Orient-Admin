#!/usr/bin/env bash
# Recette de l'étape 5bis partie B : intégration du moteur Python, retour citoyen, exemples, réentraînement.
# Usage : bash scripts/smoke-moteur.sh                 (moteur Python LANCÉ sur le port 8001)
#         REPLI=1 bash scripts/smoke-moteur.sh         (moteur Python ARRÊTÉ : teste le repli sur les mots-clés)
# Prérequis : php bin/console doctrine:migrations:migrate déjà fait, symfony serve lancé, app:seed-demo chargé,
#   et pour le mode normal : cd moteur && python -m uvicorn app:app --port 8001
#   (si MOTEUR_TOKEN est défini côté Python, mettre la même valeur dans .env.local : MOTEUR_TOKEN=...).
# Le script supprime les exemples qu'il crée ; il réentraîne ensuite le moteur (sans les exemples de test).

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
ok()  { echo "OK      $1"; NB_OK=$((NB_OK+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; NB_KO=$((NB_KO+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) $BODY"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }

jget() {
  printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (explode(".", $argv[1]) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) { exit(0); } $d = $d[$k]; }
    echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' -- "$2"
}
api() {
  local args=(-s -X "$1" "$BASE$2" -H 'Content-Type: application/json' -w $'\n%{http_code}')
  [ -n "$3" ] && args+=(-H "Authorization: Bearer $3")
  [ -n "$4" ] && args+=(-d "$4")
  local out; out=$(curl "${args[@]}")
  CODE="${out##*$'\n'}"; BODY="${out%$'\n'*}"
}
login() { api POST /api/login_check "" "{\"email\":\"$1\",\"password\":\"$PASS\"}"; jget "$BODY" token; }
total_exemples() { api GET /api/admin/moteur "$ADM"; jget "$BODY" exemples.total; }
delta() { egal "$1" "$(( $3 - $2 ))" "$4"; }
NOM_PASSEPORT="Service des passeports"

echo "== Connexion =="
CIT=$(login test@test.com);  [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com); [ -n "$AGT" ] && ok "login agent"   || ko "login agent" "$BODY"
ADM=$(login admin@test.com); [ -n "$ADM" ] && ok "login admin"   || ko "login admin" "$BODY"
RESP=$(login responsable@test.com); [ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
{ [ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$ADM" ] || [ -z "$RESP" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }

echo "== 1. Accès à l'administration du moteur =="
for chemin in "" "/exemples"; do
  api GET "/api/admin/moteur$chemin" "";      code_attendu "GET moteur$chemin sans jeton -> 401" 401
  api GET "/api/admin/moteur$chemin" "$CIT";  code_attendu "GET moteur$chemin citoyen -> 403" 403
  api GET "/api/admin/moteur$chemin" "$AGT";  code_attendu "GET moteur$chemin agent -> 403" 403
  api GET "/api/admin/moteur$chemin" "$RESP"; code_attendu "GET moteur$chemin responsable -> 403" 403
  api GET "/api/admin/moteur$chemin" "$ADM";  code_attendu "GET moteur$chemin admin -> 200" 200
done
api POST /api/admin/moteur/reentrainer "$AGT"; code_attendu "reentrainer agent -> 403" 403
api POST /api/admin/moteur/reentrainer "$CIT"; code_attendu "reentrainer citoyen -> 403" 403
api DELETE /api/admin/moteur/exemples/1 "$AGT"; code_attendu "supprimer exemple agent -> 403" 403
api DELETE /api/admin/moteur/exemples/99999999 "$ADM"; code_attendu "supprimer exemple inconnu -> 404" 404

echo "== 2. Orientation =="
api POST /api/orientation/tester "" '{"texte":""}'; code_attendu "texte vide -> 400" 400
if [ -n "$REPLI" ]; then
  api POST /api/orientation/tester "" '{"texte":"je veux un acte de naissance"}'; code_attendu "orientation (repli) -> 200" 200
  egal "source = mots_cles (moteur Python arrêté)" "$(jget "$BODY" source)" mots_cles
  [ -n "$(jget "$BODY" service.id)" ] && ok "le repli oriente encore (acte de naissance)" || ko "repli" "$BODY"
  api GET /api/admin/moteur "$ADM"; egal "admin : moteur indisponible" "$(jget "$BODY" moteur.disponible)" false
  api POST /api/admin/moteur/reentrainer "$ADM"; code_attendu "reentrainer sans moteur -> 502" 502
  echo; echo "Résultat (mode repli) : $NB_OK OK, $NB_KO ÉCHEC"; [ "$NB_KO" = "0" ]; exit
fi
api GET /api/admin/moteur "$ADM"
egal "moteur Python joignable (lancé sur :8001 ? jeton identique ?)" "$(jget "$BODY" moteur.disponible)" true
[ -n "$(jget "$BODY" moteur.sante.version)" ] && ok "santé : version du modèle présente" || ko "santé" "$BODY"
api POST /api/orientation/tester "" '{"texte":"je veux renouveler mon pasport"}'; code_attendu "orientation faute de frappe -> 200" 200
egal "source = ia" "$(jget "$BODY" source)" ia
egal "pasport -> $NOM_PASSEPORT" "$(jget "$BODY" service.nom)" "$NOM_PASSEPORT"
[ "$(php -r 'echo (float)$argv[1] >= 0.30 ? "oui":"non";' -- "$(jget "$BODY" confiance)")" = oui ] && ok "confiance >= seuil" || ko "confiance" "$BODY"
[ -n "$(jget "$BODY" alternatives.0.service.id)" ] && ok "au moins une alternative" || ko "alternatives" "$BODY"
api POST /api/orientation/tester "" '{"texte":"quel temps fera-t-il demain"}'
egal "hors sujet : source ia" "$(jget "$BODY" source)" ia
egal "hors sujet : aucun service recommandé" "$(jget "$BODY" service)" null
[ -n "$(jget "$BODY" confiance)" ] && ok "hors sujet : confiance conservée" || ko "hors sujet" "$BODY"

echo "== 3. Retour du citoyen (EF-18) =="
EX0=$(total_exemples)
api POST /api/demandes "$CIT" '{"texte":"Je veux renouveler mon pasport, contact : jean.test@mail.mg ou 034 12 345 67"}'; code_attendu "demande A (moteur)" 201
DA=$(jget "$BODY" id); egal "A orientée vers $NOM_PASSEPORT" "$(jget "$BODY" serviceRecommande.nom)" "$NOM_PASSEPORT"
api POST /api/demandes "$CIT" '{"texte":"quel temps fera-t-il demain"}'; code_attendu "demande B (hors sujet)" 201
DB=$(jget "$BODY" id); egal "B : statut soumise" "$(jget "$BODY" statut)" soumise
api POST "/api/demandes/$DA/retour" "" '{"pertinent":true}'; code_attendu "retour sans jeton -> 401" 401
api POST "/api/demandes/$DA/retour" "$AGT" '{"pertinent":true}'; code_attendu "retour agent -> 403" 403
api POST "/api/demandes/$DA/retour" "$CIT" '{}';  code_attendu "retour sans pertinent -> 422" 422
api POST "/api/demandes/$DA/retour" "$CIT" '{"pertinent":"oui"}'; code_attendu "pertinent non booléen -> 422" 422
api POST "/api/demandes/99999999/retour" "$CIT" '{"pertinent":true}'; code_attendu "demande inconnue -> 404" 404
api POST "/api/demandes/$DB/retour" "$CIT" '{"pertinent":true}'; code_attendu "retour sur demande sans recommandation -> 409" 409
api POST "/api/demandes/$DA/retour" "$CIT" '{"pertinent":true}'; code_attendu "citoyen : « oui, bon service »" 200
egal "retourPertinent = true" "$(jget "$BODY" retourPertinent)" true
delta "un exemple ajouté par le « oui »" "$EX0" "$(total_exemples)" 1
api POST "/api/demandes/$DA/retour" "$CIT" '{"pertinent":true}'; code_attendu "second « oui » (idempotent)" 200
delta "toujours un seul exemple pour la demande" "$EX0" "$(total_exemples)" 1
api GET "/api/admin/moteur/exemples?origine=retour_citoyen&limite=5" "$ADM"
EXA=$(jget "$BODY" exemples.0.id)
egal "exemple : service = $NOM_PASSEPORT" "$(jget "$BODY" exemples.0.service.nom)" "$NOM_PASSEPORT"
egal "exemple : demande liée" "$(jget "$BODY" exemples.0.demandeId)" "$DA"
TEXTE_EX=$(jget "$BODY" exemples.0.texte)
case "$TEXTE_EX" in *jean.test*|*"345 67"*) ko "anonymisation (EF-22)" "données personnelles conservées : $TEXTE_EX";; *) ok "anonymisation : e-mail et téléphone retirés";; esac
case "$TEXTE_EX" in *"[email]"*"[numéro]"*) ok "anonymisation : marqueurs [email] et [numéro] présents";; *) ko "marqueurs d'anonymisation" "$TEXTE_EX";; esac
api POST "/api/demandes/$DA/retour" "$CIT" '{"pertinent":false}'; code_attendu "citoyen : « non »" 200
egal "retourPertinent = false" "$(jget "$BODY" retourPertinent)" false
delta "le « non » retire l'exemple du « oui »" "$EX0" "$(total_exemples)" 0
EXA=""

echo "== 4. Réorientation par l'agent -> exemple (EF-19) =="
api GET /api/services "$CIT"; SERV_AUTRE=$(php -r '$d=json_decode($argv[1],true); $l=$d["services"]??$d; foreach($l as $s){ if(($s["nom"]??"")!==$argv[2]){ echo $s["id"]; break; } }' -- "$BODY" "$NOM_PASSEPORT")
[ -n "$SERV_AUTRE" ] && ok "service de réorientation choisi (id $SERV_AUTRE)" || ko "liste des services" "$BODY"
api PATCH "/api/demandes/$DB/reorienter" "$AGT" "{\"serviceId\":$SERV_AUTRE}"; code_attendu "agent oriente B à la main" 200
delta "réorientation : un exemple de plus" "$EX0" "$(total_exemples)" 1
api PATCH "/api/demandes/$DB/reorienter" "$AGT" "{\"serviceId\":$SERV_AUTRE}"; code_attendu "agent réoriente B une 2e fois" 200
delta "toujours un seul exemple pour B" "$EX0" "$(total_exemples)" 1
api GET "/api/admin/moteur/exemples?origine=reorientation&limite=5" "$ADM"
EXB=$(jget "$BODY" exemples.0.id); egal "exemple B : origine reorientation, demande liée" "$(jget "$BODY" exemples.0.demandeId)" "$DB"
api POST "/api/demandes/$DB/retour" "$CIT" '{"pertinent":true}'; code_attendu "B n'a pas de recommandation : retour -> 409" 409

echo "== 5. Réentraînement par l'admin (EF-21) =="
api GET /api/admin/moteur "$ADM"; V0=$(jget "$BODY" moteur.sante.version)
api POST /api/admin/moteur/reentrainer "$ADM"; code_attendu "réentraînement -> 200" 200
NBSUP=$(jget "$BODY" nbSupplementaires); egal "nbSupplementaires = nombre d'exemples en base" "$NBSUP" "$(total_exemples)"
[ "$(jget "$BODY" version)" -gt "$V0" ] 2>/dev/null && ok "version du modèle incrémentée ($V0 -> $(jget "$BODY" version))" || ko "version" "$BODY"
api POST /api/orientation/tester "" '{"texte":"je veux renouveler mon pasport"}'
egal "après réentraînement : toujours $NOM_PASSEPORT" "$(jget "$BODY" service.nom)" "$NOM_PASSEPORT"
api GET /api/admin/moteur "$ADM"
[ -n "$(jget "$BODY" moteur.metriques.precisionPremiere)" ] && ok "métriques disponibles (python evaluer.py déjà lancé)" || echo "INFO    pas de métriques : lance « python evaluer.py » dans moteur/"

echo "== 6. Nettoyage =="
for e in $EXA $EXB; do [ -n "$e" ] && { api DELETE "/api/admin/moteur/exemples/$e" "$ADM"; code_attendu "exemple $e supprimé" 200; }; done
delta "exemples revenus au niveau initial" "$EX0" "$(total_exemples)" 0
api POST /api/admin/moteur/reentrainer "$ADM"; code_attendu "réentraînement final (sans les exemples de test)" 200

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
