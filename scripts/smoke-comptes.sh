#!/usr/bin/env bash
# Recette de l'étape 6 : sécurité et comptes (consentement, profil, export, suppression de compte,
# services inactifs, suppression logique, limitation des tentatives de connexion).
# Usage : bash scripts/smoke-comptes.sh        (à la racine du projet, symfony serve lancé)
# Prérequis : migration appliquée, comptes de test créés, php et curl dans le PATH.
# La limitation de connexion est testée EN DERNIER (6 échecs sur un faux e-mail) : attendre
# 1 minute avant de lancer un autre script de recette.

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

# login EMAIL [MOT_DE_PASSE] : affiche le jeton (vide si refus), laisse le code dans $CODE
login() { api POST /api/login_check "" "{\"email\":\"$1\",\"password\":\"${2:-$PASS}\"}"; jget "$BODY" token; }
nb() { api GET "/api/journal?action=$1&limite=1" "$ADM"; jget "$BODY" total; }
delta() { local apres; apres=$(nb "$1"); egal "$4" "$((apres - $2))" "$3"; }

echo "== Connexion =="
ADM=$(login admin@test.com);  [ -n "$ADM" ] && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);   [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);  [ -n "$AGT" ] && ok "login agent" || ko "login agent" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
B_PROFIL=$(nb profil_modifie); B_EXPORT=$(nb donnees_exportees); B_SUPPR=$(nb compte_supprime); B_SERV=$(nb service_desactive)

echo "== 1. Inscription et consentement =="
EMAIL="recette-$RANDOM$RANDOM@test.com"
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"; code_attendu "sans consentement -> 400" 400
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"consentement\":false}"; code_attendu "consentement false -> 400" 400
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"pas-un-email\",\"password\":\"$PASS\",\"consentement\":true}"; code_attendu "e-mail invalide -> 400" 400
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"consentement\":true,\"telephone\":\"+261 34 12 345 67\"}"; code_attendu "avec consentement -> 201" 201
U=$(login "$EMAIL"); [ -n "$U" ] && ok "login du nouveau compte" || ko "login du nouveau compte" "$BODY"
api GET /api/me "$U"
[ -n "$(jget "$BODY" consentementLe)" ] && ok "date de consentement enregistrée" || ko "date de consentement" "$BODY"
egal "téléphone normalisé" "$(jget "$BODY" telephone)" "+261341234567"
egal "langue par défaut" "$(jget "$BODY" langue)" "fr"
egal "canal par défaut" "$(jget "$BODY" canalNotification)" "email"

echo "== 2. Profil (PATCH /api/me) =="
api PATCH /api/me "" '{"langue":"mg"}'; code_attendu "sans jeton -> 401" 401
api PATCH /api/me "$U" '{}'; code_attendu "corps vide -> 400" 400
api PATCH /api/me "$U" '{"langue":"xx"}'; code_attendu "langue inconnue -> 400" 400
api PATCH /api/me "$U" '{"telephone":null,"canalNotification":"sms"}'; code_attendu "sms sans téléphone -> 400" 400
api PATCH /api/me "$U" '{"langue":"mg","canalNotification":"sms","prenom":"Nouveau","role":"admin","email":"pirate@test.com"}'; code_attendu "modification valide -> 200" 200
egal "langue modifiée" "$(jget "$BODY" langue)" "mg"
egal "canal modifié" "$(jget "$BODY" canalNotification)" "sms"
egal "e-mail inchangé (champ ignoré)" "$(jget "$BODY" email)" "$EMAIL"
case "$BODY" in *ROLE_ADMIN*) ko "rôle inchangé" "ROLE_ADMIN présent";; *) ok "rôle inchangé (champ ignoré)";; esac
delta profil_modifie "$B_PROFIL" 1 "modification journalisée"

echo "== 3. Export de mes données =="
api GET /api/me/donnees "";  code_attendu "sans jeton -> 401" 401
api GET /api/me/donnees "$U"; code_attendu "avec jeton -> 200" 200
egal "profil dans l'export" "$(jget "$BODY" profil.email)" "$EMAIL"
case "$BODY" in *'"password"'*) ko "mot de passe absent de l'export" "présent";; *) ok "mot de passe absent de l'export";; esac
delta donnees_exportees "$B_EXPORT" 1 "export journalisé"

echo "== 4. Services inactifs et suppression logique =="
api POST /api/services "$ADM" "{\"nom\":\"Recette étape 6 $RANDOM\",\"actif\":true}"; code_attendu "création (admin) -> 201" 201
SID=$(jget "$BODY" id)
api GET "/api/services/$SID" ""; code_attendu "service actif visible du public" 200
api DELETE "/api/services/$SID" "$CIT"; code_attendu "suppression par un citoyen -> 403" 403
api DELETE "/api/services/$SID" "$ADM"; code_attendu "suppression par l'admin -> 204" 204
[ -z "$BODY" ] && ok "204 sans corps" || ko "204 sans corps" "$BODY"
api GET "/api/services/$SID" "";     code_attendu "public : service inactif -> 404" 404
api GET "/api/services/$SID" "$CIT"; code_attendu "citoyen : service inactif -> 404" 404
api GET "/api/services/$SID" "$ADM"; code_attendu "admin : service inactif visible" 200
egal "service désactivé, pas effacé" "$(jget "$BODY" actif)" "false"
api GET /api/services ""
case "$BODY" in *"\"id\":$SID,"*) ko "absent de la liste publique" "présent";; *) ok "absent de la liste publique";; esac
api GET /api/services "$ADM"
case "$BODY" in *"\"id\":$SID,"*) ok "présent dans la liste de l'admin";; *) ko "présent dans la liste de l'admin" "absent";; esac
api DELETE "/api/services/$SID" "$ADM"; code_attendu "répéter la suppression -> 204" 204
api DELETE "/api/services/999999999" "$ADM"; code_attendu "service inconnu -> 404" 404
delta service_desactive "$B_SERV" 2 "désactivations journalisées"

echo "== 5. Suppression du compte =="
api DELETE /api/me "$AGT" "{\"motDePasse\":\"$PASS\"}"; code_attendu "un agent ne se supprime pas ici -> 403" 403
api DELETE /api/me "$U" '{}'; code_attendu "sans mot de passe -> 400" 400
api DELETE /api/me "$U" '{"motDePasse":"faux"}'; code_attendu "mauvais mot de passe -> 403" 403
api GET /api/me "$U"; code_attendu "compte toujours là" 200
api DELETE /api/me "$U" "{\"motDePasse\":\"$PASS\"}"; code_attendu "suppression -> 204" 204
[ -z "$BODY" ] && ok "204 sans corps" || ko "204 sans corps" "$BODY"
api GET /api/me "$U"; code_attendu "ancien jeton refusé -> 401" 401
login "$EMAIL" >/dev/null; code_attendu "reconnexion impossible -> 401" 401
delta compte_supprime "$B_SUPPR" 1 "suppression journalisée"

echo "== 6. Limitation des tentatives de connexion =="
FAUX="inconnu-$RANDOM$RANDOM@test.com"
for i in 1 2 3 4 5; do login "$FAUX" mauvais >/dev/null; code_attendu "tentative $i -> 401" 401; done
SORTIE=$(curl -s -i -X POST "$BASE/api/login_check" -H 'Content-Type: application/json' -d "{\"email\":\"$FAUX\",\"password\":\"mauvais\"}")
case "$SORTIE" in *" 429"*) ok "6e tentative -> 429";; *) ko "6e tentative -> 429" "$(printf '%s' "$SORTIE" | head -1)";; esac
case "$(printf '%s' "$SORTIE" | tr 'A-Z' 'a-z')" in *"retry-after:"*) ok "en-tête Retry-After présent";; *) ko "en-tête Retry-After" "absent";; esac

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
