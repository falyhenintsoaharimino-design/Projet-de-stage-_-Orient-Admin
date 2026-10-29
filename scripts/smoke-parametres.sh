#!/usr/bin/env bash
# Recette de l'étape 10a : paramètres (EF-46 : durée par défaut des créneaux, jours fériés, modèles de messages)
# et sauvegarde / restauration de la base (EF-47, ENF-15).
# Usage : bash scripts/smoke-parametres.sh        (à la racine du projet, symfony serve lancé)
# Prérequis : migration Version20261028120000 appliquée, comptes de test créés (test@test.com citoyen, agent@test.com rattaché
# à un service, admin@test.com), php et curl dans le PATH, pg_dump et pg_restore utilisables (PATH ou PG_DUMP_BIN / PG_RESTORE_BIN).
# Les paramètres sont remis dans leur état de départ à la fin. Restent en base : quelques créneaux de test, un service de test
# désactivé, une sauvegarde dans var/sauvegardes. La base « orient_admin_recette » est créée puis supprimée.

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
ID_UNIQUE="$RANDOM$RANDOM"
BASE_VERIF="orient_admin_recette"

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
# date AAAA-MM-JJ à N jours de demain, dans le fuseau de l'application (APP_TIMEZONE), pas celui du shell
jour() { php -r '$tz = new DateTimeZone(getenv("APP_TIMEZONE") ?: "Indian/Antananarivo"); echo (new DateTime("tomorrow +" . $argv[1] . " days", $tz))->format("Y-m-d");' -- "$1"; }

echo "== Connexion =="
ADM=$(login admin@test.com);  [ -n "$ADM" ] && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);   [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);  [ -n "$AGT" ] && ok "login agent" || ko "login agent" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
api GET /api/me "$AGT"; SA=$(jget "$BODY" service)
[ -n "$SA" ] && ok "l'agent est rattaché au service $SA" || { ko "agent sans service" "$BODY"; exit 1; }
B_PARAM=$(nb parametres_modifies); B_SAUV=$(nb sauvegarde_creee); B_TELE=$(nb sauvegarde_telechargee)

echo "== 1. Accès =="
api GET /api/admin/parametres "";   code_attendu "paramètres sans jeton -> 401" 401
api GET /api/admin/parametres "$CIT"; code_attendu "paramètres : citoyen -> 403" 403
api GET /api/admin/parametres "$AGT"; code_attendu "paramètres : agent -> 403" 403
api PUT /api/admin/parametres "$AGT" '{"dureeCreneauDefautMinutes":20}'; code_attendu "modifier : agent -> 403" 403
api GET /api/jours-feries "$CIT";   code_attendu "jours fériés : citoyen -> 403" 403
api GET /api/jours-feries "$AGT";   code_attendu "jours fériés : agent -> 200" 200
api GET /api/admin/sauvegardes "$AGT"; code_attendu "sauvegardes : agent -> 403" 403
api POST /api/admin/sauvegardes "$CIT"; code_attendu "créer une sauvegarde : citoyen -> 403" 403
api GET /api/admin/sauvegardes ""; code_attendu "sauvegardes sans jeton -> 401" 401

echo "== 2. Lecture et validation des paramètres =="
api GET /api/admin/parametres "$ADM"; code_attendu "l'admin lit les paramètres" 200
DUREE0=$(jget "$BODY" dureeCreneauDefautMinutes); FERIES0=$(jget "$BODY" joursFeries)
[ -n "$DUREE0" ] && ok "durée par défaut actuelle : $DUREE0 min" || ko "durée par défaut" "$BODY"
egal "8 modèles de messages" "$(printf '%s' "$(jget "$BODY" modelesMessages)" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true));')" "8"
contient "variables du modèle rdv_confirme" "$(jget "$BODY" modelesMessages.rdv_confirme.variables)" "quand"
for mauvais in '{}' '{"inconnu":1}' '{"dureeCreneauDefautMinutes":4}' '{"dureeCreneauDefautMinutes":"30"}' \
  '{"joursFeries":[{"date":"2026-13-45","libelle":"x"}]}' '{"joursFeries":[{"date":"2026-12-25"}]}' \
  '{"modelesMessages":{"rdv_inconnu":{"sujet":"a","message":"b"}}}' '{"modelesMessages":{"rdv_confirme":{"sujet":"a"}}}' \
  '{"modelesMessages":{"rdv_confirme":{"sujet":"a","message":"Bonjour {prenom}"}}}'; do
  api PUT /api/admin/parametres "$ADM" "$mauvais"; code_attendu "refusé (400) : ${mauvais:0:70}" 400
done

echo "== 3. Durée par défaut et jours fériés =="
FERIE=$(jour 41); DEB=$(jour 40); FIN=$(jour 42)
api GET "/api/services/$SA" "$ADM"; DUREE_SA0=$(jget "$BODY" dureeCreneauMinutes)
api PUT /api/admin/parametres "$ADM" "{\"dureeCreneauDefautMinutes\":45,\"joursFeries\":[{\"date\":\"$FERIE\",\"libelle\":\"Férié de recette\"}]}"
code_attendu "l'admin enregistre durée 45 et un jour férié" 200
egal "durée enregistrée" "$(jget "$BODY" dureeCreneauDefautMinutes)" "45"
egal "jour férié enregistré" "$(jget "$BODY" joursFeries.0.date)" "$FERIE"
delta parametres_modifies "$B_PARAM" 1 "journal : parametres_modifies"
api GET "/api/jours-feries?du=$DEB&au=$FIN" "$AGT"; egal "l'agent lit le jour férié" "$(jget "$BODY" 0.date)" "$FERIE"
api GET "/api/jours-feries?du=juin" "$AGT"; code_attendu "filtre invalide -> 400" 400

api POST /api/services "$ADM" "{\"nom\":\"Recette paramètres $ID_UNIQUE\"}"; code_attendu "nouveau service sans durée -> 201" 201
egal "il reçoit la durée par défaut" "$(jget "$BODY" dureeCreneauMinutes)" "45"; NS=$(jget "$BODY" id)
api POST /api/services "$ADM" "{\"nom\":\"Recette paramètres bis $ID_UNIQUE\",\"dureeCreneauMinutes\":20}"; code_attendu "avec durée explicite -> 201" 201
egal "la durée explicite est gardée" "$(jget "$BODY" dureeCreneauMinutes)" "20"; NS2=$(jget "$BODY" id)
api POST /api/services "$ADM" '{"nom":"x","dureeCreneauMinutes":3}'; code_attendu "durée invalide -> 400" 400
api GET "/api/services/$SA" "$ADM"; egal "le service existant $SA garde son réglage (durée inchangée)" "$(jget "$BODY" dureeCreneauMinutes)" "$DUREE_SA0"
for s in "$NS" "$NS2"; do api DELETE "/api/services/$s" "$ADM"; done

api POST /api/creneaux/generer "$AGT" "{\"dateDebut\":\"$DEB\",\"dateFin\":\"$FIN\",\"heureDebut\":\"09:00\",\"heureFin\":\"09:30\",\"dureeMinutes\":30,\"joursFermes\":[]}"
code_attendu "génération sur 3 jours dont un férié -> 201" 201
egal "2 jours ouverts seulement" "$(jget "$BODY" joursOuverts)" "2"
egal "le férié est signalé comme exclu" "$(jget "$BODY" joursFeriesExclus.0)" "$FERIE"
api GET "/api/creneaux?serviceId=$SA&date=$FERIE" "$AGT"; egal "aucun créneau le jour férié" "$(printf '%s' "$BODY" | php -r 'echo count((array) json_decode(stream_get_contents(STDIN), true));')" "0"
api GET "/api/creneaux?serviceId=$SA&date=$DEB" "$AGT"; egal "un créneau la veille du férié" "$(printf '%s' "$BODY" | php -r 'echo count((array) json_decode(stream_get_contents(STDIN), true));')" "1"

echo "== 4. Modèle de message personnalisé =="
LOIN=$(jour 33)
api PUT /api/admin/parametres "$ADM" '{"modelesMessages":{"rdv_confirme":{"sujet":"Confirmé par {service}","message":"Bonne nouvelle ! Rendez-vous {quand}, {lieu}.{pieces}"}}}'
code_attendu "l'admin personnalise rdv_confirme" 200
egal "modèle marqué personnalisé" "$(jget "$BODY" modelesMessages.rdv_confirme.personnalise)" "true"
egal "les autres restent par défaut" "$(jget "$BODY" modelesMessages.rdv_refuse.personnalise)" "false"
api POST /api/demandes "$CIT" "{\"texte\":\"Recette paramètres $ID_UNIQUE\",\"serviceId\":$SA}"; D=$(jget "$BODY" id)
[ -n "$D" ] && ok "demande $D créée" || ko "demande" "$BODY"
api POST /api/creneaux "$AGT" "{\"date\":\"$LOIN\",\"heures\":[\"07:45\"],\"capacite\":3}"; CL=$(jget "$BODY" crees.0.id)
[ -z "$CL" ] && { api GET "/api/creneaux?serviceId=$SA&date=$LOIN" "$AGT"; CL=$(php -r '$d=json_decode($argv[1],true); foreach ((array)$d as $c) { if (($c["heure"] ?? "") === "07:45") { echo $c["id"]; } }' -- "$BODY"); }
[ -n "$CL" ] && ok "créneau du $LOIN 07:45" || ko "créneau" "$BODY"
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$D,\"creneauId\":$CL}"; code_attendu "réservation -> 201" 201; R=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "l'agent confirme" 200
api GET /api/notifications "$CIT"
MSG=$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $l=$d["items"] ?? $d; foreach ($l as $n) { if (($n["type"] ?? "") === "rdv_confirme") { echo $n["message"]; break; } }')
contient "la notification utilise le modèle personnalisé" "$MSG" "Bonne nouvelle ! Rendez-vous du"
case "$MSG" in *"{"*) ko "variables remplacées" "$MSG";; *) ok "toutes les variables sont remplacées";; esac
api PUT /api/admin/parametres "$ADM" '{"modelesMessages":{"rdv_confirme":null}}'; code_attendu "retour au modèle par défaut (null)" 200
egal "plus personnalisé" "$(jget "$BODY" modelesMessages.rdv_confirme.personnalise)" "false"
api PATCH "/api/rendez-vous/$R/annuler" "$AGT"; code_attendu "nettoyage : l'agent annule le rendez-vous de test" 200

echo "== 5. Sauvegarde et restauration =="
api POST /api/admin/sauvegardes "$ADM"
if [ "$CODE" = "201" ]; then
  ok "sauvegarde créée"
  NOM=$(jget "$BODY" nom); contient "nom horodaté" "$NOM" "orient-admin-"
  [ "$(jget "$BODY" taille)" -gt 1000 ] 2>/dev/null && ok "taille plausible ($(jget "$BODY" taille) octets)" || ko "taille" "$BODY"
  delta sauvegarde_creee "$B_SAUV" 1 "journal : sauvegarde_creee"
  api GET /api/admin/sauvegardes "$ADM"; egal "la sauvegarde est dans la liste" "$(jget "$BODY" 0.nom)" "$NOM"
  FICHIER_TELECHARGE=$(mktemp)
  CODE=$(curl -s -o "$FICHIER_TELECHARGE" -w '%{http_code}' -H "Authorization: Bearer $ADM" "$BASE/api/admin/sauvegardes/$NOM")
  code_attendu "téléchargement de la sauvegarde" 200
  egal "format pg_dump" "$(head -c 5 "$FICHIER_TELECHARGE")" "PGDMP"
  rm -f "$FICHIER_TELECHARGE"
  delta sauvegarde_telechargee "$B_TELE" 1 "journal : sauvegarde_telechargee"
  api GET "/api/admin/sauvegardes/orient-admin-20200101-000000.dump" "$ADM"; code_attendu "sauvegarde inconnue -> 404" 404
  api GET "/api/admin/sauvegardes/..%2F..%2F.env" "$ADM"; code_attendu "sortie du dossier refusée -> 404" 404

  SORTIE=$(php bin/console app:restauration "$NOM" --base="$BASE_VERIF" --no-interaction 2>&1); RC=$?
  [ "$RC" = "0" ] && ok "restauration dans la base $BASE_VERIF" || ko "restauration" "${SORTIE:0:300}"
  # Comparaison base courante / base restaurée (le journal est exclu : la sauvegarde elle-même y ajoute une ligne).
  L1=$(printf '%s\n' "$SORTIE" | grep "Contrôle courante" | sed 's/.*: //; s/ journal=[0-9]*//')
  L2=$(printf '%s\n' "$SORTIE" | grep "Contrôle restauree" | sed 's/.*: //; s/ journal=[0-9]*//')
  [ -n "$L1" ] && egal "même contenu après restauration ($L1)" "$L2" "$L1" || ko "lignes de contrôle absentes" "${SORTIE:0:300}"
  php bin/console app:restauration "$NOM" --no-interaction >/dev/null 2>&1; [ "$?" != "0" ] && ok "sans --oui, la base courante n'est pas remplacée" || ko "remplacement sans confirmation" "code 0"
  php bin/console dbal:run-sql "DROP DATABASE IF EXISTS $BASE_VERIF" >/dev/null 2>&1 && ok "base de vérification supprimée" || ko "suppression de $BASE_VERIF" "à faire à la main : DROP DATABASE $BASE_VERIF"
else
  ko "création de la sauvegarde" "code $CODE ${BODY:0:300} (pg_dump introuvable ? voir PG_DUMP_BIN dans .env.local)"
fi

echo "== 6. Remise des paramètres dans leur état de départ =="
api PUT /api/admin/parametres "$ADM" "{\"dureeCreneauDefautMinutes\":$DUREE0,\"joursFeries\":$FERIES0}"; code_attendu "paramètres remis à zéro" 200
egal "durée remise" "$(jget "$BODY" dureeCreneauDefautMinutes)" "$DUREE0"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
