#!/usr/bin/env bash
# Recette de l'étape 5 : statistiques, tableau de bord responsable, export CSV.
# Usage : bash scripts/smoke-stats.sh            (à lancer à la racine du projet)
#         SERVICE_ID=2 bash scripts/smoke-stats.sh   (si l'agent n'est pas sur le service 1)
# Prérequis : symfony serve lancé, comptes de test créés, php et curl dans le PATH,
#             catalogue de démonstration chargé (php bin/console app:seed-demo : le test
#             d'orientation utilise « acte de naissance » et « passeport »).
# Aucune migration pour cette étape. Le script compare les chiffres AVANT / APRÈS ses
# propres actions (la base peut déjà contenir des données). Il laisse quelques demandes,
# créneaux, rendez-vous et un avis de test dans la base (sans danger).

BASE="${1:-http://127.0.0.1:8000}"
SERVICE_ID="${SERVICE_ID:-1}"
PASS="motdepasse123"
NB_OK=0; NB_KO=0
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

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
# val CHEMIN CLE : valeur de CLE dans la réponse de GET CHEMIN (jeton responsable)
val() { api GET "$1" "$RESP"; jget "$BODY" "$2"; }
# trouve LISTE CHAMP VALEUR RESULTAT : dans BODY, élément de LISTE dont CHAMP vaut VALEUR -> son RESULTAT (0 sinon)
trouve() { printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (($d[$argv[1]] ?? []) as $x) { if ((string)($x[$argv[2]] ?? "") === $argv[3]) { echo $x[$argv[4]]; exit; } } echo 0;' -- "$1" "$2" "$3" "$4"; }
# delta NOM AVANT APRES ATTENDU
delta() { egal "$1" "$(( $3 - $2 ))" "$4"; }
nb_journal() { api GET "/api/journal?action=$1&limite=1" "$ADM"; jget "$BODY" total; }

echo "== Connexion =="
CIT=$(login test@test.com);         [ -n "$CIT" ] && ok "login citoyen"      || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);        [ -n "$AGT" ] && ok "login agent"        || ko "login agent" "$BODY"
ADM=$(login admin@test.com);        [ -n "$ADM" ] && ok "login admin"        || ko "login admin" "$BODY"
RESP=$(login responsable@test.com); [ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
{ [ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$ADM" ] || [ -z "$RESP" ]; } && { echo "Impossible de continuer sans jetons."; exit 1; }

echo "== 1. Accès réservé au responsable (et à l'admin) =="
for chemin in tableau-de-bord demandes rendez-vous affluence satisfaction orientation "export?type=synthese"; do
  api GET "/api/statistiques/$chemin" "";      code_attendu "$chemin sans jeton -> 401" 401
  api GET "/api/statistiques/$chemin" "$CIT";  code_attendu "$chemin citoyen -> 403" 403
  api GET "/api/statistiques/$chemin" "$AGT";  code_attendu "$chemin agent -> 403" 403
  api GET "/api/statistiques/$chemin" "$RESP"; code_attendu "$chemin responsable -> 200" 200
  api GET "/api/statistiques/$chemin" "$ADM";  code_attendu "$chemin admin -> 200" 200
done

echo "== 2. Validation des paramètres =="
api GET "/api/statistiques/demandes?du=2026-13-45" "$RESP";            code_attendu "du invalide -> 400" 400
api GET "/api/statistiques/demandes?au=hier" "$RESP";                  code_attendu "au invalide -> 400" 400
api GET "/api/statistiques/demandes?du=2026-10-10&au=2026-10-01" "$RESP"; code_attendu "du > au -> 400" 400
api GET "/api/statistiques/demandes?serviceId=abc" "$RESP";            code_attendu "serviceId texte -> 400" 400
api GET "/api/statistiques/demandes?serviceId=99999999" "$RESP";       code_attendu "serviceId inconnu -> 404" 404
api GET "/api/statistiques/demandes?pas=semaine" "$RESP";              code_attendu "pas invalide -> 400" 400
api GET "/api/statistiques/export" "$RESP";                            code_attendu "export sans type -> 400" 400
api GET "/api/statistiques/export?type=xyz" "$RESP";                   code_attendu "export type inconnu -> 400" 400
api GET "/api/statistiques/export?type=avis&format=pdf" "$RESP";       code_attendu "export format pdf -> 400" 400
api GET "/api/statistiques/export?type=avis&du=pasunedate" "$RESP";    code_attendu "export du invalide -> 400" 400

echo "== 3. Forme du tableau de bord =="
api GET /api/statistiques/tableau-de-bord "$RESP"
for cle in indicateurs.totalDemandes indicateurs.totalRendezVous indicateurs.nombreAvis demandes.parService demandes.parType affluence.parJour satisfaction.repartition orientation.orienteesParMoteur; do
  [ -n "$(jget "$BODY" "$cle")" ] && ok "tableau de bord : $cle présent" || ko "tableau de bord : $cle" "absent"
done
egal "indicateurs.totalDemandes = demandes.total" "$(jget "$BODY" indicateurs.totalDemandes)" "$(jget "$BODY" demandes.total)"
egal "7 jours dans affluence.parJour" "$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["affluence"]["parJour"]);')" 7
egal "5 notes dans satisfaction.repartition" "$(printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo count($d["satisfaction"]["repartition"]);')" 5

echo "== 4. Chiffres avant les actions de test =="
O="/api/statistiques/orientation"
DEM0=$(val /api/statistiques/demandes total)
ORI0=$(val $O orienteesParMoteur); REO0=$(val $O reorientees); BON0=$(val $O bonnesOrientations)
NC0=$(val $O nonComprises);        CH0=$(val $O choixDuCitoyen)
api GET /api/statistiques/rendez-vous "$RESP"; RDV0=$(jget "$BODY" total); TERM0=$(trouve parStatut statut termine total); ABS0=$(trouve parStatut statut absent total)
AVIS0=$(val /api/statistiques/satisfaction nombreAvis)
api GET /api/statistiques/satisfaction "$RESP"; N5_0=$(trouve repartition note 5 total)
JOUR=$(date -d "+$((RANDOM % 300 + 5)) days" +%F); JNUM=$(date -d "$JOUR" +%u); HEURE=$((8 + RANDOM % 10))
api GET "/api/statistiques/affluence?du=$JOUR&au=$JOUR" "$RESP"; AFF0=$(jget "$BODY" total); AFFJ0=$(trouve parJour jour "$JNUM" total); AFFH0=$(trouve parHeure heure "$HEURE" total)
JRNL0=$(nb_journal export_rapport)

echo "== 5. Demandes de test (orientation) =="
api POST /api/demandes "$CIT" '{"texte":"Je veux un acte de naissance"}'; code_attendu "demande A (moteur)" 201
DA=$(jget "$BODY" id); SA=$(jget "$BODY" serviceRecommande.id)
[ -n "$SA" ] && ok "A : le moteur recommande le service $SA" || ko "A : pas de service recommandé" "lance php bin/console app:seed-demo ; $BODY"
api POST /api/demandes "$CIT" '{"texte":"zzqx wvut"}'; code_attendu "demande B (non comprise)" 201
egal "B : statut soumise" "$(jget "$BODY" statut)" soumise
api POST /api/demandes "$CIT" "{\"texte\":\"Recette stats\",\"serviceId\":$SERVICE_ID}"; code_attendu "demande C (choix du citoyen)" 201
DC=$(jget "$BODY" id)
api POST /api/demandes "$CIT" '{"texte":"Je veux un passeport"}'; code_attendu "demande D (moteur, puis réorientée)" 201
DD=$(jget "$BODY" id); SD=$(jget "$BODY" serviceRecommande.id)
[ -n "$SD" ] && [ -n "$SA" ] && [ "$SD" != "$SA" ] && ok "D : service recommandé ($SD) différent de celui de A ($SA)" || ko "D : service recommandé" "SD='$SD' SA='$SA'"
api PATCH "/api/demandes/$DD/reorienter" "$AGT" "{\"serviceId\":$SA}"; code_attendu "agent réoriente D vers $SA" 200

echo "== 6. Chiffres après les demandes =="
delta "total des demandes +4" "$DEM0" "$(val /api/statistiques/demandes total)" 4
delta "orientées par le moteur +2 (A et D)" "$ORI0" "$(val $O orienteesParMoteur)" 2
delta "réorientées +1 (D)" "$REO0" "$(val $O reorientees)" 1
delta "bonnes orientations +1 (A)" "$BON0" "$(val $O bonnesOrientations)" 1
delta "non comprises +1 (B)" "$NC0" "$(val $O nonComprises)" 1
delta "choix du citoyen +1 (C)" "$CH0" "$(val $O choixDuCitoyen)" 1
api GET $O "$RESP"
egal "bonnes = orientées - réorientées" "$(jget "$BODY" bonnesOrientations)" "$(( $(jget "$BODY" orienteesParMoteur) - $(jget "$BODY" reorientees) ))"
api GET "/api/statistiques/demandes?du=$(date +%F)&au=$(date +%F)" "$RESP"
[ "$(jget "$BODY" total)" -ge 4 ] 2>/dev/null && ok "filtre du/au = aujourd'hui : au moins 4 demandes" || ko "filtre du/au" "$BODY"
egal "filtre pas=mois : une courbe" "$([ -n "$(jget "$BODY" evolution.0.periode)" ] && echo oui || echo non)" oui
api GET "/api/statistiques/demandes?pas=jour&du=$(date +%F)&au=$(date +%F)" "$RESP"
egal "pas=jour : la période est un jour" "$(jget "$BODY" evolution.0.periode)" "$(date +%F)"
api GET "/api/statistiques/demandes?serviceId=$SA&du=$(date +%F)&au=$(date +%F)" "$RESP"
[ "$(jget "$BODY" total)" -ge 2 ] 2>/dev/null && ok "filtre serviceId=$SA : A et D comptées" || ko "filtre serviceId" "$BODY"

echo "== 7. Rendez-vous, affluence, satisfaction =="
m() { printf '%02d:%02d' "$HEURE" "$1"; }
M1=$((RANDOM % 30)); M2=$((30 + RANDOM % 30))
api POST /api/creneaux "$AGT" "{\"date\":\"$JOUR\",\"heures\":[\"$(m $M1)\",\"$(m $M2)\"]}"
C1=$(jget "$BODY" crees.0.id); C2=$(jget "$BODY" crees.1.id)
[ -n "$C1" ] && [ -n "$C2" ] && ok "2 créneaux créés ($JOUR à ${HEURE}h)" || ko "créneaux" "relance le script (heure déjà prise ?) $BODY"
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DC,\"creneauId\":$C1}"; code_attendu "réservation rdv 1" 201; R1=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R1/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme rdv 1" 200
api PATCH "/api/rendez-vous/$R1/statut" "$AGT" '{"statut":"termine"}'; code_attendu "agent termine rdv 1" 200
api POST /api/rendez-vous "$CIT" "{\"demandeId\":$DC,\"creneauId\":$C2}"; code_attendu "réservation rdv 2" 201; R2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R2/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "agent confirme rdv 2" 200
api PATCH "/api/rendez-vous/$R2/statut" "$AGT" '{"statut":"absent"}'; code_attendu "agent note rdv 2 absent" 200
api POST /api/avis "$CIT" "{\"rendezVousId\":$R1,\"note\":5,\"commentaire\":\"=1+1\"}"; code_attendu "citoyen note le rdv 1 (5/5)" 201

api GET /api/statistiques/rendez-vous "$RESP"
delta "rendez-vous +2" "$RDV0" "$(jget "$BODY" total)" 2
delta "terminés +1" "$TERM0" "$(trouve parStatut statut termine total)" 1
delta "absents +1" "$ABS0" "$(trouve parStatut statut absent total)" 1
api GET "/api/statistiques/affluence?du=$JOUR&au=$JOUR" "$RESP"
delta "affluence du $JOUR : +2 rendez-vous" "$AFF0" "$(jget "$BODY" total)" 2
delta "affluence : +2 sur le jour $JNUM de la semaine" "$AFFJ0" "$(trouve parJour jour "$JNUM" total)" 2
delta "affluence : +2 à ${HEURE}h" "$AFFH0" "$(trouve parHeure heure "$HEURE" total)" 2
api GET /api/statistiques/satisfaction "$RESP"
delta "avis +1" "$AVIS0" "$(jget "$BODY" nombreAvis)" 1
delta "note 5 +1 dans la répartition" "$N5_0" "$(trouve repartition note 5 total)" 1
[ -n "$(jget "$BODY" noteMoyenne)" ] && ok "note moyenne calculée" || ko "note moyenne" "$BODY"
api GET "/api/statistiques/satisfaction?serviceId=$SERVICE_ID" "$RESP"
[ "$(trouve parService serviceId "$SERVICE_ID" nombreAvis)" -ge 1 ] 2>/dev/null && ok "satisfaction par service : le service $SERVICE_ID a des avis" || ko "satisfaction par service" "$BODY"

echo "== 8. Export CSV =="
export_csv() { # TYPE [QUERY] -> $CODE, $TMP/h.txt, $TMP/out.csv
  CODE=$(curl -s -o "$TMP/out.csv" -D "$TMP/h.txt" -w '%{http_code}' -H "Authorization: Bearer $RESP" "$BASE/api/statistiques/export?type=$1$2")
}
AUJ=$(date +%F)
for t in synthese demandes rendez-vous avis; do
  export_csv "$t" "&du=$AUJ&au=$AUJ"; code_attendu "export $t -> 200" 200
  grep -qi '^content-type: text/csv' "$TMP/h.txt" && ok "export $t : Content-Type text/csv" || ko "export $t : Content-Type" "$(grep -i '^content-type' "$TMP/h.txt")"
  grep -qi '^content-disposition: attachment' "$TMP/h.txt" && ok "export $t : téléchargement (attachment)" || ko "export $t : Content-Disposition" "absent"
  egal "export $t : BOM UTF-8" "$(head -c 3 "$TMP/out.csv" | od -An -tx1 | tr -d ' \n')" efbbbf
done
export_csv synthese ""
grep -q 'Service;Catégorie;Demandes;Rendez-vous' "$TMP/out.csv" && ok "synthèse : en-têtes attendus (séparateur « ; »)" || ko "synthèse : en-têtes" "$(head -2 "$TMP/out.csv")"
export_csv demandes "&du=$AUJ&au=$AUJ"
[ "$(grep -c 'choix_citoyen' "$TMP/out.csv")" -ge 1 ] && ok "demandes : ligne choix_citoyen (C)" || ko "demandes : choix_citoyen" "absent"
[ "$(grep -c 'reorientee' "$TMP/out.csv")" -ge 1 ] && ok "demandes : ligne reorientee (D)" || ko "demandes : reorientee" "absent"
[ "$(grep -c 'non_comprise' "$TMP/out.csv")" -ge 1 ] && ok "demandes : ligne non_comprise (B)" || ko "demandes : non_comprise" "absent"
grep -q 'zzqx' "$TMP/out.csv" && ko "demandes : le texte des demandes ne doit pas être exporté" "présent" || ok "demandes : aucun texte de demande dans l'export"
grep -q 'test@test.com' "$TMP/out.csv" && ko "demandes : l'identité du citoyen ne doit pas être exportée" "présent" || ok "demandes : aucune identité de citoyen"
export_csv avis "&du=$AUJ&au=$AUJ"
grep -q "'=1+1" "$TMP/out.csv" && ok "avis : commentaire « =1+1 » neutralisé (pas de formule Excel)" || ko "avis : protection contre l'injection de formule" "$(cat "$TMP/out.csv")"
export_csv rendez-vous "&du=$JOUR&au=$JOUR&serviceId=$SERVICE_ID"
egal "rendez-vous du $JOUR (service $SERVICE_ID) : 2 lignes + en-tête" "$(grep -c '' "$TMP/out.csv")" 3
api GET "/api/statistiques/export?type=avis" "$ADM"; code_attendu "admin peut exporter" 200
api GET "/api/statistiques/export?type=avis" "$CIT"; code_attendu "citoyen ne peut pas exporter" 403
JRNL1=$(nb_journal export_rapport)
[ "$JRNL1" -gt "$JRNL0" ] 2>/dev/null && ok "journal : actions export_rapport enregistrées ($JRNL0 -> $JRNL1)" || ko "journal export_rapport" "$JRNL0 -> $JRNL1"

echo
echo "Résultat : $NB_OK OK, $NB_KO ÉCHEC"
[ "$NB_KO" = "0" ]
