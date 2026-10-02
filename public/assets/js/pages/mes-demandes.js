/* ==========================================================================
   Liste des demandes du citoyen connecté — GET /api/demandes (déjà filtré
   côté serveur sur l'utilisateur courant).
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var root = document.body.dataset.root || "./";
  var conteneur = document.getElementById("tableau-demandes");
  if (!conteneur || !api) { return; }

  var LABELS_STATUT = {
    soumise: "Soumise", orientee: "Orientée", rendez_vous_pris: "RDV pris",
    en_traitement: "En traitement", cloturee: "Clôturée", annulee: "Annulée"
  };
  var BADGES_STATUT = {
    soumise: "neutral", orientee: "info", rendez_vous_pris: "success",
    en_traitement: "warning", cloturee: "neutral", annulee: "danger"
  };

  function formatDate(iso) {
    if (!iso) { return "-"; }
    var d = new Date(iso);
    return d.toLocaleDateString("fr-FR") + " " + d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }

  api.listDemandes().then(function (demandes) {
    if (demandes.length === 0) {
      conteneur.innerHTML = '<p class="text-muted">Vous n\'avez pas encore de demande. '
        + '<a href="' + root + 'pages/citoyen/assistant.html">Ouvrir l\'assistant</a> pour en faire une.</p>';
      return;
    }

    var lignes = demandes.map(function (d) {
      var service = d.serviceFinal || d.serviceRecommande;
      return "<tr>"
        + '<td><a href="' + root + 'pages/citoyen/demande-detail.html?id=' + d.id + '">#' + d.id + "</a></td>"
        + "<td>" + escapeHtml(d.texte) + "</td>"
        + "<td>" + escapeHtml(service ? service.nom : "Non déterminé") + "</td>"
        + "<td>" + formatDate(d.dateCreation) + "</td>"
        + '<td><span class="badge badge--' + (BADGES_STATUT[d.statut] || "neutral") + '">'
        + (LABELS_STATUT[d.statut] || d.statut) + "</span></td>"
        + "</tr>";
    }).join("");

    conteneur.innerHTML =
      '<div class="table-wrap"><table class="table"><thead><tr>'
      + "<th>Référence</th><th>Objet</th><th>Service</th><th>Date</th><th>Statut</th>"
      + "</tr></thead><tbody>" + lignes + "</tbody></table></div>";
  }).catch(function () {
    conteneur.innerHTML = '<p class="text-muted">Impossible de charger vos demandes pour le moment.</p>';
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
})();
