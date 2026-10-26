#!/usr/bin/env bash
# Recette de l'étape 8 : rendez-vous (réglages et génération en lot des créneaux, capacité, délai limite du citoyen,
# modification par le citoyen, report par l'agent, récapitulatif avec pièces à apporter) : scénario S4.
# Usage : bash scripts/smoke-rendez-vous.sh        (à la racine du projet, symfony serve lancé)
# Prérequis : migration Version20261026120000 appliquée, comptes de test créés (agent@test.com rattaché à un service),
# php et curl dans le PATH. Le script crée des créneaux (dans plusieurs mois), des demandes et des rendez-vous de test
# qu'il annule ou laisse en base : sans danger.

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
ID_UNIQUE="$RANDOM$RANDOM"

ok()  { echo "OK      $1"; NB_OK=$((NB_OK+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; NB_KO=$((NB_KO+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) ${BODY:0:200}"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }
contient() { case "$2" in *"$3"*) ok "$1";; *) ko "$1" "'$3' absent de '${2:0:200}'";; esac; }

jget() {
  printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (explode(".", $argv[1]) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) { exit(0); } $d = $d[$k]; }
    echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' -- "$2"
}

# Le corps JSON passe par un fichier temporaire (--data-binary) : sous Git Bash, un corps passé en argument
# (crochets, accents) peut être déformé avant d'arriver à curl.exe.
api() {
  local args=(-s -X "$1" "$BASE$2" -H 'Content-Type: application/json' -w $'\n%{http_code}')
  [ -n "$3" ] && args+=(-H "Authorization: Bearer $3")
  local tmp=""
  if [ -n "$4" ]; then tmp=$(mktemp); printf '%s' "$4" > "$tmp"; args+=(--data-binary "@$tmp"); fi
  local out; out=$(curl "${args[@]}")
  [ -n "$tmp" ] && rm -f "$tmp"
  CODE="${out##*$'\n'}"; BODY="${out%$'\n'*}"
}

login() { api POST /api/login_check "" "{\"email\":\"$1\",\"password\":\"${2:-$PASS}\"}"; jget "$BODY" token; }
nb() { api GET "/api/journal?action=$1&limite=1" "$ADM"; jget "$BODY" total; }
delta() { local apres; apres=$(nb "$1"); egal "$4" "$((apres - $2))" "$3"; }
# nouvelle demande du citoyen orientée vers le service SA
demande() { api POST /api/demandes "$CIT" "{\"texte\":\"Recette rendez-vous $ID_UNIQUE\",\"serviceId\":$SA}"; jget "$BODY" id; }
# réserver DEMANDE CRENEAU [PROCEDURE]
reserver() { local p=""; [ -n "$3" ] && p=",\"procedureId\":$3"; api POST /api/rendez-vous "$CIT" "{\"demandeId\":$1,\"creneauId\":$2$p}"; }
# le créneau $2 figure-t-il dans la liste $1 (jeton) du jour $3 ? oui/non
visible() { api GET "/api/creneaux?serviceId=$SA&date=$3" "$1"; printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=false; foreach ((array)$d as $c) { if (is_array($c) && ($c["id"] ?? null) == $argv[1]) { $ok=true; } } echo $ok ? "oui" : "non";' -- "$2"; }

echo "== Connexion =="
ADM=$(login admin@test.com);  [ -n "$ADM" ] && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);   [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);  [ -n "$AGT" ] && ok "login agent" || ko "login agent" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
api GET /api/me "$AGT"; SA=$(jget "$BODY" service)
[ -n "$SA" ] && ok "l'agent est rattaché au service $SA" || { ko "agent sans service" "$BODY"; exit 1; }
B_GEN=$(nb creneaux_generes); B_MODIF=$(nb rdv_modifie); B_REPORT=$(nb rdv_reporte)

echo "== 1. Réglages des créneaux du service =="
api PUT "/api/services/$SA" "$CIT" '{"capaciteCreneau":2}'; code_attendu "citoyen -> 403" 403
for corps in '{"capaciteCreneau":0}' '{"dureeCreneauMinutes":3}' '{"joursFermes":[0]}' '{"joursFermes":"samedi"}'; do
  api PUT "/api/services/$SA" "$AGT" "$corps"; code_attendu "réglage invalide $corps -> 400" 400
done
api PUT "/api/services/$SA" "$AGT" '{"capaciteCreneau":2,"dureeCreneauMinutes":30,"joursFermes":[6,7]}'; code_attendu "l'agent règle son service -> 200" 200
egal "capacité du service" "$(jget "$BODY" capaciteCreneau)" "2"
egal "jours fermés (week-end)" "$(jget "$BODY" joursFermes)" "[6,7]"

echo "== 2. Génération en lot =="
# un lundi lointain (évite les collisions avec d'autres recettes) et le dimanche suivant
J=$(date -d "+$((RANDOM % 600 + 400)) days" +%F); LUNDI=$(date -d "$J -$(( $(date -d "$J" +%u) - 1 )) days" +%F); DIMANCHE=$(date -d "$LUNDI +6 days" +%F); SAMEDI=$(date -d "$LUNDI +5 days" +%F)
GEN="{\"dateDebut\":\"$LUNDI\",\"dateFin\":\"$DIMANCHE\",\"heureDebut\":\"08:00\",\"heureFin\":\"10:00\",\"pauseDebut\":\"09:00\",\"pauseFin\":\"09:30\"}"
api POST /api/creneaux/generer "" "$GEN"; code_attendu "sans jeton -> 401" 401
api POST /api/creneaux/generer "$CIT" "$GEN"; code_attendu "citoyen -> 403" 403
api POST /api/creneaux/generer "$AGT" '{"dateDebut":"2030-01-01","heureDebut":"10:00","heureFin":"08:00"}'; code_attendu "heureFin avant heureDebut -> 400" 400
api POST /api/creneaux/generer "$AGT" '{"dateDebut":"2020-01-01","heureDebut":"08:00","heureFin":"10:00"}'; code_attendu "période passée -> 400" 400
api POST /api/creneaux/generer "$AGT" "$GEN"; code_attendu "l'agent génère la semaine -> 201" 201
egal "5 jours ouverts" "$(jget "$BODY" joursOuverts)" "5"
egal "3 créneaux par jour (la pause saute 09:00)" "$(jget "$BODY" creneauxParJour)" "3"
egal "15 créneaux créés" "$(jget "$BODY" crees)" "15"
egal "durée et capacité du service reprises" "$(jget "$BODY" dureeMinutes)/$(jget "$BODY" capacite)" "30/2"
api POST /api/creneaux/generer "$AGT" "$GEN"; egal "rejouer ne duplique rien (ignorés)" "$(jget "$BODY" ignores)" "15"
egal "rien de recréé" "$(jget "$BODY" crees)" "0"
api GET "/api/creneaux?serviceId=$SA&date=$LUNDI" "$AGT"; code_attendu "agent liste le lundi" 200
egal "heures du lundi" "$(jget "$BODY" 0.heure) $(jget "$BODY" 1.heure) $(jget "$BODY" 2.heure)" "08:00 08:30 09:30"
C1=$(jget "$BODY" 0.id); C2=$(jget "$BODY" 1.id); C3=$(jget "$BODY" 2.id)
egal "capacité du créneau" "$(jget "$BODY" 0.capacite)" "2"
egal "heure de fin" "$(jget "$BODY" 0.heureFin)" "08:30"
api GET "/api/creneaux?serviceId=$SA&date=$SAMEDI" "$AGT"; egal "samedi fermé : aucun créneau" "$BODY" "[]"

echo "== 3. Capacité : 2 places, aucune double réservation =="
D1=$(demande); D2=$(demande); D3=$(demande)
reserver "$D1" "$C1"; code_attendu "1re réservation -> 201" 201; R1=$(jget "$BODY" id)
reserver "$D2" "$C1"; code_attendu "2e réservation (dernière place) -> 201" 201; R2=$(jget "$BODY" id)
reserver "$D3" "$C1"; code_attendu "3e réservation : créneau complet -> 409" 409
contient "message « complet »" "$(jget "$BODY" error)" "complet"
reserver "$D1" "$C2"; code_attendu "même demande, 2e rendez-vous -> 409" 409
egal "citoyen : le créneau complet n'est plus proposé" "$(visible "$CIT" "$C1" "$LUNDI")" "non"
egal "agent : il le voit" "$(visible "$AGT" "$C1" "$LUNDI")" "oui"
api GET "/api/creneaux?serviceId=$SA&date=$LUNDI" "$AGT"; egal "2 réservations, 0 place restante" "$(jget "$BODY" 0.reservations)/$(jget "$BODY" 0.placesRestantes)" "2/0"
api PATCH "/api/rendez-vous/$R1/annuler" "$CIT"; code_attendu "le citoyen annule (loin du délai) -> 200" 200
egal "la place est libérée" "$(visible "$CIT" "$C1" "$LUNDI")" "oui"
reserver "$D3" "$C1"; code_attendu "un autre prend la place -> 201" 201; R3=$(jget "$BODY" id)
api PATCH "/api/creneaux/$C1" "$AGT" '{"capacite":1}'; code_attendu "capacité sous les 2 rendez-vous actifs -> 409" 409
api PATCH "/api/creneaux/$C2" "$AGT" '{"disponible":false}'; code_attendu "l'agent bloque le créneau 2" 200
egal "créneau bloqué invisible du citoyen" "$(visible "$CIT" "$C2" "$LUNDI")" "non"
D4=$(demande); reserver "$D4" "$C2"; code_attendu "réservation d'un créneau bloqué -> 409" 409
api PATCH "/api/creneaux/$C2" "$AGT" '{"disponible":true}'; code_attendu "l'agent le rouvre" 200

echo "== 4. Délai limite du citoyen (24 h) =="
# « demain » dans le fuseau de l'application (APP_TIMEZONE, Indian/Antananarivo par défaut), pas celui du shell
DEMAIN=$(php -r '$tz = new DateTimeZone(getenv("APP_TIMEZONE") ?: "Indian/Antananarivo"); echo (new DateTime("tomorrow", $tz))->format("Y-m-d");')
api POST /api/creneaux "$AGT" "{\"date\":\"$DEMAIN\",\"heure\":\"00:00\"}"
CP=$(jget "$BODY" crees.0.id)
[ -z "$CP" ] && { api GET "/api/creneaux?serviceId=$SA&date=$DEMAIN" "$AGT"; CP=$(jget "$BODY" 0.id); }
[ -n "$CP" ] && ok "créneau de demain 00:00 (à moins de 24 h)" || ko "créneau proche" "$BODY"
D5=$(demande); reserver "$D5" "$CP"; code_attendu "réservation proche -> 201" 201; R5=$(jget "$BODY" id)
egal "plus modifiable par le citoyen" "$(jget "$BODY" modifiableParLeCitoyen)" "false"
api PATCH "/api/rendez-vous/$R5" "$CIT" "{\"creneauId\":$C2}"; code_attendu "citoyen : modification hors délai -> 409" 409
contient "message « délai »" "$(jget "$BODY" error)" "délai"
api PATCH "/api/rendez-vous/$R5/annuler" "$CIT"; code_attendu "citoyen : annulation hors délai -> 409" 409
api PATCH "/api/rendez-vous/$R5/annuler" "$AGT"; code_attendu "l'agent annule sans délai -> 200" 200

echo "== 5. Modification par le citoyen =="
api PATCH "/api/rendez-vous/$R2/decision" "$AGT" '{"decision":"confirmer"}'; egal "rendez-vous 2 confirmé" "$(jget "$BODY" statut)" "confirme"
api PATCH "/api/rendez-vous/$R2" "$CIT" '{}'; code_attendu "rien à modifier -> 422" 422
api PATCH "/api/rendez-vous/$R2" "$CIT" "{\"creneauId\":$C1}"; code_attendu "même créneau -> 422" 422
api PATCH "/api/rendez-vous/$R2" "$AGT" "{\"creneauId\":$C2}"; code_attendu "l'agent n'utilise pas cette route -> 403" 403
api PATCH "/api/rendez-vous/$R2" "$CIT" "{\"creneauId\":$C2}"; code_attendu "le citoyen change de créneau -> 200" 200
egal "il faut le re-confirmer (en attente)" "$(jget "$BODY" statut)" "en_attente"
egal "nouveau créneau" "$(jget "$BODY" creneauId)" "$C2"

echo "== 6. Report par l'agent =="
api PATCH "/api/rendez-vous/$R2/reporter" "$CIT" "{\"creneauId\":$C3}"; code_attendu "citoyen -> 403" 403
api PATCH "/api/rendez-vous/$R2/reporter" "$AGT" '{}'; code_attendu "creneauId manquant -> 400" 400
api PATCH "/api/rendez-vous/$R2/reporter" "$AGT" "{\"creneauId\":$C2}"; code_attendu "déjà sur ce créneau -> 422" 422
api PATCH "/api/rendez-vous/$R2/reporter" "$AGT" "{\"creneauId\":$C3,\"motif\":\"Absence de l'agent\"}"; code_attendu "l'agent reporte -> 200" 200
egal "confirmé sur le nouveau créneau" "$(jget "$BODY" statut)/$(jget "$BODY" creneauId)" "confirme/$C3"
api GET /api/notifications "$CIT"
egal "le citoyen est prévenu (notification)" "$(jget "$BODY" 0.type)" "rdv_reporte"
contient "avec le motif" "$(jget "$BODY" 0.message)" "Absence de l'agent"

echo "== 7. Récapitulatif avec pièces à apporter =="
api POST /api/procedures "$AGT" "{\"serviceId\":$SA,\"nom\":\"Recette rendez-vous $ID_UNIQUE\",\"documentsRequis\":[{\"libelle\":\"Livret de famille\",\"exemplaires\":2,\"ancienneteMaxMois\":3,\"ouObtenir\":\"Fokontany\"},{\"libelle\":\"Photo\",\"obligatoire\":false}]}"
code_attendu "l'agent crée une procédure avec 2 pièces" 201; PR=$(jget "$BODY" id)
D6=$(demande); reserver "$D6" "$C3" "$PR"; code_attendu "réservation avec procédure -> 201" 201; R6=$(jget "$BODY" id)
api GET "/api/rendez-vous/$R6/recapitulatif" "$CIT"; code_attendu "le citoyen lit le récapitulatif" 200
egal "heure de début" "$(jget "$BODY" quand.heureDebut)" "09:30"
egal "heure de fin" "$(jget "$BODY" quand.heureFin)" "10:00"
[ -n "$(jget "$BODY" lieu.service)" ] && ok "lieu : service renseigné" || ko "lieu" "$BODY"
egal "procédure" "$(jget "$BODY" procedure.id)" "$PR"
egal "1re pièce" "$(jget "$BODY" piecesAApporter.0.libelle) x$(jget "$BODY" piecesAApporter.0.exemplaires) <$(jget "$BODY" piecesAApporter.0.ancienneteMaxMois) mois ($(jget "$BODY" piecesAApporter.0.ouObtenir))" "Livret de famille x2 <3 mois (Fokontany)"
egal "2e pièce facultative" "$(jget "$BODY" piecesAApporter.1.obligatoire)" "false"
egal "modifiable par le citoyen (loin du rendez-vous)" "$(jget "$BODY" modifiableParLeCitoyen)" "true"
api PATCH "/api/rendez-vous/$R6/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "l'agent confirme" 200
api GET /api/notifications "$CIT"; egal "notification de confirmation" "$(jget "$BODY" 0.type)" "rdv_confirme"
contient "elle liste les pièces à apporter" "$(jget "$BODY" 0.message)" "Livret de famille (2 exemplaires, de moins de 3 mois)"
api GET "/api/rendez-vous/$R3/recapitulatif" "$CIT"; egal "sans procédure : aucune pièce" "$(jget "$BODY" piecesAApporter)" "[]"
api GET "/api/rendez-vous/999999999/recapitulatif" "$CIT"; code_attendu "rendez-vous inconnu -> 404" 404
api PATCH "/api/rendez-vous/$R6" "$CIT" "{\"procedureId\":999999999}"; code_attendu "procédure inconnue -> 404" 404
api PATCH "/api/rendez-vous/$R6" "$CIT" '{"procedureId":null}'; egal "le citoyen retire la procédure" "$(jget "$BODY" procedureId)" "null"

echo "== 8. Journal =="
delta creneaux_generes "$B_GEN" 1 "1 génération journalisée (la 2e n'a rien créé)"
delta rdv_modifie "$B_MODIF" 2 "2 modifications journalisées"
delta rdv_reporte "$B_REPORT" 1 "1 report journalisé"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" -eq 0 ]
