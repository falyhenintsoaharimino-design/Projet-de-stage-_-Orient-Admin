/* ==========================================================================
   Rendez-vous du service de l'agent — GET /api/rendez-vous (déjà filtré sur
   son service), PATCH .../decision (confirmer / refuser),
   PATCH .../statut (termine / absent), PATCH .../annuler.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var conteneur = document.getElementById("liste-rdv-agent");
  var sousTitre = document.getElementById("rdv-agent-soustitre");
  if (!conteneur || !api) { return; }

  var STATUTS = {
    en_attente: ["En attente", "warning"],
    confirme: ["Confirmé", "success"],
    refuse: ["Refusé", "danger"],
    annule: ["Annulé", "neutral"],
    termine: ["Terminé", "neutral"],
    absent: ["Absent", "danger"]
  };

  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function formatDate(iso) { var p = iso.split("-"); return p[2] + "/" + p[1] + "/" + p[0]; }

  function boutons(r) {
    function b(action, id, texte, classe) {
      return '<button class="btn ' + classe + ' btn--sm" type="button" data-action="' + action + '" data-id="' + id + '">' + texte + "</button> ";
    }
    if (r.statut === "en_attente") {
      return b("confirmer", r.id, "Confirmer", "btn--success") + b("refuser", r.id, "Refuser", "btn--outline");
    }
    if (r.statut === "confirme") {
      return b("termine", r.id, "Terminé", "btn--primary") + b("absent", r.id, "Absent", "btn--outline")
        + b("annuler", r.id, "Annuler", "btn--outline");
    }
    return "";
  }

  function charger() {
    api.listRendezVous().then(function (liste) {
      var enAttente = liste.filter(function (r) { return r.statut === "en_attente"; }).length;
      if (sousTitre) {
        sousTitre.textContent = enAttente
          ? enAttente + " rendez-vous en attente de votre validation"
          : "Aucun rendez-vous en attente de validation";
      }
      if (!liste.length) {
        conteneur.innerHTML = '<div class="card"><p>Aucun rendez-vous pour votre service pour le moment.</p></div>';
        return;
      }
      // Les rendez-vous à valider passent en premier, puis par date.
      liste.sort(function (a, b) {
        var pa = a.statut === "en_attente" ? 0 : 1, pb = b.statut === "en_attente" ? 0 : 1;
        return pa - pb || (a.date + a.heure).localeCompare(b.date + b.heure);
      });
      var lignes = liste.map(function (r) {
        var st = STATUTS[r.statut] || [r.statut, "neutral"];
        return "<tr><td>" + formatDate(r.date) + " " + escapeHtml(r.heure) + "</td>"
          + "<td>" + escapeHtml((r.citoyenPrenom || "") + " " + (r.citoyenNom || "")) + "</td>"
          + "<td>" + escapeHtml(r.demande.texte) + "</td>"
          + '<td><span class="badge badge--' + st[1] + '">' + st[0] + "</span></td>"
          + "<td>" + boutons(r) + "</td></tr>";
      }).join("");
      conteneur.innerHTML = '<div class="table-wrap"><table class="table"><thead><tr>'
        + "<th>Date</th><th>Citoyen</th><th>Objet</th><th>Statut</th><th>Actions</th>"
        + "</tr></thead><tbody>" + lignes + "</tbody></table></div>";
    }).catch(function () {
      conteneur.innerHTML = '<p class="text-muted">Impossible de charger les rendez-vous pour le moment.</p>';
    });
  }

  conteneur.addEventListener("click", function (event) {
    var bouton = event.target.closest("[data-action]");
    if (!bouton) { return; }
    var action = bouton.dataset.action, id = bouton.dataset.id, appel;

    if (action === "confirmer" || action === "refuser") {
      if (action === "refuser" && !window.confirm("Refuser ce rendez-vous ? Le créneau sera libéré.")) { return; }
      appel = api.decisionRendezVous(id, action);
    } else if (action === "annuler") {
      if (!window.confirm("Annuler ce rendez-vous ? Le créneau sera libéré.")) { return; }
      appel = api.annulerRendezVous(id);
    } else {
      appel = api.statutRendezVous(id, action);
    }
    bouton.disabled = true;
    appel.then(charger).catch(function (err) {
      window.alert(err.message || "Action impossible.");
      charger();
    });
  });

  charger();
})();
