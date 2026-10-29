#!/usr/bin/env bash
# Recette finale du backend (étape 10b) : les scénarios S1 à S9 du cahier des charges, de bout en bout, avec un verdict par scénario.
#   S1 le visiteur trouve son service et sa procédure     S6 l'agent met à jour une procédure (versions)
#   S2 demande ambiguë ou hors sujet                      S7 le responsable consulte statistiques et exports
#   S3 compte citoyen et mot de passe oublié              S8 qualité du moteur d'orientation (cibles 80 % / 95 %)
#   S4 rendez-vous : réservation, modification, rappel    S9 sécurité (accès, entrées invalides, mots de passe, essais répétés)
#   S5 l'agent réoriente une demande
# Usage : bash scripts/recette-finale.sh [URL_API]      (à la racine du projet, API et moteur Python lancés)
# Prérequis : comptes de test (test@test.com citoyen, agent@test.com rattaché à un service, responsable@test.com, admin@test.com ; mot de passe
# motdepasse123), services de démonstration (app:seed-demo), php et curl dans le PATH. Reste en base après le test : quelques comptes
# « recette-finale-… », des demandes, des créneaux et des procédures de test (rendez-vous de test annulés). Le test des essais de connexion
# répétés (S9) n'utilise qu'une adresse e-mail inventée : il ne bloque aucun vrai compte.

BASE="${1:-http://127.0.0.1:8000}"
PASS="motdepasse123"
PASS_NEUF="Recette-finale-2026!"
ID_UNIQUE="$RANDOM$RANDOM"
SC=0; declare -A SC_OK SC_KO SC_TITRE; SC_OK[0]=0; SC_KO[0]=0

scenario() { SC="$1"; SC_TITRE[$1]="$2"; SC_OK[$1]=0; SC_KO[$1]=0; echo; echo "== S$1 : $2 =="; }
ok()  { echo "OK      $1"; SC_OK[$SC]=$((SC_OK[$SC]+1)); }
ko()  { echo "ÉCHEC   $1  ->  $2"; SC_KO[$SC]=$((SC_KO[$SC]+1)); }
code_attendu() { [ "$CODE" = "$2" ] && ok "$1" || ko "$1" "code $CODE (attendu $2) ${BODY:0:200}"; }
egal() { [ "$2" = "$3" ] && ok "$1" || ko "$1" "obtenu '$2', attendu '$3'"; }
contient() { case "$2" in *"$3"*) ok "$1";; *) ko "$1" "'$3' absent de '${2:0:240}'";; esac; }
vide() { { [ -z "$2" ] || [ "$2" = "null" ]; } && ok "$1" || ko "$1" "obtenu '$2'"; }
vrai() { [ "$2" = "1" ] && ok "$1" || ko "$1" "$3"; }

jget() {
  printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true);
    foreach (explode(".", $argv[1]) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) { exit(0); } $d = $d[$k]; }
    echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' -- "$2"
}
compter() { printf '%s' "$1" | php -r 'echo count((array) json_decode(stream_get_contents(STDIN), true));'; }

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
# fuseau de l'application (APP_TIMEZONE), pas celui du shell : « demain » et les dates des créneaux en dépendent
jour() { php -r '$tz = new DateTimeZone(getenv("APP_TIMEZONE") ?: "Indian/Antananarivo"); echo (new DateTime("today +" . $argv[1] . " days", $tz))->format("Y-m-d");' -- "$1"; }
types_notifs() { api GET /api/notifications "$1"; printf '%s' "$BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $l=$d["items"] ?? $d; $t=[]; foreach ((array)$l as $n) { $t[]=$n["type"] ?? ""; } echo implode(",", $t);'; }
heure() { printf '%02d:%02d' "$1" "$((RANDOM % 60))"; }

echo "== Connexion =="
ADM=$(login admin@test.com);        [ -n "$ADM" ]  && ok "login admin" || ko "login admin" "$BODY"
CIT=$(login test@test.com);         [ -n "$CIT" ]  && ok "login citoyen" || ko "login citoyen" "$BODY"
AGT=$(login agent@test.com);        [ -n "$AGT" ]  && ok "login agent" || ko "login agent" "$BODY"
RESP=$(login responsable@test.com); [ -n "$RESP" ] && ok "login responsable" || ko "login responsable" "$BODY"
{ [ -z "$ADM" ] || [ -z "$CIT" ] || [ -z "$AGT" ] || [ -z "$RESP" ]; } && { echo "Impossible de continuer sans les 4 jetons."; exit 1; }
api GET /api/me "$AGT"; SA=$(jget "$BODY" service.id); [ -z "$SA" ] && SA=$(jget "$BODY" service)
[ -n "$SA" ] && ok "l'agent est rattaché au service $SA" || { ko "agent sans service" "$BODY"; exit 1; }

# ---------------------------------------------------------------------------------------------------------------------
scenario 1 "le visiteur (sans compte) trouve son service et sa procédure"
api POST /api/orientation/tester "" '{"texte":"Je veux refaire ma carte d identité"}'; code_attendu "l'assistant répond sans compte" 200
SI=$(jget "$BODY" service.id); CONF=$(jget "$BODY" confiance)
[ -n "$SI" ] && ok "service proposé : $(jget "$BODY" service.nom) (confiance $CONF)" || ko "aucun service proposé" "$BODY"
vrai "confiance au moins égale au seuil (0,30)" "$(php -r 'echo ((float)$argv[1] >= 0.30) ? 1 : 0;' -- "${CONF:-0}")" "confiance $CONF"
api POST /api/procedures "$ADM" "{\"serviceId\":${SI:-0},\"nom\":\"Recette finale carte d'identité $ID_UNIQUE\",\"delaiEstime\":7,\"frais\":\"10 000 Ar\",\"source\":\"Commissariat\",\"etapes\":[\"Retirer le formulaire\",\"Déposer le dossier\"],\"documentsRequis\":[{\"libelle\":\"Acte de naissance\",\"exemplaires\":1,\"ancienneteMaxMois\":3,\"ouObtenir\":\"Mairie\"},{\"libelle\":\"Photo d'identité\",\"obligatoire\":false}]}"
code_attendu "(préparation) l'admin décrit la procédure du service proposé" 201; PI=$(jget "$BODY" id)
api GET "/api/procedures?serviceId=$SI" ""; code_attendu "le visiteur lit les procédures du service, sans compte" 200
FICHE=$(printf '%s' "$BODY" | php -r '$id=(int)$argv[1]; foreach ((array)json_decode(stream_get_contents(STDIN),true) as $p) { if (($p["id"] ?? 0) === $id) { echo json_encode($p); } }' -- "${PI:-0}")
[ -n "$FICHE" ] && ok "la procédure du service apparaît" || ko "procédure absente de la liste" "${BODY:0:200}"
egal "délai estimé" "$(jget "$FICHE" delaiEstime)" "7"
egal "frais" "$(jget "$FICHE" frais)" "10 000 Ar"
egal "2 étapes" "$(compter "$(jget "$FICHE" etapes)")" "2"
egal "2 pièces à fournir" "$(compter "$(jget "$FICHE" documentsRequis)")" "2"
contient "pièce obligatoire et où l'obtenir" "$(jget "$FICHE" documentsRequis.0.ouObtenir)" "Mairie"
contient "avertissement « information indicative »" "$(jget "$FICHE" avertissement)" "indicative"
[ -n "$(jget "$FICHE" misAJourLe)" ] && ok "date de mise à jour affichée" || ko "date de mise à jour" "$FICHE"

# ---------------------------------------------------------------------------------------------------------------------
scenario 2 "demande ambiguë ou hors sujet : pas de réponse inventée"
api POST /api/orientation/tester "" '{"texte":"zzqx wvut"}'; code_attendu "texte incompréhensible : l'assistant répond" 200
vide "aucun service imposé" "$(jget "$BODY" service)"
api POST /api/orientation/tester "" '{"texte":"quel temps fera-t-il demain"}'; code_attendu "demande hors sujet : l'assistant répond" 200
vide "aucun service imposé (hors sujet)" "$(jget "$BODY" service)"
api POST /api/orientation/tester "" '{"texte":""}'; vrai "texte vide refusé (4xx)" "$([ "$CODE" -ge 400 ] && [ "$CODE" -lt 500 ] && echo 1 || echo 0)" "code $CODE"
api POST /api/demandes "$CIT" '{"texte":"zzqx wvut"}'; code_attendu "le citoyen dépose quand même sa demande" 201
egal "elle reste « soumise » (traitée à la main par un agent)" "$(jget "$BODY" statut)" "soumise"
vide "aucun service recommandé" "$(jget "$BODY" serviceRecommande)"

# ---------------------------------------------------------------------------------------------------------------------
scenario 3 "compte citoyen et mot de passe oublié"
EMAIL="recette-finale-$ID_UNIQUE@test.com"
api POST /api/register "" "{\"nom\":\"Recette\",\"prenom\":\"Finale\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"consentement\":true}"; code_attendu "inscription avec consentement -> 201" 201
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"consentement\":true}"; code_attendu "même e-mail refusé -> 409" 409
api POST /api/register "" "{\"nom\":\"Recette\",\"email\":\"sans-consentement-$ID_UNIQUE@test.com\",\"password\":\"$PASS\"}"; code_attendu "sans consentement -> 400" 400
C1=$(login "$EMAIL"); [ -n "$C1" ] && ok "connexion du nouveau compte" || ko "connexion" "$BODY"
api GET /api/me "$C1"; contient "rôle citoyen" "$(jget "$BODY" roles)" "ROLE_CITOYEN"
for chemin in /api/admin/parametres /api/admin/moteur /api/journal /api/statistiques/tableau-de-bord /api/admin/sauvegardes; do
  api GET "$chemin" "$C1"; code_attendu "citoyen refusé sur $chemin -> 403" 403
done
api POST /api/mot-de-passe/oublie "" "{\"email\":\"$EMAIL\"}"; code_attendu "demande de réinitialisation -> 202" 202
JETON=$(php bin/console app:lien-reinitialisation "$EMAIL" --jeton 2>/dev/null | tr -d '\r\n')
[ -n "$JETON" ] && ok "jeton de réinitialisation (fabriqué par la console, aucun e-mail réel en développement)" || ko "jeton" "vide : APP_SECRET renseigné ?"
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON\",\"password\":\"$PASS_NEUF\"}"; code_attendu "nouveau mot de passe enregistré" 200
api POST /api/mot-de-passe/reinitialiser "" "{\"token\":\"$JETON\",\"password\":\"Autre-motdepasse-2026!\"}"; vrai "le même jeton ne sert qu'une fois (4xx)" "$([ "$CODE" -ge 400 ] && [ "$CODE" -lt 500 ] && echo 1 || echo 0)" "code $CODE"
api POST /api/login_check "" "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"; code_attendu "l'ancien mot de passe ne marche plus" 401
C1=$(login "$EMAIL" "$PASS_NEUF"); [ -n "$C1" ] && ok "connexion avec le nouveau mot de passe" || ko "connexion nouveau mot de passe" "$BODY"

# ---------------------------------------------------------------------------------------------------------------------
scenario 4 "rendez-vous : réservation, place unique, modification, confirmation, rappel, annulation"
EMAIL2="recette-finale-bis-$ID_UNIQUE@test.com"
api POST /api/register "" "{\"nom\":\"Recette\",\"prenom\":\"Bis\",\"email\":\"$EMAIL2\",\"password\":\"$PASS\",\"consentement\":true}"; code_attendu "2e citoyen inscrit" 201
C2=$(login "$EMAIL2")
LOIN=$(jour 4); DEMAIN=$(jour 1)
api POST /api/creneaux "$AGT" "{\"date\":\"$LOIN\",\"heures\":[\"$(heure 14)\",\"$(heure 15)\"],\"capacite\":1}"; X=$(jget "$BODY" crees.0.id); Y=$(jget "$BODY" crees.1.id)
api POST /api/creneaux "$AGT" "{\"date\":\"$DEMAIN\",\"heures\":[\"$(heure 16)\"],\"capacite\":1}"; T=$(jget "$BODY" crees.0.id)
{ [ -n "$X" ] && [ -n "$Y" ] && [ -n "$T" ]; } && ok "3 créneaux d'une place créés (le $LOIN et le $DEMAIN)" || ko "créneaux" "relancer le script (heure déjà prise ?) $BODY"
api POST /api/demandes "$C1" "{\"texte\":\"Recette finale rendez-vous\",\"serviceId\":$SA}"; D1=$(jget "$BODY" id)
api POST /api/demandes "$C2" "{\"texte\":\"Recette finale rendez-vous bis\",\"serviceId\":$SA}"; D2=$(jget "$BODY" id)
api POST /api/rendez-vous "$C1" "{\"demandeId\":$D1,\"creneauId\":$X}"; code_attendu "citoyen 1 réserve le créneau X" 201; R1=$(jget "$BODY" id)
api POST /api/rendez-vous "$C2" "{\"demandeId\":$D2,\"creneauId\":$X}"; code_attendu "citoyen 2 sur le même créneau : complet -> 409" 409
api PATCH "/api/rendez-vous/$R1" "$C1" "{\"creneauId\":$Y}"; code_attendu "citoyen 1 change pour le créneau Y" 200
api POST /api/rendez-vous "$C2" "{\"demandeId\":$D2,\"creneauId\":$X}"; code_attendu "la place libérée de X est réservable par le citoyen 2" 201; R2=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R1/annuler" "$C1"; code_attendu "citoyen 1 annule (hors délai de 24 h)" 200
api POST /api/rendez-vous "$C1" "{\"demandeId\":$D1,\"creneauId\":$T}"; code_attendu "citoyen 1 réserve le créneau de demain" 201; R3=$(jget "$BODY" id)
api PATCH "/api/rendez-vous/$R3/decision" "$AGT" '{"decision":"confirmer"}'; code_attendu "l'agent confirme" 200
php bin/console app:rappels-rdv >/dev/null 2>&1; [ "$?" = "0" ] && ok "commande des rappels exécutée" || ko "app:rappels-rdv" "code de sortie non nul"
NOTIFS=$(types_notifs "$C1")
contient "notification : demande enregistrée" "$NOTIFS" "rdv_en_attente"
contient "notification : rendez-vous confirmé" "$NOTIFS" "rdv_confirme"
contient "notification : rappel de la veille" "$NOTIFS" "rdv_rappel"
for r in "$R2" "$R3"; do api PATCH "/api/rendez-vous/$r/annuler" "$AGT"; code_attendu "nettoyage : l'agent annule le rendez-vous $r" 200; done

# ---------------------------------------------------------------------------------------------------------------------
scenario 5 "l'agent réoriente une demande mal orientée"
api GET /api/statistiques/orientation "$RESP"; REO0=$(jget "$BODY" reorientees)
api POST /api/demandes "$CIT" '{"texte":"Je veux un passeport"}'; code_attendu "demande orientée par le moteur" 201; DP=$(jget "$BODY" id); SP=$(jget "$BODY" serviceRecommande.id)
[ -n "$SP" ] && [ "$SP" != "$SA" ] && ok "le moteur propose le service $SP, pas celui de l'agent ($SA)" || ko "service recommandé" "SP='$SP' SA='$SA'"
api PATCH "/api/demandes/$DP/reorienter" "$AGT" "{\"serviceId\":$SA}"; code_attendu "l'agent réoriente vers son service" 200
egal "service final = $SA" "$(jget "$BODY" serviceFinal.id)" "$SA"
api GET /api/statistiques/orientation "$RESP"; egal "statistique « réorientées » +1" "$(jget "$BODY" reorientees)" "$((REO0 + 1))"
api PATCH "/api/demandes/$DP/reorienter" "$CIT" "{\"serviceId\":$SA}"; code_attendu "un citoyen ne peut pas réorienter -> 403" 403
# La réorientation crée un exemple d'apprentissage ; celui-ci est faux (ce n'est qu'un test) : on le supprime pour ne pas fausser le moteur au prochain réentraînement.
api GET /api/admin/moteur/exemples "$ADM"
EX=$(printf '%s' "$BODY" | php -r '$id=(int)$argv[1]; foreach ((array)(json_decode(stream_get_contents(STDIN),true)["exemples"] ?? []) as $e) { if (($e["demandeId"] ?? 0) === $id) { echo $e["id"]; break; } }' -- "$DP")
[ -n "$EX" ] && ok "la réorientation a créé l'exemple d'apprentissage $EX" || ko "exemple d'apprentissage" "introuvable pour la demande $DP"
[ -n "$EX" ] && { api DELETE "/api/admin/moteur/exemples/$EX" "$ADM"; code_attendu "nettoyage : l'exemple de test est supprimé (le moteur n'apprend pas de faux exemples)" 200; }

# ---------------------------------------------------------------------------------------------------------------------
scenario 6 "l'agent met à jour une procédure : nouvelle version visible du citoyen"
api POST /api/procedures "$AGT" "{\"serviceId\":$SA,\"nom\":\"Recette finale acte $ID_UNIQUE\",\"delaiEstime\":3,\"frais\":\"2 000 Ar\",\"etapes\":[\"Déposer le dossier\"]}"; code_attendu "l'agent crée la procédure de son service" 201; P6=$(jget "$BODY" id)
egal "version 1" "$(jget "$BODY" numeroVersion)" "1"
api PUT "/api/procedures/$P6" "$AGT" '{"frais":"5 000 Ar","delaiEstime":5}'; code_attendu "l'agent change les frais et le délai" 200
egal "version 2" "$(jget "$BODY" numeroVersion)" "2"
api GET "/api/procedures/$P6" "$C1"; code_attendu "le citoyen lit la fiche à jour" 200
egal "frais à jour" "$(jget "$BODY" frais)" "5 000 Ar"
egal "délai à jour" "$(jget "$BODY" delaiEstime)" "5"
[ -n "$(jget "$BODY" misAJourLe)" ] && ok "date de mise à jour renseignée" || ko "misAJourLe" "$BODY"
api GET "/api/procedures/$P6/versions" "$AGT"; code_attendu "l'agent consulte l'historique" 200
egal "2 versions conservées" "$(compter "$BODY")" "2"
api GET "/api/procedures/$P6/versions" "$C1"; code_attendu "le citoyen n'a pas accès à l'historique -> 403" 403

# ---------------------------------------------------------------------------------------------------------------------
scenario 7 "le responsable consulte les statistiques et exporte"
DU=$(jour -1); AU=$(jour 1)
api GET "/api/statistiques/tableau-de-bord?du=$DU&au=$AU" "$RESP"; code_attendu "tableau de bord" 200
TOTAL_TB=$(jget "$BODY" indicateurs.totalDemandes)
api GET "/api/statistiques/demandes?du=$DU&au=$AU" "$RESP"; code_attendu "statistiques des demandes" 200
egal "tableau de bord et statistiques donnent le même total ($TOTAL_TB)" "$(jget "$BODY" total)" "$TOTAL_TB"
TMPD=$(mktemp -d)
CODE=$(curl -s -o "$TMPD/d.csv" -D "$TMPD/h.txt" -w '%{http_code}' -H "Authorization: Bearer $RESP" "$BASE/api/statistiques/export?type=demandes&du=$DU&au=$AU"); BODY=""
code_attendu "export CSV des demandes" 200
grep -qi '^content-type: text/csv' "$TMPD/h.txt" && ok "type text/csv" || ko "Content-Type" "$(grep -i '^content-type' "$TMPD/h.txt")"
egal "BOM UTF-8 (accents corrects dans Excel)" "$(head -c 3 "$TMPD/d.csv" | od -An -tx1 | tr -d ' \n')" "efbbbf"
LIGNES=$(( $(tr -d '\r' < "$TMPD/d.csv" | grep -c .) - 1 ))
egal "autant de lignes exportées que de demandes comptées" "$LIGNES" "$TOTAL_TB"
grep -q 'zzqx' "$TMPD/d.csv" && ko "le texte des demandes ne doit pas être exporté" "présent" || ok "aucun texte de demande dans l'export"
rm -rf "$TMPD"
api GET "/api/statistiques/tableau-de-bord" "$AGT"; code_attendu "l'agent n'a pas accès aux statistiques -> 403" 403
api GET "/api/statistiques/tableau-de-bord" "$CIT"; code_attendu "le citoyen n'a pas accès aux statistiques -> 403" 403

# ---------------------------------------------------------------------------------------------------------------------
scenario 8 "qualité du moteur d'orientation : cibles 80 % (1re proposition) et 95 % (3 premières)"
api GET /api/admin/moteur "$ADM"; code_attendu "l'admin lit l'état et les métriques du moteur" 200
egal "moteur actif et disponible" "$(jget "$BODY" moteur.actif)/$(jget "$BODY" moteur.disponible)" "true/true"
P1=$(jget "$BODY" moteur.metriques.precisionPremiere); P3=$(jget "$BODY" moteur.metriques.precisionTop3)
echo "        mesuré sur $(jget "$BODY" moteur.metriques.nbDemandesTest) demandes de test : 1re proposition $P1, 3 premières $P3, hors sujet rejetés $(jget "$BODY" moteur.metriques.horsSujet.tauxRejet)"
vrai "1re proposition ≥ 80 % ($P1)" "$(php -r 'echo ((float)$argv[1] >= 0.80) ? 1 : 0;' -- "${P1:-0}")" "obtenu $P1"
vrai "3 premières ≥ 95 % ($P3)" "$(php -r 'echo ((float)$argv[1] >= 0.95) ? 1 : 0;' -- "${P3:-0}")" "obtenu $P3"

# ---------------------------------------------------------------------------------------------------------------------
scenario 9 "sécurité : accès, entrées invalides, mots de passe, essais répétés"
for chemin in /api/me /api/demandes /api/notifications /api/rendez-vous /api/admin/parametres /api/journal /api/statistiques/tableau-de-bord /api/admin/moteur; do
  api GET "$chemin" ""; code_attendu "sans jeton : $chemin -> 401" 401
done
api POST /api/procedures "$CIT" "{\"serviceId\":$SA,\"nom\":\"Interdit\"}"; code_attendu "citoyen : créer une procédure -> 403" 403
api POST /api/creneaux/generer "$CIT" '{}'; code_attendu "citoyen : générer des créneaux -> 403" 403
api PATCH "/api/demandes/$DP/reorienter" "$RESP" "{\"serviceId\":$SA}"; code_attendu "responsable : réorienter -> 403" 403
api POST /api/demandes "$CIT" '{pas du json'; vrai "JSON invalide refusé (4xx, pas de 500)" "$([ "$CODE" -ge 400 ] && [ "$CODE" -lt 500 ] && echo 1 || echo 0)" "code $CODE"
api POST /api/demandes "$CIT" "{\"texte\":\"$(head -c 6000 /dev/zero | tr '\0' 'a')\"}"; code_attendu "texte démesuré (6000 caractères) refusé -> 400" 400
api POST /api/orientation/tester "" '{"texte":[1,2]}'; code_attendu "assistant public : texte qui n'est pas une chaîne -> 400 (pas de 500)" 400
api POST /api/register "" '{"nom":"x","email":"pas-un-email","password":"motdepasse123","consentement":true}'; code_attendu "e-mail invalide -> 400" 400
api POST /api/demandes "$CIT" "{\"texte\":\"'; DROP TABLE utilisateur; -- <script>alert(1)</script>\"}"; vrai "texte piégé traité comme du simple texte (pas de 500)" "$([ "$CODE" -lt 500 ] && echo 1 || echo 0)" "code $CODE"
api GET /api/me "$CIT"; code_attendu "la base est intacte après le texte piégé" 200
SORTIE=$(php bin/console dbal:run-sql "SELECT COUNT(*) AS clairs FROM utilisateur WHERE password NOT LIKE '\$%'" 2>/dev/null | tr -d '\r')
NB_CLAIRS=$(printf '%s\n' "$SORTIE" | grep -E '^[[:space:]|]*[0-9]+[[:space:]|]*$' | grep -o '[0-9]\+' | head -1)
egal "aucun mot de passe en clair en base (tous hachés)" "${NB_CLAIRS:-?}" "0"
VUS429=0; FAUX="inconnu-$ID_UNIQUE@test.com"
for i in 1 2 3 4 5 6 7; do api POST /api/login_check "" "{\"email\":\"$FAUX\",\"password\":\"mauvais\"}"; [ "$CODE" = "429" ] && VUS429=1; done
vrai "essais de connexion répétés bloqués (429)" "$VUS429" "jamais de 429 après 7 échecs"

# ---------------------------------------------------------------------------------------------------------------------
echo; echo "================ Résultat par scénario ================"
TOT_KO=${SC_KO[0]}
for n in 1 2 3 4 5 6 7 8 9; do
  [ "${SC_KO[$n]}" = "0" ] && V="OK   " || V="ÉCHEC"
  printf '%s  S%s  %-62s %3s OK, %s échec(s)\n' "$V" "$n" "${SC_TITRE[$n]:0:62}" "${SC_OK[$n]}" "${SC_KO[$n]}"
  TOT_KO=$((TOT_KO + SC_KO[$n]))
done
[ "${SC_KO[0]}" = "0" ] || echo "ÉCHEC  connexion : ${SC_KO[0]} échec(s)"
echo "========================================================"
[ "$TOT_KO" = "0" ] && echo "RECETTE FINALE : TOUS LES SCÉNARIOS SONT RÉUSSIS" || echo "RECETTE FINALE : $TOT_KO ÉCHEC(S)"
[ "$TOT_KO" = "0" ]
