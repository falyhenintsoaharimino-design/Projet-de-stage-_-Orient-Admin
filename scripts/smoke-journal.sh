#!/usr/bin/env bash
# Recette du journal d'audit (étape 3 du backend).
# Usage : bash scripts/smoke-journal.sh            (serveur sur http://127.0.0.1:8000)
#         SERVICE_ID=2 bash scripts/smoke-journal.sh   (si l'agent n'est pas sur le service 1)
# Prérequis : migration appliquée (php bin/console doctrine:migrations:migrate),
#             symfony serve lancé, comptes de test créés, php dans le PATH.
# Laisse quelques créneaux/demandes/rendez-vous/avis/comptes de test dans la base (sans danger).

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
# nb ACTION : nombre d'entrées du journal pour cette action (lu avec le jeton admin)
nb() { api GET "/api/journal?action=$1&limite=1" "$ADM"; jget "$BODY" total; }
# delta ACTION AVANT ATTENDU LIBELLÉ
delta() { local apres; apres=$(nb "$1"); egal "$4" "$((apres - $2))" "$3"; }

echo "== Connexion =="
CIT=$(login test@test.com);        [ -n "$CIT" ] && ok "login citoyen"     || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);       [ -n "$AGT" ] && ok "login agent"       || ko "login agent" "$BODY"
ADM=$(login admin@test.com);       [ -n "$ADM" ] && ok "login admin"       || ko "login admin" "$BODY"
RESP=$(login responsable@test.com);[ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
[ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$ADM" ] || [ -z "$RESP" ] && { echo "Impossible de continuer sans jetons."; exit 1; }

echo "== 1. Accès réservé à l'admin =="
api GET /api/journal "";            code_attendu "sans jeton -> 401" 401
api GET /api/journal "$CIT";        code_attendu "citoyen -> 403" 403
api GET /api/journal "$AGT";        code_attendu "agent -> 403" 403
api GET /api/journal "$RESP";       code_attendu "responsable -> 403" 403
api GET /api/journal "$ADM";        code_attendu "admin -> 200" 200
api GET /api/journal/actions "$CIT"; code_attendu "actions : citoyen -> 403" 403
api GET /api/journal/actions "$ADM"; code_attendu "actions : admin -> 200" 200

echo "== 2. Validation des filtres =="
api GET "/api/journal?page=0" "$ADM";             code_attendu "page=0 -> 400" 400
api GET "/api/journal?page=abc" "$ADM";           code_attendu "page=abc -> 400" 400
api GET "/api/journal?limite=0" "$ADM";           code_attendu "limite=0 -> 400" 400
api GET "/api/journal?limite=101" "$ADM";         code_attendu "limite=101 -> 400" 400
api GET "/api/journal?du=2026-13-45" "$ADM";      code_attendu "du invalide -> 400" 400
api GET "/api/journal?au=demain" "$ADM";          code_attendu "au invalide -> 400" 400
api GET "/api/journal?utilisateurId=abc" "$ADM";  code_attendu "utilisateurId invalide -> 400" 400

echo "== 3. Mesures de départ =="
A_CONNEXION=$(nb connexion); A_ECHEC=$(nb connexion_echec); A_INSCR=$(nb inscription)
A_DCREE=$(nb demande_creee); A_DREO=$(nb demande_reorientee); A_DSTAT=$(nb demande_statut)
A_RRES=$(nb rdv_reserve); A_RCONF=$(nb rdv_confirme); A_RREF=$(nb rdv_refuse)
A_RANN=$(nb rdv_annule); A_RTERM=$(nb rdv_termine); A_RABS=$(nb rdv_absent); A_AVIS=$(nb avis_donne)
[ -n "$A_CONNEXION" ] && ok "compteurs lus (connexion=$A_CONNEXION)" || ko "compteurs" "$BODY"

echo "== 4. Connexions =="
login test@test.com >/dev/null
delta connexion "$A_CONNEXION" 1 "une connexion réussie ajoute 1 ligne « connexion »"
api GET /api/me "$CIT"; code_attendu "requête avec jeton (/api/me)" 200
api GET /api/me "$CIT"
delta connexion "$((A_CONNEXION + 1))" 0 "les requêtes avec jeton n'ajoutent rien"
MAUVAIS="inconnu-$RANDOM@test.com"
api POST /api/login_check "" "{\"email\":\"$MAUVAIS\",\"password\":\"mauvaisMDP-xyz\"}"; code_attendu "mauvais identifiants -> 401" 401
delta connexion_echec "$A_ECHEC" 1 "un échec ajoute 1 ligne « connexion_echec »"
api GET "/api/journal?action=connexion_echec&limite=1" "$ADM"
egal "échec : l'e-mail saisi est consigné" "$(jget "$BODY" items.0.details)" "email=$MAUVAIS"
egal "échec : aucun utilisateur rattaché" "$(jget "$BODY" items.0.utilisateur)" "null"
case "$BODY" in *mauvaisMDP-xyz*) ko "le mot de passe ne doit jamais être journalisé" "présent dans $BODY";; *) ok "le mot de passe n'est pas journalisé";; esac

echo "== 5. Inscription =="
NOUVEAU="recette-$RANDOM$RANDOM@test.com"
api POST /api/register "" "{\"nom\":\"Recette\",\"prenom\":\"Journal\",\"email\":\"$NOUVEAU\",\"password\":\"motdepasse123\"}"; code_attendu "inscription" 201
delta inscription "$A_INSCR" 1 "inscription journalisée"
api GET "/api/journal?action=inscription&limite=1" "$ADM"
egal "inscription : rattachée au nouveau compte" "$(jget "$BODY" items.0.utilisateur.email)" "$NOUVEAU"

echo "== 6. Demande, rendez-vous, avis =="
JOUR=$(date -d "+$((RANDOM % 300 + 5)) days" +%F)
h() { printf '"%02d:%02d"' $((8 + RANDOM % 10)) $((RANDOM % 60)); }
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}"; C1=$(jget "$BODY" crees.0.id)
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[$(h)]}"; C2=$(jget "$BODY" crees.0.id)
[ -n "$C1" ] && [ -n "$C2" ] && ok "2 créneaux créés ($JOUR)" || ko "créneaux" "relance le script (heure déjà prise ?) $BODY"
api POST /api/demandes "$CIT" "{\"texte\":\"Recette journal\",\"serviceId\":$SERVICE_ID}"
DEM=$(jget "$BODY" id); [ -n "$DEM" ] && ok "demande $DEM créée" || ko "demande" "$BODY"
delta demande_creee "$A_DCREE" 1 "demande_creee journalisée"
api GET "/api/journal?action=demande_creee&limite=1" "$ADM"
egal "demande_creee : détail" "$(jget "$BODY" items.0.details)" "demande=$DEM statut=orientee"

api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C1}"; code_attendu "réservation rdv 1" 201
RDV=$(jget "$BODY" id)
delta rdv_reserve "$A_RRES" 1 "rdv_reserve journalisé"
api PATCH "/api/rendez-vous/$RDV/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme" 200
delta rdv_confirme "$A_RCONF" 1 "rdv_confirme journalisé"
api PATCH "/api/rendez-vous/$RDV/statut" "$AGT" '{"statut":"termine"}'; code_attendu "agent termine" 200
delta rdv_termine "$A_RTERM" 1 "rdv_termine journalisé"
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "avis déposé" 201
delta avis_donne "$A_AVIS" 1 "avis_donne journalisé"
api POST /api/avis "$CIT" "{\"rendezVousId\":$RDV,\"note\":5}"; code_attendu "2e avis refusé -> 409" 409
delta avis_donne "$A_AVIS" 1 "un avis refusé n'est pas journalisé"

api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C2}"; code_attendu "réservation rdv 2" 201
RDV2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV2/decision" "$AGT" '{"decision":"refuser"}'; code_attendu "agent refuse" 200
delta rdv_refuse "$A_RREF" 1 "rdv_refuse journalisé"
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C2}"; code_attendu "réservation rdv 3 (créneau libéré)" 201
RDV3=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV3/annuler" "$CIT"; code_attendu "citoyen annule" 200
delta rdv_annule "$A_RANN" 1 "rdv_annule journalisé"
api GET "/api/journal?action=rdv_annule&limite=1" "$ADM"
egal "rdv_annule : détail" "$(jget "$BODY" items.0.details)" "rdv=$RDV3 demande=$DEM"
egal "rdv_annule : auteur = le citoyen" "$(jget "$BODY" items.0.utilisateur.email)" "test@test.com"
api PATCH "/api/rendez-vous/$RDV3/annuler" "$CIT"; code_attendu "annulation répétée -> 409" 409
delta rdv_annule "$A_RANN" 1 "une annulation refusée n'est pas journalisée"

api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DEM,\"creneauId\":$C2}"; code_attendu "réservation rdv 4" 201
RDV4=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$RDV4/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme rdv 4" 200
api PATCH "/api/rendez-vous/$RDV4/statut" "$AGT" '{"statut":"absent"}'; code_attendu "agent note absent" 200
delta rdv_absent "$A_RABS" 1 "rdv_absent journalisé"

echo "== 7. Décisions de l'agent sur la demande =="
api PATCH "/api/demandes/$DEM/reorienter" "$AGT" "{\"serviceId\":$SERVICE_ID}"; code_attendu "agent réoriente" 200
delta demande_reorientee "$A_DREO" 1 "demande_reorientee journalisée"
api PATCH "/api/demandes/$DEM/statut" "$AGT" '{"statut":"en_traitement"}'; code_attendu "agent change le statut" 200
delta demande_statut "$A_DSTAT" 1 "demande_statut journalisé"
api GET "/api/journal?action=demande_statut&limite=1" "$ADM"
egal "demande_statut : détail" "$(jget "$BODY" items.0.details)" "demande=$DEM statut=en_traitement"
egal "demande_statut : auteur = l'agent" "$(jget "$BODY" items.0.utilisateur.email)" "agent@test.com"
api PATCH "/api/demandes/$DEM/statut" "$AGT" '{"statut":"nimporte"}'; code_attendu "statut invalide -> 400" 400
delta demande_statut "$A_DSTAT" 1 "un statut invalide n'est pas journalisé"

echo "== 8. Filtres et pagination =="
AUJ=$(date +%F)
api GET /api/me "$CIT"; CITID=$(jget "$BODY" id)
api GET "/api/journal?utilisateurId=$CITID&limite=100" "$ADM"
TOUS=$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=!empty($d["items"]); foreach ($d["items"] ?? [] as $i) { if (($i["utilisateur"]["id"] ?? null) != $argv[1]) { $ok=false; } } echo $ok ? "oui" : "non";' -- "$CITID")
egal "filtre utilisateurId : que des lignes du citoyen" "$TOUS" oui
api GET "/api/journal?du=$AUJ&au=$AUJ" "$ADM"
[ "$(jget "$BODY" total)" -ge 1 ] 2>/dev/null && ok "filtre du=au=aujourd'hui : au moins 1 ligne" || ko "filtre du/au aujourd'hui" "$BODY"
api GET "/api/journal?au=2000-01-01" "$ADM"
egal "filtre au=2000-01-01 : aucune ligne" "$(jget "$BODY" total)" 0
api GET "/api/journal?du=2999-01-01" "$ADM"
egal "filtre du=2999-01-01 : aucune ligne" "$(jget "$BODY" total)" 0
api GET "/api/journal?limite=2&page=1" "$ADM"
TOTAL=$(jget "$BODY" total)
egal "limite=2 : 2 éléments" "$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["items"]);')" 2
egal "pages = ceil(total/2)" "$(jget "$BODY" pages)" "$(( (TOTAL + 1) / 2 ))"
D1=$(jget "$BODY" items.0.date); D2=$(jget "$BODY" items.1.date)
[ ! "$D1" \< "$D2" ] && ok "tri : du plus récent au plus ancien" || ko "tri" "$D1 puis $D2"
api GET "/api/journal?page=99999" "$ADM"; code_attendu "page hors limites -> 200" 200
egal "page hors limites : liste vide" "$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["items"]);')" 0
api GET /api/journal/actions "$ADM"
case "$BODY" in *rdv_confirme*) ok "liste des actions contient rdv_confirme";; *) ko "liste des actions" "$BODY";; esac

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
