#!/usr/bin/env bash
# Recette de l'étape 9 : notifications (messages de l'agent, pièces manquantes, changement de procédure, SMS simulé,
# rappel de la veille par la commande app:rappels-rdv).
# Usage : bash scripts/smoke-notifications.sh        (à la racine du projet, symfony serve lancé)
# Prérequis : migration Version20261027120000 appliquée, comptes de test créés (test@test.com citoyen, agent@test.com rattaché
# à un service, admin@test.com), php et curl dans le PATH. Le citoyen de test passe en canal « sms » pendant la recette,
# puis revient en « email ». Les créneaux, demandes et procédures créés restent en base : sans danger.

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
ID_UNIQUE="$RANDOM$RANDOM"

ok()  { echo "OK      $1"; NB_OK=$((NB_OK+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; NB_KO=$((NB_KO+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) ${BODY:0:200}"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }
contient() { case "$2" in *"$3"*) ok "$1";; *) ko "$1" "'$3' absent de '${2:0:240}'";; esac; }

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
demande() { api POST /api/demandes "$CIT" "{\"texte\":\"Recette notifications $ID_UNIQUE\",\"serviceId\":$SA}"; jget "$BODY" id; }
nb_sms() { api GET "/api/sms-simules?limite=200" "$ADM"; printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count((array)$d);'; }
# nombre de notifications d'un type pour le citoyen
nb_type() { api GET /api/notifications "$CIT"; printf '%s' "$BODY" | php -r '$n=0; foreach ((array)json_decode(stream_get_contents(STDIN),true) as $x) { if (is_array($x) && ($x["type"] ?? "") === $argv[1]) { $n++; } } echo $n;' -- "$1"; }

echo "== Connexion =="
ADM=$(login admin@test.com);  [ -n "$ADM" ] && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);   [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);  [ -n "$AGT" ] && ok "login agent" || ko "login agent" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
api GET /api/me "$AGT"; SA=$(jget "$BODY" service)
[ -n "$SA" ] && ok "l'agent est rattaché au service $SA" || { ko "agent sans service" "$BODY"; exit 1; }
B_MSG=$(nb message_agent_envoye); B_PIECES=$(nb rdv_pieces_manquantes); B_RAPPELS=$(nb rappels_envoyes)

echo "== 1. Canal SMS du citoyen =="
api PATCH /api/me "$CIT" '{"telephone":"+261 34 12 345 67","canalNotification":"sms"}'; code_attendu "le citoyen choisit le canal sms" 200
egal "canal enregistré" "$(jget "$BODY" canalNotification)" "sms"
api GET /api/sms-simules "$AGT"; code_attendu "agent : boîte d'envoi des SMS -> 403" 403
api GET /api/sms-simules "$CIT"; code_attendu "citoyen : boîte d'envoi des SMS -> 403" 403
api GET /api/sms-simules "" ; code_attendu "sans jeton -> 401" 401
S0=$(nb_sms)

echo "== 2. Messages de l'agent au citoyen =="
D=$(demande); [ -n "$D" ] && ok "demande $D créée (orientée vers le service $SA)" || ko "demande" "$BODY"
api POST "/api/demandes/$D/messages" "$CIT" '{"message":"Je peux écrire ?"}'; code_attendu "citoyen n'écrit pas par cette route -> 403" 403
api POST "/api/demandes/$D/messages" "$AGT" '{"message":"   "}'; code_attendu "message vide -> 400" 400
api POST "/api/demandes/999999999/messages" "$AGT" '{"message":"x"}'; code_attendu "demande inconnue -> 404" 404
api POST "/api/demandes/$D/messages" "$AGT" '{"sujet":"Pièce à ajouter","message":"Merci d'"'"'apporter un justificatif de domicile."}'; code_attendu "l'agent écrit au citoyen -> 201" 201
egal "sujet" "$(jget "$BODY" sujet)" "Pièce à ajouter"
api GET "/api/demandes/$D/messages" "$CIT"; code_attendu "le citoyen lit les messages" 200
contient "contenu du message" "$(jget "$BODY" 0.message)" "justificatif de domicile"
api GET /api/notifications "$CIT"
egal "notification reçue" "$(jget "$BODY" 0.type)" "message_agent"
contient "avec l'origine du message" "$(jget "$BODY" 0.message)" "Message du service"
egal "un SMS simulé est parti (canal sms)" "$(( $(nb_sms) - S0 ))" "1"
api GET "/api/sms-simules?limite=1" "$ADM"
contient "SMS : texte" "$(jget "$BODY" 0.message)" "justificatif de domicile"
egal "SMS : téléphone du citoyen" "$(jget "$BODY" 0.telephone)" "+261341234567"
egal "le SMS n'apparaît pas dans le centre de notifications" "$(nb_type sms)" "0"

echo "== 3. Pièces manquantes et changement de procédure =="
api POST /api/procedures "$AGT" "{\"serviceId\":$SA,\"nom\":\"Recette notifications $ID_UNIQUE\",\"frais\":\"2000 Ar\",\"documentsRequis\":[{\"libelle\":\"Livret de famille\",\"exemplaires\":2,\"ancienneteMaxMois\":3}]}"
code_attendu "procédure créée" 201; PR=$(jget "$BODY" id)
LOIN=$(date -d "+$((RANDOM % 600 + 400)) days" +%F)
api POST /api/creneaux "$AGT" "{\"date\":\"$LOIN\",\"heures\":[\"08:00\"],\"capacite\":3}"; code_attendu "créneau lointain" 201; CL=$(jget "$BODY" crees.0.id)
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$D,\"creneauId\":$CL,\"procedureId\":$PR}"; code_attendu "réservation avec procédure -> 201" 201; R=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "l'agent confirme" 200
api POST "/api/rendez-vous/$R/pieces-manquantes" "$CIT" '{"pieces":["Photo"]}'; code_attendu "citoyen -> 403" 403
api POST "/api/rendez-vous/$R/pieces-manquantes" "$AGT" '{"pieces":[]}'; code_attendu "liste vide -> 400" 400
api POST "/api/rendez-vous/$R/pieces-manquantes" "$AGT" '{"pieces":["Photo d'"'"'identité","Justificatif de domicile"],"message":"Sans eux le dossier sera refusé."}'; code_attendu "l'agent signale les pièces manquantes" 200
api GET /api/notifications "$CIT"
egal "notification « pièces manquantes »" "$(jget "$BODY" 0.type)" "pieces_manquantes"
contient "liste des pièces" "$(jget "$BODY" 0.message)" "il manque : Photo d'identité ; Justificatif de domicile"
P0=$(nb_type procedure_modifiee)
api PUT "/api/procedures/$PR" "$AGT" '{"frais":"Gratuit"}'; code_attendu "l'agent change les frais" 200
egal "alerte « changement de procédure »" "$(( $(nb_type procedure_modifiee) - P0 ))" "1"
api GET /api/notifications "$CIT"; contient "elle cite le champ modifié" "$(jget "$BODY" 0.message)" "frais"
api PUT "/api/procedures/$PR" "$AGT" '{"source":"Décret 2026"}'
egal "un changement de source ne prévient personne" "$(( $(nb_type procedure_modifiee) - P0 ))" "1"
api DELETE "/api/procedures/$PR" "$AGT"; code_attendu "désactivation -> 204" 204
egal "alerte de désactivation" "$(( $(nb_type procedure_modifiee) - P0 ))" "2"
api GET /api/notifications "$CIT"; contient "« n'est plus proposée »" "$(jget "$BODY" 0.message)" "n'est plus proposée"

echo "== 4. Rappel de la veille (app:rappels-rdv) =="
# « demain » dans le fuseau de l'application (APP_TIMEZONE, Indian/Antananarivo par défaut), pas celui du shell
DEMAIN=$(php -r '$tz = new DateTimeZone(getenv("APP_TIMEZONE") ?: "Indian/Antananarivo"); echo (new DateTime("tomorrow", $tz))->format("Y-m-d");')
api POST /api/creneaux "$AGT" "{\"date\":\"$DEMAIN\",\"heures\":[\"06:30\"]}"
CD=$(jget "$BODY" crees.0.id)
[ -z "$CD" ] && { api GET "/api/creneaux?serviceId=$SA&date=$DEMAIN" "$AGT"; CD=$(php -r '$d=json_decode($argv[1],true); foreach ((array)$d as $c) { if (($c["heure"] ?? "") === "06:30") { echo $c["id"]; } }' -- "$BODY"); }
[ -n "$CD" ] && ok "créneau de demain 06:30" || ko "créneau de demain" "$BODY"
D2=$(demande)
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$D2,\"creneauId\":$CD}"; code_attendu "réservation pour demain -> 201" 201; R2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R2/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "l'agent confirme" 200
RP0=$(nb_type rdv_rappel)
SORTIE=$(php bin/console app:rappels-rdv --dry-run 2>&1); contient "simulation : rien n'est envoyé" "$SORTIE" "seraient envoyés"
egal "aucun rappel après la simulation" "$(( $(nb_type rdv_rappel) - RP0 ))" "0"
php bin/console app:rappels-rdv --jours=abc >/dev/null 2>&1; egal "option invalide refusée (code de sortie 2)" "$?" "2"
SMS_AVANT=$(nb_sms)
SORTIE=$(php bin/console app:rappels-rdv 2>&1); contient "rappels envoyés" "$SORTIE" "rappel(s) envoyé(s)"
egal "le citoyen a reçu son rappel" "$(( $(nb_type rdv_rappel) - RP0 ))" "1"
api GET /api/notifications "$CIT"; contient "texte du rappel" "$(jget "$BODY" 0.message)" "Rappel : vous avez rendez-vous"
egal "rappel aussi par SMS simulé" "$(( $(nb_sms) - SMS_AVANT ))" "1"
SORTIE=$(php bin/console app:rappels-rdv 2>&1); contient "rejouer n'envoie plus rien" "$SORTIE" "0 rappel(s) envoyé(s)"
egal "pas de doublon" "$(( $(nb_type rdv_rappel) - RP0 ))" "1"
api PATCH "/api/rendez-vous/$R2/annuler" "$AGT"; code_attendu "nettoyage : l'agent annule le rendez-vous de test" 200

echo "== 5. Retour au canal e-mail =="
api PATCH /api/me "$CIT" '{"canalNotification":"email"}'; code_attendu "le citoyen revient à l'e-mail" 200
S1=$(nb_sms)
api POST "/api/demandes/$D/messages" "$AGT" '{"message":"Message envoyé par e-mail."}'; code_attendu "nouveau message -> 201" 201
egal "plus de SMS pour ce citoyen" "$(( $(nb_sms) - S1 ))" "0"
api GET /api/notifications "$CIT"; egal "mais la notification de l'application reste" "$(jget "$BODY" 0.type)" "message_agent"

echo "== 6. Journal =="
delta message_agent_envoye "$B_MSG" 2 "2 messages journalisés"
delta rdv_pieces_manquantes "$B_PIECES" 1 "1 alerte de pièces manquantes journalisée"
A=$(nb rappels_envoyes); [ "$((A - B_RAPPELS))" -ge 1 ] && ok "rappels journalisés" || ko "rappels journalisés" "avant $B_RAPPELS, après $A"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" -eq 0 ]
