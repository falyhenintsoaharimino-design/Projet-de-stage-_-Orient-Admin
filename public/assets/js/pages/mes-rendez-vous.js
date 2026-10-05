/* ==========================================================================
   Mes rendez-vous (citoyen) — GET /api/rendez-vous (filtré côté serveur sur
   le citoyen connecté), PATCH /api/rendez-vous/{id}/annuler.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var conteneur = document.getElementById("liste-rdv");
  if (!conteneur || !api) { return; }

  var STATUTS = {
    en_attente: ["En attente de validation", "warning"],
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

  function charger() {
    api.listRendezVous().then(function (liste) {
      if (!liste.length) {
        conteneur.innerHTML = '<div class="card"><p>Vous n\'avez aucun rendez-vous pour le moment.</p></div>';
        return;
      }
      var lignes = liste.map(function (r) {
        var st = STATUTS[r.statut] || [r.statut, "neutral"];
        var lieu = escapeHtml(r.service.nom) + (r.service.adresse ? " — " + escapeHtml(r.service.adresse) : "");
        var action = (r.statut === "en_attente" || r.statut === "confirme")
          ? '<button class="btn btn--outline btn--sm" type="button" data-annuler="' + r.id + '">Annuler</button>'
          : "";
        return "<tr><td>" + escapeHtml(r.demande.texte) + "</td>"
          + "<td>" + formatDate(r.date) + " " + escapeHtml(r.heure) + "</td>"
          + "<td>" + lieu + "</td>"
          + '<td><span class="badge badge--' + st[1] + '">' + st[0] + "</span></td>"
          + "<td>" + action + "</td></tr>";
      }).join("");
      conteneur.innerHTML = '<div class="table-wrap"><table class="table"><thead><tr>'
        + "<th>Objet</th><th>Date</th><th>Lieu</th><th>Statut</th><th></th>"
        + "</tr></thead><tbody>" + lignes + "</tbody></table></div>";
    }).catch(function () {
      conteneur.innerHTML = '<p class="text-muted">Impossible de charger vos rendez-vous pour le moment.</p>';
    });
  }

  conteneur.addEventListener("click", function (event) {
    var bouton = event.target.closest("[data-annuler]");
    if (!bouton) { return; }
    if (!window.confirm("Annuler ce rendez-vous ?")) { return; }
    bouton.disabled = true;
    api.annulerRendezVous(bouton.dataset.annuler).then(charger).catch(function (err) {
      window.alert(err.message || "Annulation impossible.");
      bouton.disabled = false;
    });
  });

  charger();
})();
