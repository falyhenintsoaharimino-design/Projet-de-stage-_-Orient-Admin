#!/usr/bin/env bash
# Recette de l'étape 4 : gestion admin (utilisateurs, rôles) + mot de passe oublié.
# Usage : bash scripts/smoke-admin.sh            (à lancer à la racine du projet)
#         SERVICE_ID=2 bash scripts/smoke-admin.sh   (si l'agent n'est pas sur le service 1)
# Prérequis : migration appliquée (php bin/console doctrine:migrations:migrate),
#             symfony serve lancé, comptes de test créés, php dans le PATH,
#             APP_SECRET renseigné dans .env.local.
# Laisse quelques comptes de test (recette-...@test.com) dans la base (sans danger).

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

# login EMAIL [MOT_DE_PASSE] : affiche le jeton (vide si refus), laisse le code dans $CODE
login() { api POST /api/login_check "" "{\"email\":\"$1\",\"password\":\"${2:-$PASS}\"}"; jget "$BODY" token; }
# jeton EMAIL [--expire] : jeton de réinitialisation fabriqué par la console
jeton() { php bin/console app:lien-reinitialisation "$1" --jeton $2 2>/dev/null | tr -d '\r\n'; }
# nb ACTION : nombre d'entrées du journal pour cette action (lu avec le jeton admin)
nb() { api GET "/api/journal?action=$1&limite=1" "$ADM"; jget "$BODY" total; }
delta() { local apres; apres=$(nb "$1"); egal "$4" "$((apres - $2))" "$3"; }
compte() { printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["items"] ?? []);'; }

echo "== Connexion =="
CIT=$(login test@test.com);         [ -n "$CIT" ] && ok "login citoyen"      || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);        [ -n "$AGT" ] && ok "login agent"        || ko "login agent" "$BODY"
ADM=$(login admin@test.com);        [ -n "$ADM" ] && ok "login admin"        || ko "login admin" "$BODY"
RESP=$(login responsable@test.com); [ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
{ [ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$ADM" ] || [ -z "$RESP" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
api GET /api/me "$ADM"; ADMID=$(jget "$BODY" id)

echo "== 1. Accès réservé à l'admin =="
for chemin in /api/admin/utilisateurs /api/admin/roles; do
  api GET "$chemin" "";        code_attendu "$chemin sans jeton -> 401" 401
  api GET "$chemin" "$CIT";    code_attendu "$chemin citoyen -> 403" 403
  api GET "$chemin" "$AGT";    code_attendu "$chemin agent -> 403" 403
  api GET "$chemin" "$RESP";   code_attendu "$chemin responsable -> 403" 403
  api GET "$chemin" "$ADM";    code_attendu "$chemin admin -> 200" 200
done
api POST /api/admin/utilisateurs "$CIT" '{}';       code_attendu "création par un citoyen -> 403" 403
api PATCH "/api/admin/utilisateurs/$ADMID/actif" "$AGT" '{"actif":false}'; code_attendu "désactivation par un agent -> 403" 403

echo "== 2. Rôles et liste =="
api GET /api/admin/roles "$ADM"
case "$BODY" in *citoyen*agent*|*agent*citoyen*) ok "rôles : citoyen et agent présents";; *) ko "rôles" "$BODY";; esac
api GET "/api/admin/utilisateurs?q=agent@test.com" "$ADM"
egal "q=agent@test.com : trouvé" "$(jget "$BODY" items.0.email)" "agent@test.com"
egal "agent : rôle" "$(jget "$BODY" items.0.role)" "agent"
case "$BODY" in *password*) ko "le mot de passe ne doit jamais sortir" "présent : $BODY";; *) ok "aucun mot de passe dans la réponse";; esac
api GET "/api/admin/utilisateurs?role=agent&limite=100" "$ADM"
TOUS=$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=!empty($d["items"]); foreach ($d["items"] ?? [] as $i) { if ($i["role"] !== "agent") { $ok=false; } } echo $ok ? "oui" : "non";')
egal "filtre role=agent : que des agents" "$TOUS" oui
api GET "/api/admin/utilisateurs?role=roleinexistant" "$ADM"
egal "role inexistant : aucun résultat" "$(jget "$BODY" total)" 0
api GET "/api/admin/utilisateurs?actif=true&limite=100" "$ADM"
TOUS=$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=!empty($d["items"]); foreach ($d["items"] ?? [] as $i) { if ($i["actif"] !== true) { $ok=false; } } echo $ok ? "oui" : "non";')
egal "filtre actif=true : que des comptes actifs" "$TOUS" oui
api GET "/api/admin/utilisateurs?serviceId=$SERVICE_ID&limite=100" "$ADM"
[ "$(jget "$BODY" total)" -ge 1 ] 2>/dev/null && ok "filtre serviceId=$SERVICE_ID : au moins 1" || ko "filtre serviceId" "$BODY"
api GET "/api/admin/utilisateurs?limite=2&page=1" "$ADM"
egal "limite=2 : 2 éléments" "$(compte "$BODY")" 2
TOTAL=$(jget "$BODY" total)
egal "pages = ceil(total/2)" "$(jget "$BODY" pages)" "$(( (TOTAL + 1) / 2 ))"
api GET "/api/admin/utilisateurs?page=99999" "$ADM"; code_attendu "page hors limites -> 200" 200
egal "page hors limites : liste vide" "$(compte "$BODY")" 0
api GET "/api/admin/utilisateurs?page=0" "$ADM";           code_attendu "page=0 -> 400" 400
api GET "/api/admin/utilisateurs?limite=101" "$ADM";       code_attendu "limite=101 -> 400" 400
api GET "/api/admin/utilisateurs?actif=peutetre" "$ADM";   code_attendu "actif invalide -> 400" 400
api GET "/api/admin/utilisateurs?serviceId=abc" "$ADM";    code_attendu "serviceId invalide -> 400" 400
api GET "/api/admin/utilisateurs/$ADMID" "$ADM";           code_attendu "détail d'un utilisateur -> 200" 200
egal "détail : e-mail" "$(jget "$BODY" email)" "admin@test.com"
api GET /api/admin/utilisateurs/999999999 "$ADM";          code_attendu "utilisateur inexistant -> 404" 404

echo "== 3. Création =="
B_CREE=$(nb utilisateur_cree)
EMAIL="recette-$RANDOM$RANDOM@test.com"
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"Recette\",\"prenom\":\"Admin\",\"email\":\"$EMAIL\",\"password\":\"motdepasse123\",\"role\":\"citoyen\"}"
code_attendu "création d'un citoyen -> 201" 201
UID1=$(jget "$BODY" id)
egal "création : actif par défaut" "$(jget "$BODY" actif)" true
egal "création : rôle" "$(jget "$BODY" role)" citoyen
case "$BODY" in *password*) ko "le mot de passe ne doit jamais sortir" "$BODY";; *) ok "aucun mot de passe dans la réponse";; esac
delta utilisateur_cree "$B_CREE" 1 "utilisateur_cree journalisé"
api GET "/api/journal?action=utilisateur_cree&limite=1" "$ADM"
egal "utilisateur_cree : auteur = l'admin" "$(jget "$BODY" items.0.utilisateur.email)" "admin@test.com"
egal "utilisateur_cree : détail" "$(jget "$BODY" items.0.details)" "cible=$UID1 role=citoyen"
TOK1=$(login "$EMAIL"); [ -n "$TOK1" ] && ok "le nouveau compte peut se connecter" || ko "connexion du nouveau compte" "$BODY"
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"Doublon\",\"email\":\"$EMAIL\",\"password\":\"motdepasse123\",\"role\":\"citoyen\"}"; code_attendu "e-mail déjà pris -> 409" 409
api POST /api/admin/utilisateurs "$ADM" '{"nom":"X","email":"pas-un-email","password":"motdepasse123","role":"citoyen"}'; code_attendu "e-mail invalide -> 400" 400
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"X\",\"email\":\"court-$RANDOM@test.com\",\"password\":\"abc\",\"role\":\"citoyen\"}"; code_attendu "mot de passe trop court -> 400" 400
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"X\",\"email\":\"role-$RANDOM@test.com\",\"password\":\"motdepasse123\",\"role\":\"roleinexistant\"}"; code_attendu "rôle inconnu -> 400" 400
api POST /api/admin/utilisateurs "$ADM" '{"nom":"X"}'; code_attendu "champs manquants -> 400" 400
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"X\",\"email\":\"ag-$RANDOM@test.com\",\"password\":\"motdepasse123\",\"role\":\"agent\"}"; code_attendu "agent sans service -> 400" 400
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"X\",\"email\":\"ag-$RANDOM@test.com\",\"password\":\"motdepasse123\",\"role\":\"agent\",\"serviceId\":999999999}"; code_attendu "service inexistant -> 400" 400
delta utilisateur_cree "$B_CREE" 1 "les créations refusées ne sont pas journalisées"
EMAIL_AG="recette-ag-$RANDOM$RANDOM@test.com"
api POST /api/admin/utilisateurs "$ADM" "{\"nom\":\"Recette\",\"prenom\":\"Agent\",\"email\":\"$EMAIL_AG\",\"password\":\"motdepasse123\",\"role\":\"agent\",\"serviceId\":$SERVICE_ID}"
code_attendu "création d'un agent avec service -> 201" 201
egal "agent : service rattaché" "$(jget "$BODY" service.id)" "$SERVICE_ID"

echo "== 4. Modification et rôles =="
B_MOD=$(nb utilisateur_modifie)
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"nom":"Recette2"}'; code_attendu "modification du nom -> 200" 200
egal "nom modifié" "$(jget "$BODY" nom)" "Recette2"
delta utilisateur_modifie "$B_MOD" 1 "utilisateur_modifie journalisé"
api GET "/api/journal?action=utilisateur_modifie&limite=1" "$ADM"
egal "utilisateur_modifie : détail" "$(jget "$BODY" items.0.details)" "cible=$UID1 champs=nom"
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"email":"test@test.com"}'; code_attendu "e-mail déjà pris -> 409" 409
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"nom":""}';               code_attendu "nom vide -> 400" 400
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{}';                       code_attendu "aucun champ -> 400" 400
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"role":"roleinexistant"}'; code_attendu "rôle inconnu -> 400" 400
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"role":"agent"}';         code_attendu "passer agent sans service -> 400" 400
api PATCH /api/admin/utilisateurs/999999999 "$ADM" '{"nom":"X"}';            code_attendu "utilisateur inexistant -> 404" 404
delta utilisateur_modifie "$B_MOD" 1 "les modifications refusées ne sont pas journalisées"
api PATCH "/api/admin/utilisateurs/$ADMID" "$ADM" '{"role":"citoyen"}';      code_attendu "changer son propre rôle -> 409" 409
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" "{\"role\":\"agent\",\"serviceId\":$SERVICE_ID}"; code_attendu "citoyen -> agent avec service" 200
egal "rôle agent" "$(jget "$BODY" role)" agent
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"role":"admin"}'; code_attendu "agent -> admin" 200
api GET /api/journal "$TOK1"; code_attendu "ancien jeton : droits admin immédiats" 200
api PATCH "/api/admin/utilisateurs/$UID1" "$ADM" '{"role":"citoyen","serviceId":null}'; code_attendu "retour citoyen sans service" 200
egal "service retiré" "$(jget "$BODY" service)" "null"
api GET /api/journal "$TOK1"; code_attendu "ancien jeton : droits retirés -> 403" 403

echo "== 5. Activation / désactivation =="
B_DES=$(nb utilisateur_desactive); B_ACT=$(nb utilisateur_active)
api PATCH "/api/admin/utilisateurs/$UID1/actif" "$ADM" '{"actif":"non"}'; code_attendu "actif non booléen -> 400" 400
api PATCH "/api/admin/utilisateurs/$UID1/actif" "$ADM" '{}';              code_attendu "actif absent -> 400" 400
api PATCH "/api/admin/utilisateurs/$ADMID/actif" "$ADM" '{"actif":false}'; code_attendu "se désactiver soi-même -> 409" 409
api PATCH /api/admin/utilisateurs/999999999/actif "$ADM" '{"actif":false}'; code_attendu "utilisateur inexistant -> 404" 404
api PATCH "/api/admin/utilisateurs/$UID1/actif" "$ADM" '{"actif":false}'; code_attendu "désactivation -> 200" 200
egal "compte désactivé" "$(jget "$BODY" actif)" false
delta utilisateur_desactive "$B_DES" 1 "utilisateur_desactive journalisé"
api GET /api/me "$TOK1"; code_attendu "ancien jeton d'un compte désactivé -> 401" 401
login "$EMAIL" >/dev/null; code_attendu "connexion d'un compte désactivé -> 401" 401
api GET "/api/admin/utilisateurs?actif=false&q=$EMAIL" "$ADM"
egal "filtre actif=false retrouve le compte" "$(jget "$BODY" items.0.email)" "$EMAIL"
api PATCH "/api/admin/utilisateurs/$UID1/actif" "$ADM" '{"actif":true}'; code_attendu "réactivation -> 200" 200
delta utilisateur_active "$B_ACT" 1 "utilisateur_active journalisé"
TOK1=$(login "$EMAIL"); [ -n "$TOK1" ] && ok "connexion rétablie après réactivation" || ko "connexion après réactivation" "$BODY"

echo "== 6. Mot de passe oublié =="
B_DEM=$(nb mdp_oublie_demande); B_REI=$(nb mdp_reinitialise)
api POST /api/mot-de-passe/oublie "" '{}';                           code_attendu "sans e-mail -> 400" 400
api POST /api/mot-de-passe/oublie "" '{"email":"pas-un-email"}';     code_attendu "e-mail invalide -> 400" 400
api POST /api/mot-de-passe/oublie "" '{"email":"inconnu-'$RANDOM'@test.com"}'; code_attendu "e-mail inconnu -> 202 (même réponse)" 202
MSG_INCONNU="$BODY"
delta mdp_oublie_demande "$B_DEM" 0 "e-mail inconnu : rien dans le journal"
api POST /api/mot-de-passe/oublie "" "{\"email\":\"$EMAIL\"}";       code_attendu "e-mail connu -> 202" 202
egal "même message pour un compte connu ou inconnu" "$BODY" "$MSG_INCONNU"
delta mdp_oublie_demande "$B_DEM" 1 "mdp_oublie_demande journalisé"

JETON=$(jeton "$EMAIL")
[ -n "$JETON" ] && ok "jeton fabriqué par la console" || ko "jeton" "vide : APP_SECRET renseigné ? (php bin/console app:lien-reinitialisation $EMAIL)"
LIEN=$(php bin/console app:lien-reinitialisation "$EMAIL" 2>/dev/null | tr -d '\r\n')
case "$LIEN" in *reinitialiser-mot-de-passe.html?token=*) ok "le lien contient la page et le jeton";; *) ko "lien" "$LIEN";; esac
api POST /api/mot-de-passe/reinitialiser "" '{"token":"n-importe-quoi","password":"nouveaumdp123"}'; code_attendu "jeton bidon -> 400" 400
api POST /api/mot-de-passe/reinitialiser "" '{"password":"nouveaumdp123"}';                         code_attendu "jeton absent -> 400" 400
FALSIFIE="${JETON%?}$([ "${JETON: -1}" = "a" ] && echo b || echo a)"
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$FALSIFIE\",\"password\":\"nouveaumdp123\"}"; code_attendu "signature modifiée -> 400" 400
JETON_EXPIRE=$(jeton "$EMAIL" --expire)
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON_EXPIRE\",\"password\":\"nouveaumdp123\"}"; code_attendu "jeton expiré -> 400" 400
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON\",\"password\":\"abc\"}";             code_attendu "mot de passe trop court -> 400" 400
delta mdp_reinitialise "$B_REI" 0 "les échecs ne sont pas journalisés"
login "$EMAIL" >/dev/null; code_attendu "l'ancien mot de passe marche encore -> 200" 200
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON\",\"password\":\"nouveaumdp123\"}";   code_attendu "réinitialisation -> 200" 200
delta mdp_reinitialise "$B_REI" 1 "mdp_reinitialise journalisé"
login "$EMAIL" "nouveaumdp123" >/dev/null; code_attendu "connexion avec le nouveau mot de passe -> 200" 200
login "$EMAIL" >/dev/null; code_attendu "l'ancien mot de passe est refusé -> 401" 401
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON\",\"password\":\"autremdp12345\"}";   code_attendu "le même lien ne sert qu'une fois -> 400" 400

api PATCH "/api/admin/utilisateurs/$UID1/actif" "$ADM" '{"actif":false}'
JETON_DES=$(jeton "$EMAIL")
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON_DES\",\"password\":\"autremdp12345\"}"; code_attendu "compte désactivé : lien refusé -> 400" 400
api POST /api/mot-de-passe/oublie "" "{\"email\":\"$EMAIL\"}"; code_attendu "compte désactivé : même réponse 202" 202
delta mdp_oublie_demande "$B_DEM" 1 "compte désactivé : aucun e-mail ni journal"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
