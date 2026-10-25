#!/usr/bin/env bash
# Recette de l'étape 7 : catalogue et procédures (source et date de mise à jour, modification par l'agent de SON service,
# historique des versions, suppression logique, recherche tolérante aux fautes, rubriques, scénario S6).
# Usage : bash scripts/smoke-catalogue.sh        (à la racine du projet, symfony serve lancé)
# Prérequis : migration Version20261022120000 appliquée, comptes de test créés (agent@test.com rattaché à un service),
# php et curl dans le PATH. Le script crée des services et procédures « Recette ... » : ils restent en base,
# désactivés (suppression logique), et ne gênent pas le catalogue public.

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
ID_UNIQUE="$RANDOM$RANDOM"

ok()  { echo "OK      $1"; NB_OK=$((NB_OK+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; NB_KO=$((NB_KO+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) ${BODY:0:200}"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }
contient() { case "$2" in *"$3"*) ok "$1";; *) ko "$1" "'$3' absent de '$2'";; esac; }

jget() {
  printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (explode(".", $argv[1]) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) { exit(0); } $d = $d[$k]; }
    echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' -- "$2"
}

api() {
  # Le corps JSON passe par un fichier temporaire (--data-binary) : sous Git Bash, un corps passé en argument
  # (crochets, accents) peut être déformé avant d'arriver à curl.exe.
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

echo "== Connexion =="
ADM=$(login admin@test.com);  [ -n "$ADM" ] && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);   [ -n "$CIT" ] && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);  [ -n "$AGT" ] && ok "login agent" || ko "login agent" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }
api GET /api/me "$AGT"; SA=$(jget "$BODY" service)
[ -n "$SA" ] && ok "l'agent est rattaché au service $SA" || { ko "agent sans service" "$BODY"; exit 1; }
B_CREE=$(nb procedure_creee); B_MODIF=$(nb procedure_modifiee); B_DESACT=$(nb procedure_desactivee)
B_REACT=$(nb procedure_reactivee); B_VERIF=$(nb procedure_verifiee); B_SERV=$(nb service_modifie)

RUB="Recette-$ID_UNIQUE"
api POST /api/services "$ADM" "{\"nom\":\"Recette Bureau des passeports $ID_UNIQUE\",\"categorie\":\"$RUB\",\"mission\":\"Délivrer les passeports\"}"
code_attendu "admin crée un autre service (SB)" 201; SB=$(jget "$BODY" id)

echo "== 1. L'agent crée une procédure de SON service (source, étapes, pièces enrichies) =="
CORPS="{\"serviceId\":$SA,\"nom\":\"Recette acte de naissance $ID_UNIQUE\",\"delaiEstime\":3,\"frais\":\"2000 Ar\",\"source\":\"Mairie\",\"etapes\":[\"Retirer le formulaire\",\"Déposer le dossier\"],\"documentsRequis\":[{\"libelle\":\"Livret de famille\",\"exemplaires\":2,\"ancienneteMaxMois\":3,\"ouObtenir\":\"Fokontany\"},{\"libelle\":\"Photo\",\"obligatoire\":false}]}"
api POST /api/procedures "" "$CORPS"; code_attendu "sans jeton -> 401" 401
api POST /api/procedures "$CIT" "$CORPS"; code_attendu "citoyen -> 403" 403
api POST /api/procedures "$AGT" "{\"serviceId\":$SB,\"nom\":\"Recette interdite\"}"; code_attendu "agent dans le service d'un autre -> 403" 403
api POST /api/procedures "$AGT" "{\"serviceId\":$SA}"; code_attendu "nom manquant -> 400" 400
api POST /api/procedures "$AGT" "{\"serviceId\":$SA,\"nom\":\"x\",\"documentsRequis\":[{\"libelle\":\"CIN\",\"exemplaires\":0}]}"; code_attendu "exemplaires à 0 -> 400" 400
api POST /api/procedures "$AGT" "$CORPS"; code_attendu "agent crée dans son service -> 201" 201
P=$(jget "$BODY" id)
[ -n "$P" ] || { echo "Création impossible : inutile de continuer. Réponse : ${BODY:0:300}"; exit 1; }
egal "version 1" "$(jget "$BODY" numeroVersion)" "1"
egal "source enregistrée" "$(jget "$BODY" source)" "Mairie"
[ -n "$(jget "$BODY" misAJourLe)" ] && ok "date de mise à jour présente" || ko "misAJourLe" "$BODY"
contient "mention « information indicative »" "$(jget "$BODY" avertissement)" "indicative"
egal "2e étape" "$(jget "$BODY" etapes.1.description)" "Déposer le dossier"
egal "pièce : exemplaires" "$(jget "$BODY" documentsRequis.0.exemplaires)" "2"
egal "pièce : ancienneté max (mois)" "$(jget "$BODY" documentsRequis.0.ancienneteMaxMois)" "3"
egal "pièce : où l'obtenir" "$(jget "$BODY" documentsRequis.0.ouObtenir)" "Fokontany"
egal "pièce facultative" "$(jget "$BODY" documentsRequis.1.obligatoire)" "false"
DATE1=$(jget "$BODY" misAJourLe)

echo "== 2. Le citoyen voit la fiche (scénario S6) =="
api GET "/api/procedures/$P" ""; code_attendu "visible sans jeton" 200
egal "frais côté citoyen" "$(jget "$BODY" frais)" "2000 Ar"
sleep 1
api PUT "/api/procedures/$P" "$AGT" '{"frais":"Gratuit","etapes":["Retirer le formulaire","Déposer le dossier","Récupérer l'"'"'acte"]}'; code_attendu "l'agent modifie frais et étapes -> 200" 200
egal "version 2" "$(jget "$BODY" numeroVersion)" "2"
DATE2=$(jget "$BODY" misAJourLe)
[ "$DATE2" \> "$DATE1" ] && ok "date de mise à jour renouvelée" || ko "date de mise à jour" "$DATE1 puis $DATE2"
api GET "/api/procedures/$P" ""
egal "nouveau frais visible côté citoyen" "$(jget "$BODY" frais)" "Gratuit"
egal "3 étapes côté citoyen" "$(jget "$BODY" etapes.2.description)" "Récupérer l'acte"
api PUT "/api/procedures/$P" "$AGT" '{"frais":"Gratuit"}'; egal "même contenu : pas de nouvelle version" "$(jget "$BODY" numeroVersion)" "2"
api PUT "/api/procedures/$P" "$CIT" '{"frais":"1"}'; code_attendu "citoyen ne modifie pas -> 403" 403
api PUT "/api/procedures/$P" "$AGT" "{\"serviceId\":$SB}"; code_attendu "agent ne déplace pas la procédure -> 403" 403

echo "== 3. Historique et vérification =="
api GET "/api/procedures/$P/versions" "$CIT"; code_attendu "citoyen : pas d'historique -> 403" 403
api GET "/api/procedures/$P/versions" "$AGT"; code_attendu "agent : historique -> 200" 200
egal "version la plus récente d'abord" "$(jget "$BODY" 0.numero)" "2"
contient "résumé de la modification" "$(jget "$BODY" 0.resume)" "frais"
egal "version 1 = création" "$(jget "$BODY" 1.resume)" "Création"
egal "ancien frais conservé dans la version 1" "$(jget "$BODY" 1.contenu.frais)" "2000 Ar"
api POST "/api/procedures/$P/verification" "$AGT"; code_attendu "information vérifiée -> 200" 200
[ -n "$(jget "$BODY" verifieLe)" ] && ok "date de vérification renseignée" || ko "verifieLe" "$BODY"
egal "pas de nouvelle version" "$(jget "$BODY" numeroVersion)" "2"

echo "== 4. Fiche du service modifiable par son agent =="
api PUT "/api/services/$SA" "$AGT" "{\"horaires\":\"Lun-Ven 8h-15h $ID_UNIQUE\"}"; code_attendu "agent modifie les horaires de son service -> 200" 200
api PUT "/api/services/$SA" "$AGT" '{"nom":"Pirate"}'; code_attendu "agent ne renomme pas -> 403" 403
api PUT "/api/services/$SA" "$AGT" '{"actif":false}'; code_attendu "agent ne désactive pas -> 403" 403
api PUT "/api/services/$SB" "$AGT" '{"horaires":"x"}'; code_attendu "agent : fiche d'un autre service -> 403" 403
api PUT "/api/services/$SA" "$CIT" '{"horaires":"x"}'; code_attendu "citoyen -> 403" 403

echo "== 5. Suppression logique et visibilité =="
api DELETE "/api/procedures/$P" "$AGT"; code_attendu "l'agent désactive -> 204" 204
[ -z "$BODY" ] && ok "204 sans corps" || ko "204 sans corps" "$BODY"
api GET "/api/procedures/$P" ""; code_attendu "public : 404" 404
api GET "/api/procedures?serviceId=$SA&inclureInactifs=true" "$CIT"; case "$BODY" in *"Recette acte de naissance $ID_UNIQUE"*) ko "citoyen ne voit pas l'inactive" "visible";; *) ok "citoyen ne voit pas l'inactive";; esac
api GET "/api/procedures?serviceId=$SA&inclureInactifs=true" "$AGT"; contient "l'agent la voit (inclureInactifs)" "$BODY" "Recette acte de naissance $ID_UNIQUE"
api GET "/api/procedures/$P" "$ADM"; code_attendu "admin la voit -> 200" 200
api GET "/api/procedures?serviceId=$SA" ""; case "$BODY" in *"Recette acte de naissance $ID_UNIQUE"*) ko "absente de la liste publique" "visible";; *) ok "absente de la liste publique";; esac
api PUT "/api/procedures/$P" "$AGT" '{"actif":true}'; code_attendu "réactivation -> 200" 200
api GET "/api/procedures/$P" ""; code_attendu "visible de nouveau" 200
# Procédure d'un service inactif : cachée au public.
api POST /api/procedures "$ADM" "{\"serviceId\":$SB,\"nom\":\"Recette renouvellement de passeport $ID_UNIQUE\",\"description\":\"Renouveler son passeport biométrique\"}"; code_attendu "admin crée dans SB -> 201" 201; PB=$(jget "$BODY" id)

echo "== 6. Recherche tolérante aux fautes, rubriques =="
api GET "/api/services?q=pasports&rubrique=$RUB" ""; egal "service trouvé malgré la faute (« pasports »)" "$(jget "$BODY" 0.id)" "$SB"
api GET "/api/procedures?q=pasport" ""; contient "procédure trouvée malgré la faute (« pasport »)" "$BODY" "Recette renouvellement de passeport $ID_UNIQUE"
api GET "/api/procedures?rubrique=$RUB" ""; egal "filtre par rubrique" "$(jget "$BODY" 0.id)" "$PB"
api GET "/api/procedures?rubrique=$RUB&q=naissance" ""; egal "rubrique + mot absent = liste vide" "$BODY" "[]"
api GET /api/services/rubriques ""; code_attendu "rubriques publiques -> 200" 200
contient "la rubrique de la recette est listée" "$BODY" "$RUB"
api DELETE "/api/services/$SB" "$ADM"; code_attendu "admin désactive SB -> 204" 204
api GET "/api/procedures/$PB" ""; code_attendu "procédure d'un service inactif cachée -> 404" 404
api GET "/api/procedures?rubrique=$RUB" ""; egal "absente de la liste publique" "$BODY" "[]"
api GET "/api/procedures/$PB" "$ADM"; code_attendu "admin la voit toujours -> 200" 200

echo "== 7. Journal =="
delta procedure_creee "$B_CREE" 2 "2 créations journalisées"
delta procedure_modifiee "$B_MODIF" 1 "1 modification journalisée"
delta procedure_desactivee "$B_DESACT" 1 "1 désactivation journalisée"
delta procedure_reactivee "$B_REACT" 1 "1 réactivation journalisée"
delta procedure_verifiee "$B_VERIF" 1 "1 vérification journalisée"
delta service_modifie "$B_SERV" 1 "1 modification de fiche journalisée"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" -eq 0 ]
