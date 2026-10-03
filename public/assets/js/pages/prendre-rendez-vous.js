/* ==========================================================================
   Prise de rendez-vous (citoyen).
   - GET /api/demandes     : demandes orientées vers un service, sans rendez-vous
   - GET /api/creneaux     : créneaux disponibles du service de la demande
   - POST /api/rendez-vous : réservation
   Une demande peut être préchoisie avec ?demandeId=12 dans l'adresse.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var racine = document.getElementById("rdv-app");
  if (!racine || !api) { return; }

  var root = document.body.dataset.root || "./";
  var MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août",
    "septembre", "octobre", "novembre", "décembre"];
  var JOURS = ["dimanche", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi"];

  var etat = { demandes: [], demande: null, creneaux: [], mois: null, jour: null, creneau: null, message: null };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function pad(n) { return n < 10 ? "0" + n : String(n); }
  // "2026-10-12" -> Date locale (évite le décalage de fuseau de new Date("2026-10-12"))
  function parseDate(s) { var p = s.split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function cle(annee, mois, jour) { return annee + "-" + pad(mois + 1) + "-" + pad(jour); }
  function libelleJour(s) {
    var d = parseDate(s);
    var j = JOURS[d.getDay()];
    return j.charAt(0).toUpperCase() + j.slice(1) + " " + d.getDate() + " " + MOIS[d.getMonth()];
  }
  function serviceDe(d) { return d.serviceFinal || d.serviceRecommande; }

  /* ----- Données ----- */
  function demandeEligible(d) {
    return d.statut === "orientee" && !!serviceDe(d);
  }

  function chargerCreneaux() {
    var service = serviceDe(etat.demande);
    etat.jour = null;
    etat.creneau = null;
    return api.listCreneaux(service.id).then(function (liste) {
      etat.creneaux = liste;
      var premier = liste.length ? parseDate(liste[0].date) : new Date();
      etat.mois = new Date(premier.getFullYear(), premier.getMonth(), 1);
    });
  }

  function creneauxDuJour(jour) {
    return etat.creneaux.filter(function (c) { return c.date === jour; });
  }
  function joursDisponibles() {
    var jours = {};
    etat.creneaux.forEach(function (c) { jours[c.date] = true; });
    return jours;
  }

  /* ----- Affichage ----- */
  function htmlSelectDemande() {
    if (etat.demandes.length < 2) { return ""; }
    var options = etat.demandes.map(function (d) {
      var texte = "#" + d.id + " — " + d.texte;
      if (texte.length > 70) { texte = texte.slice(0, 67) + "..."; }
      return '<option value="' + d.id + '"' + (d.id === etat.demande.id ? " selected" : "") + ">"
        + escapeHtml(texte) + "</option>";
    }).join("");
    return '<div class="card"><div class="field"><label for="rdv-demande">Pour quelle demande ?</label>'
      + '<select id="rdv-demande">' + options + "</select></div></div>";
  }

  function htmlCalendrier() {
    var annee = etat.mois.getFullYear();
    var mois = etat.mois.getMonth();
    var premierJourSemaine = (new Date(annee, mois, 1).getDay() + 6) % 7; // lundi = 0
    var nbJours = new Date(annee, mois + 1, 0).getDate();
    var dispo = joursDisponibles();

    var html = '<div class="calendar">';
    ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"].forEach(function (j) {
      html += '<div class="calendar__weekday">' + j + "</div>";
    });
    for (var i = 0; i < premierJourSemaine; i++) { html += '<span class="cal-empty"></span>'; }
    for (var d = 1; d <= nbJours; d++) {
      var k = cle(annee, mois, d);
      if (dispo[k]) {
        html += '<button class="cal-day' + (k === etat.jour ? " is-selected" : "")
          + '" type="button" data-jour="' + k + '">' + d + "</button>";
      } else {
        html += '<button class="cal-day is-disabled" type="button" disabled>' + d + "</button>";
      }
    }
    return html + "</div>";
  }

  function htmlCreneaux() {
    if (!etat.jour) {
      return '<p class="text-muted">Choisissez une date dans le calendrier.</p>';
    }
    var boutons = creneauxDuJour(etat.jour).map(function (c) {
      return '<button class="slot' + (c.id === etat.creneau ? " is-selected" : "")
        + '" type="button" data-creneau="' + c.id + '">' + escapeHtml(c.heure) + "</button>";
    }).join("");
    return '<h3>' + libelleJour(etat.jour) + '</h3><p class="text-muted">Créneaux disponibles</p>'
      + '<div class="slots">' + boutons + "</div>";
  }

  function htmlRecap() {
    var c = etat.creneau && etat.creneaux.filter(function (x) { return x.id === etat.creneau; })[0];
    var service = serviceDe(etat.demande);
    var ligne = c
      ? escapeHtml(service.nom) + " - " + libelleJour(c.date) + " à " + escapeHtml(c.heure)
      : "Aucun créneau choisi pour le moment.";
    return '<h3 class="mt-5">Récapitulatif</h3><p class="text-muted">' + ligne + "</p>"
      + '<div class="card__footer"><button class="btn btn--success" type="button" id="rdv-confirmer"'
      + (c ? "" : " disabled") + ">Confirmer le rendez-vous</button></div>";
  }

  function render() {
    var service = serviceDe(etat.demande);
    document.getElementById("rdv-sous-titre").textContent = service.nom;

    var alerte = etat.message
      ? '<div class="alert alert--' + etat.message.type + '">' + escapeHtml(etat.message.texte) + "</div>"
      : "";

    var aucunCreneau = etat.creneaux.length === 0;
    var calendrier = aucunCreneau
      ? '<p class="text-muted">Aucun créneau n\'est ouvert pour ce service pour le moment. Revenez plus tard.</p>'
      : '<div class="page-head__actions" style="margin-bottom:var(--space-3)">'
        + '<button class="btn btn--ghost" type="button" id="mois-prec">&laquo;</button> '
        + "<strong>" + MOIS[etat.mois.getMonth()] + " " + etat.mois.getFullYear() + "</strong> "
        + '<button class="btn btn--ghost" type="button" id="mois-suiv">&raquo;</button></div>'
        + htmlCalendrier();

    racine.innerHTML = alerte + htmlSelectDemande()
      + '<div class="grid grid--main-aside">'
      + '<div class="card"><h3>Choisissez une date</h3>' + calendrier + "</div>"
      + '<div class="card">' + htmlCreneaux() + htmlRecap() + "</div></div>";
  }

  /* ----- Actions (délégation d'événements : le HTML est reconstruit à chaque rendu) ----- */
  racine.addEventListener("change", function (event) {
    if (event.target.id !== "rdv-demande") { return; }
    var id = +event.target.value;
    etat.demande = etat.demandes.filter(function (d) { return d.id === id; })[0];
    etat.message = null;
    chargerCreneaux().then(render).catch(erreurChargement);
  });

  racine.addEventListener("click", function (event) {
    var cible = event.target.closest("button");
    if (!cible || cible.disabled) { return; }

    if (cible.dataset.jour) {
      etat.jour = cible.dataset.jour;
      etat.creneau = null;
      etat.message = null;
      render();
    } else if (cible.dataset.creneau) {
      etat.creneau = +cible.dataset.creneau;
      etat.message = null;
      render();
    } else if (cible.id === "mois-prec" || cible.id === "mois-suiv") {
      var pas = cible.id === "mois-prec" ? -1 : 1;
      etat.mois = new Date(etat.mois.getFullYear(), etat.mois.getMonth() + pas, 1);
      render();
    } else if (cible.id === "rdv-confirmer") {
      confirmer(cible);
    }
  });

  function confirmer(bouton) {
    bouton.disabled = true;
    api.creerRendezVous(etat.demande.id, etat.creneau).then(function () {
      window.location.href = root + "pages/citoyen/mes-rendez-vous.html";
    }).catch(function (err) {
      // 409 : quelqu'un a pris le créneau entre-temps -> on recharge la liste
      etat.message = { type: "danger", texte: err.message };
      chargerCreneaux().then(render).catch(render);
    });
  }

  function erreurChargement() {
    racine.innerHTML = '<p class="text-muted">Impossible de charger les créneaux pour le moment.</p>';
  }

  /* ----- Démarrage ----- */
  api.listDemandes().then(function (demandes) {
    etat.demandes = demandes.filter(demandeEligible);
    if (etat.demandes.length === 0) {
      racine.innerHTML = '<div class="card"><p>Vous n\'avez aucune demande en attente de rendez-vous.</p>'
        + '<p class="text-muted">Faites d\'abord une demande avec <a href="' + root
        + 'pages/citoyen/assistant.html">l\'assistant</a> ; elle sera orientée vers le bon service.</p></div>';
      return;
    }
    var voulu = +new URLSearchParams(window.location.search).get("demandeId");
    etat.demande = etat.demandes.filter(function (d) { return d.id === voulu; })[0] || etat.demandes[0];
    return chargerCreneaux().then(render);
  }).catch(erreurChargement);
})();
