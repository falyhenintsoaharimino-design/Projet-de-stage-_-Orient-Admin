#!/usr/bin/env bash
# Recette des avis (étape 2 du backend).
# Usage : bash scripts/smoke-avis.sh            (serveur sur http://127.0.0.1:8000)
#         SERVICE_ID=2 bash scripts/smoke-avis.sh   (si l'agent n'est pas sur le service 1)
# Prérequis : migration appliquée (php bin/console doctrine:migrations:migrate),
#             symfony serve lancé, comptes de test créés, php dans le PATH.
# Laisse quelques créneaux/rendez-vous/avis de test dans la base (sans danger).

BASE="${1:-http://127.0.0.1:8000}"
SERVICE_ID="${SERVICE_ID:-1}"
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
plus_recente() { api GET /api/notifications "$1"; NTYPE=$(jget "$BODY" 0.type); }
# contient TOKEN CHEMIN ID : l'avis ID figure-t-il dans la liste ? (oui/non)
contient() { api GET "$2" "$1"; printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=false; foreach ((array)$d as $a) { if (is_array($a) && ($a["id"] ?? null) == $argv[1]) { $ok=true; } } echo $ok ? "oui" : "non";' -- "$3"; }

echo "== Connexion =="
CIT=$(login test@test.com);        [ -n "$CIT" ] && ok "login citoyen"     || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);       [ -n "$AGT" ] && ok "login agent"       || ko "login agent" "$BODY"
ADM=$(login admin@test.com);       [ -n "$ADM" ] && ok "login admin"       || ko "login admin" "$BODY"
RESP=$(login responsable@test.com);[ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
[ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$ADM" ] || [ -z "$RESP" ] && { echo "Impossible de continuer sans jetons."; exit 1; }

echo "== Accès =="
api GET /api/avis "";            code_attendu "sans jeton -> 401" 401
api POST /api/avis "" '{}';      code_attendu "POST sans jeton -> 401" 401
api GET /api/avis "$CIT";  code_attendu "citoyen liste ses avis" 200
api GET /api/avis "$AGT";  code_attendu "agent liste les avis de son service" 200
api GET /api/avis "$RESP"; code_attendu "responsable liste tous les avis" 200
api GET /api/avis "$ADM";  code_attendu "admin liste tous les avis" 200
api GET "/api/avis?note=9" "$ADM"; code_attendu "filtre note invalide -> 400" 400

echo "== Préparation : 2 rendez-vous =="
JOUR=$(date -d "+$((RANDOM % 300 + 5)) days" +%F)
h() { printf '"%02d:%02d"' $((8 + RANDOM % 10)) $((RANDOM % 60)); }
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}"; C1=$(jget "$BODY" crees.0.id)
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}"; C2=$(jget "$BODY" crees.0.id)
[ -n "$C1" ] && [ -n "$C2" ] && ok "2 créneaux créés ($JOUR)" || ko "créneaux" "relance le script (heure déjà prise ?) $BODY"
api POST /api/demandes "$CIT" "{\"texte\":\"Recette avis\",\"serviceId\":$SERVICE_ID}"
DEM=$(jget "$BODY" id); [ -n "$DEM" ] && ok "demande $DEM créée" || ko "demande" "$BODY"
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C1}"; code_attendu "réservation rdv 1" 201
RDV=$(jget "$BODY" id)

echo "== 1. Pas d'avis tant que le rendez-vous n'est pas terminé =="
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":4}"; code_attendu "rdv en attente -> 409" 409
api PATCH "/api/rendez-vous/$RDV/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme" 200
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":4}"; code_attendu "rdv confirmé (pas terminé) -> 409" 409

echo "== 2. Rendez-vous terminé -> citoyen invité à donner son avis =="
api PATCH "/api/rendez-vous/$RDV/statut" "$AGT" '{"statut":"termine"}'; code_attendu "agent termine le rdv" 200
plus_recente "$CIT"; egal "citoyen : notification rdv_termine" "$NTYPE" rdv_termine

echo "== 3. Validation =="
api POST /api/avis "$CIT" '{"note":4}';                              code_attendu "rendezVousId manquant -> 400" 400
api POST /api/avis "$CIT" '{"rendezVousId":99999999,"note":4}';      code_attendu "rdv inconnu -> 404" 404
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV}";                 code_attendu "note manquante -> 422" 422
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":0}";      code_attendu "note 0 -> 422" 422
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":6}";      code_attendu "note 6 -> 422" 422
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":\"abc\"}"; code_attendu "note texte -> 422" 422
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":4.5}";    code_attendu "note décimale -> 422" 422
LONG=$(printf 'a%.0s' $(seq 1 1001))
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":4,\"commentaire\":\"$LONG\"}"; code_attendu "commentaire > 1000 car. -> 422" 422

echo "== 4. Seul le citoyen du rendez-vous peut noter =="
api POST /api/avis "$AGT" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "agent ne peut pas noter (403)" 403
api POST /api/avis "$RESP" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "responsable ne peut pas noter (403)" 403
api POST /api/avis "$ADM" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "admin ne peut pas noter (403)" 403
api GET "/api/avis/rendez-vous/$RDV" "$CIT"; code_attendu "pas encore d'avis -> 404" 404

echo "== 5. Dépôt de l'avis =="
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":4,\"commentaire\":\"  Accueil rapide  \"}"; code_attendu "citoyen donne son avis" 201
AVIS=$(jget "$BODY" id)
egal "note enregistrée" "$(jget "$BODY" note)" 4
egal "commentaire nettoyé (trim)" "$(jget "$BODY" commentaire)" "Accueil rapide"
egal "lié au bon rendez-vous" "$(jget "$BODY" rendezVousId)" "$RDV"
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "deuxième avis sur le même rdv -> 409" 409

echo "== 6. Consultation =="
egal "citoyen voit son avis"      "$(contient "$CIT"  /api/avis "$AVIS")" oui
egal "agent (service) voit l'avis" "$(contient "$AGT"  /api/avis "$AVIS")" oui
egal "responsable voit l'avis"    "$(contient "$RESP" /api/avis "$AVIS")" oui
egal "admin voit l'avis"          "$(contient "$ADM"  /api/avis "$AVIS")" oui
egal "filtre note=4 : présent"    "$(contient "$ADM" "/api/avis?note=4" "$AVIS")" oui
egal "filtre note=2 : absent"     "$(contient "$ADM" "/api/avis?note=2" "$AVIS")" non
egal "filtre serviceId=$SERVICE_ID : présent" "$(contient "$ADM" "/api/avis?serviceId=$SERVICE_ID" "$AVIS")" oui
egal "filtre serviceId=99999999 : absent"      "$(contient "$ADM" "/api/avis?serviceId=99999999" "$AVIS")" non
api GET "/api/avis/rendez-vous/$RDV" "$CIT";  code_attendu "citoyen lit l'avis de son rdv" 200
api GET "/api/avis/rendez-vous/$RDV" "$AGT";  code_attendu "agent lit l'avis (son service)" 200
api GET "/api/avis/rendez-vous/$RDV" "$RESP"; code_attendu "responsable lit l'avis" 200
api GET /api/avis/rendez-vous/99999999 "$CIT"; code_attendu "rdv inconnu -> 404" 404

echo "== 7. Rendez-vous « absent » : pas d'avis, pas d'invitation =="
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C2}"; code_attendu "nouvelle réservation" 201
RDV2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV2/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme" 200
api PATCH "/api/rendez-vous/$RDV2/statut" "$AGT" '{"statut":"absent"}'; code_attendu "agent note absent" 200
plus_recente "$CIT"; [ "$NTYPE" != "rdv_termine" ] && ok "citoyen : pas de rdv_termine pour un absent" || ko "notification absent" "type $NTYPE"
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV2,\"note\":3}"; code_attendu "avis sur un rdv absent -> 409" 409

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
