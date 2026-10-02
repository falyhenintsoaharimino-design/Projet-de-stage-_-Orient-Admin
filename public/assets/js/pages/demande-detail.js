/* ==========================================================================
   Détail d'une demande — GET /api/demandes/{id}, avec l'historique des
   statuts réellement enregistré côté serveur.

   Remarque honnête : le backend ne relie pas encore une demande à la liste
   précise des pièces fournies/manquantes (ce lien n'existe pas dans le
   modèle de données actuel) ; cette section n'est donc pas affichée ici
   plutôt que d'inventer une donnée qui n'existe pas côté serveur.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var conteneur = document.getElementById("detail-demande");
  if (!conteneur || !api) { return; }

  var LABELS_STATUT = {
    soumise: "Soumise", orientee: "Orientée", rendez_vous_pris: "RDV pris",
    en_traitement: "En traitement", cloturee: "Clôturée", annulee: "Annulée"
  };

  var id = new URLSearchParams(window.location.search).get("id");
  if (!id) {
    conteneur.innerHTML = '<p class="text-muted">Aucune demande sélectionnée.</p>';
    return;
  }

  function formatDate(iso) {
    if (!iso) { return "-"; }
    var d = new Date(iso);
    return d.toLocaleDateString("fr-FR") + " à " + d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }

  api.getDemande(id).then(function (d) {
    var service = d.serviceFinal || d.serviceRecommande;

    var etapes = (d.historique || []).map(function (h, i, tous) {
      var estDerniere = i === tous.length - 1;
      return '<div class="timeline__item ' + (estDerniere ? "is-current" : "is-done") + '">'
        + '<p class="timeline__title">' + (LABELS_STATUT[h.statut] || h.statut) + "</p>"
        + '<p class="timeline__date">' + formatDate(h.date) + "</p></div>";
    }).join("");

    conteneur.innerHTML =
      '<header class="page-head"><div><h1>Demande #' + d.id + "</h1>"
      + '<p class="text-muted">' + escapeHtml(d.texte) + "</p></div></header>"
      + '<div class="grid grid--main-aside">'
      + '<div class="card"><h3>Suivi</h3><div class="timeline">' + (etapes || '<p class="text-muted">Aucun historique.</p>') + "</div></div>"
      + '<div class="card"><h3 class="card__title">Orientation</h3><dl class="info-list">'
      + "<dt>Service</dt><dd>" + escapeHtml(service ? service.nom : "Non déterminé") + "</dd>"
      + "<dt>Confiance</dt><dd>" + (d.confiance != null ? Math.round(d.confiance * 100) + " %" : "-") + "</dd>"
      + "</dl></div></div>";
  }).catch(function () {
    conteneur.innerHTML = '<p class="text-muted">Cette demande est introuvable ou ne vous appartient pas.</p>';
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
})();
