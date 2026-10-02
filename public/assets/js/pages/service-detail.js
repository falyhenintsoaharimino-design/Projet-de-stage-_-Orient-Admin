/* ==========================================================================
   Fiche service — lit l'identifiant dans l'URL (service-detail.html?id=3),
   charge le service et ses procédures liées depuis l'API.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var root = document.body.dataset.root || "./";
  var conteneur = document.getElementById("fiche-service");
  if (!conteneur || !api) { return; }

  var id = new URLSearchParams(window.location.search).get("id");
  if (!id) {
    conteneur.innerHTML = "<p class=\"text-muted\">Aucun service sélectionné. Retournez au <a href=\"" + root + "pages/public/services.html\">catalogue</a>.</p>";
    return;
  }

  Promise.all([api.getService(id), api.listProcedures(id)]).then(function (resultats) {
    var service = resultats[0];
    var procedures = resultats[1];

    var procHtml = procedures.length
      ? "<ul class=\"check-list\">" + procedures.map(function (p) {
          return "<li><a href=\"" + root + "pages/public/procedure.html?id=" + p.id + "\">"
            + escapeHtml(p.nom) + "</a></li>";
        }).join("") + "</ul>"
      : "<p class=\"text-muted\">Aucune procédure enregistrée pour ce service pour le moment.</p>";

    conteneur.innerHTML =
      '<header class="page-head"><div><h1>' + escapeHtml(service.nom) + '</h1>' +
      (service.categorie ? '<p class="text-muted">' + escapeHtml(service.categorie) + '</p>' : '') +
      '</div></header>' +
      '<div class="grid grid--main-aside">' +
      '<div class="card"><h3>Missions</h3><p class="text-muted">' + escapeHtml(service.mission || 'Non renseigné.') + '</p>' +
      '<h3 class="mt-5">Procédures liées</h3>' + procHtml + '</div>' +
      '<div class="card"><h3 class="card__title">Coordonnées</h3><dl class="info-list">' +
      '<dt>Adresse</dt><dd>' + escapeHtml(service.adresse || 'Non renseignée') + '</dd>' +
      '<dt>Horaires</dt><dd>' + escapeHtml(service.horaires || 'Non renseignés') + '</dd>' +
      '<dt>Contact</dt><dd>' + escapeHtml(service.contact || 'Non renseigné') + '</dd>' +
      '</dl></div></div>';
  }).catch(function () {
    conteneur.innerHTML = "<p class=\"text-muted\">Ce service est introuvable ou n'est plus disponible.</p>";
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
})();
