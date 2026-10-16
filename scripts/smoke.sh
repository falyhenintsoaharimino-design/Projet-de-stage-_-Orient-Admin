#!/usr/bin/env bash
# Recette des notifications (étape 1 du backend).
# Usage : bash scripts/smoke.sh            (serveur sur http://127.0.0.1:8000)
#         bash scripts/smoke.sh http://127.0.0.1:8000
#         SERVICE_ID=2 bash scripts/smoke.sh   (si l'agent n'est pas sur le service 1)
# Prérequis : symfony serve lancé, comptes de test créés (app:seed-demo), php dans le PATH.
# Laisse quelques créneaux/rendez-vous de test dans la base (sans danger).

BASE="${1:-http://127.0.0.1:8000}"
SERVICE_ID="${SERVICE_ID:-1}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0

ok()  { echo "OK      $1"; NB_OK=$((NB_OK+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; NB_KO=$((NB_KO+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) $BODY"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }

# jget '<json>' 'a.b.0.c' : lit une valeur dans un JSON (via PHP, pas besoin de jq)
jget() {
  printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (explode(".", $argv[1]) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) { exit(0); } $d = $d[$k]; }
    echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' -- "$2"
}

# api METHODE /chemin [token] [corps-json]  ->  remplit CODE et BODY
api() {
  local args=(-s -X "$1" "$BASE$2" -H 'Content-Type: application/json' -w $'\n%{http_code}')
  [ -n "$3" ] && args+=(-H "Authorization: Bearer $3")
  [ -n "$4" ] && args+=(-d "$4")
  local out; out=$(curl "${args[@]}")
  CODE="${out##*$'\n'}"; BODY="${out%$'\n'*}"
}

login() { api POST /api/login_check "" "{\"email\":\"$1\",\"password\":\"$PASS\"}"; jget "$BODY" token; }
# plus_recente TOKEN -> NID (id) et NTYPE (type) de la notification la plus récente
plus_recente() { api GET /api/notifications "$1"; NID=$(jget "$BODY" 0.id); NTYPE=$(jget "$BODY" 0.type); }
non_lues() { api GET /api/notifications/compteur "$1"; jget "$BODY" nonLues; }

echo "== Connexion =="
CIT=$(login test@test.com);  [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com); [ -n "$AGT" ] && ok "login agent"   || ko "login agent" "$BODY"
[ -z "$CIT" ] || [ -z "$AGT" ] && { echo "Impossible de continuer sans jetons."; exit 1; }

echo "== Accès =="
api GET /api/notifications "";    code_attendu "sans jeton -> 401" 401
api GET /api/notifications "$CIT"; code_attendu "citoyen liste ses notifications" 200

echo "== Préparation : créneaux + demande =="
JOUR=$(date -d "+$((RANDOM % 300 + 5)) days" +%F)
h() { printf '"%02d:%02d"' $((8 + RANDOM % 10)) $((RANDOM % 60)); }
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}";  C1=$(jget "$BODY" crees.0.id)
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}";  C2=$(jget "$BODY" crees.0.id)
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}";  C3=$(jget "$BODY" crees.0.id)
[ -n "$C1" ] && [ -n "$C2" ] && [ -n "$C3" ] && ok "3 créneaux créés ($JOUR)" || ko "créneaux" "relance le script (heure déjà prise ?) $BODY"
api POST /api/demandes "$CIT" "{\"texte\":\"Recette notifications\",\"serviceId\":$SERVICE_ID}"
DEM=$(jget "$BODY" id); [ -n "$DEM" ] && ok "demande $DEM créée" || ko "demande" "$BODY"

echo "== 1. Réservation -> citoyen + agent notifiés =="
AV_CIT=$(non_lues "$CIT"); AV_AGT=$(non_lues "$AGT")
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C1}"; code_attendu "réservation" 201
RDV=$(jget "$BODY" id)
plus_recente "$CIT"; egal "citoyen : type rdv_en_attente" "$NTYPE" rdv_en_attente; N_CIT=$NID
plus_recente "$AGT"; egal "agent : type rdv_a_valider" "$NTYPE" rdv_a_valider
egal "citoyen : +1 non lue" "$(non_lues "$CIT")" $((AV_CIT+1))
egal "agent : +1 non lue"   "$(non_lues "$AGT")" $((AV_AGT+1))

echo "== 2. Marquer comme lu =="
api PATCH "/api/notifications/$N_CIT/lu" "$AGT"; code_attendu "un autre utilisateur ne peut pas la marquer (403)" 403
api PATCH "/api/notifications/$N_CIT/lu" "$CIT"; code_attendu "le propriétaire la marque lue" 200
egal "statut = lue" "$(jget "$BODY" statut)" lue
egal "citoyen : compteur revenu" "$(non_lues "$CIT")" "$AV_CIT"
api PATCH /api/notifications/99999999/lu "$CIT"; code_attendu "notification inconnue (404)" 404

echo "== 3. Confirmation par l'agent -> citoyen notifié =="
api PATCH "/api/rendez-vous/$RDV/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme" 200
plus_recente "$CIT"; egal "citoyen : type rdv_confirme" "$NTYPE" rdv_confirme

echo "== 4. Annulation par le citoyen -> agent notifié =="
plus_recente "$AGT"; AVANT=$NID
api PATCH "/api/rendez-vous/$RDV/annuler" "$CIT"; code_attendu "citoyen annule" 200
plus_recente "$AGT"; egal "agent : type rdv_annule" "$NTYPE" rdv_annule
[ "$NID" != "$AVANT" ] && ok "agent : nouvelle notification" || ko "agent : nouvelle notification" "même id $NID"

echo "== 5. Refus par l'agent -> citoyen notifié =="
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C2}"; code_attendu "nouvelle réservation" 201
RDV2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV2/decision" "$AGT" '{"decision":"refuser"}'; code_attendu "agent refuse" 200
plus_recente "$CIT"; egal "citoyen : type rdv_refuse" "$NTYPE" rdv_refuse

echo "== 6. Annulation par l'agent -> citoyen notifié =="
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C3}"; code_attendu "nouvelle réservation" 201
RDV3=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV3/annuler" "$AGT"; code_attendu "agent annule" 200
plus_recente "$CIT"; egal "citoyen : type rdv_annule" "$NTYPE" rdv_annule

echo "== 7. Liste, filtre, tout marquer lu, isolation =="
api GET "/api/notifications?nonLues=1" "$CIT"; code_attendu "filtre nonLues=1" 200
[ "$(jget "$BODY" 0.statut)" = "non_lue" ] && ok "filtre : premières = non lues" || ko "filtre nonLues" "$BODY"
api GET /api/notifications "$CIT"
[ "$(printf '%s' "$BODY" | grep -c rdv_a_valider)" = "0" ] && ok "le citoyen ne voit pas les notifications de l'agent" || ko "isolation" "rdv_a_valider visible côté citoyen"
api PATCH /api/notifications/lu-tout "$CIT"; code_attendu "tout marquer lu" 200
egal "citoyen : compteur à 0" "$(non_lues "$CIT")" 0
api GET "/api/notifications?nonLues=1" "$CIT"; egal "citoyen : plus aucune non lue" "$BODY" "[]"
[ "$(non_lues "$AGT")" -gt 0 ] && ok "l'agent a toujours les siennes (non touchées)" || ko "isolation lu-tout" "compteur agent à 0"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
echo "E-mails : ils partent dans la file Messenger. Pour les voir : php bin/console messenger:consume async -vv --limit=20"
[ "$NB_KO" = "0" ]
