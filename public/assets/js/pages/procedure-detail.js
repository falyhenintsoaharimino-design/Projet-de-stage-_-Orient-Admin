/* ==========================================================================
   Fiche procédure — lit l'identifiant dans l'URL (procedure.html?id=5),
   charge la procédure (étapes + pièces requises) depuis l'API.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var root = document.body.dataset.root || "./";
  var role = document.body.dataset.role || "visiteur";
  var conteneur = document.getElementById("fiche-procedure");
  if (!conteneur || !api) { return; }

  var id = new URLSearchParams(window.location.search).get("id");
  if (!id) {
    conteneur.innerHTML = "<p class=\"text-muted\">Aucune procédure sélectionnée. Retournez au <a href=\"" + root + "pages/public/services.html\">catalogue</a>.</p>";
    return;
  }

  api.getProcedure(id).then(function (procedure) {
    var etapesHtml = procedure.etapes.length
      ? "<ol class=\"check-list\">" + procedure.etapes
          .slice().sort(function (a, b) { return a.ordre - b.ordre; })
          .map(function (e) { return "<li>" + escapeHtml(e.description) + "</li>"; }).join("") + "</ol>"
      : "<p class=\"text-muted\">Étapes à préciser par le service concerné.</p>";

    var piecesHtml = procedure.documentsRequis.length
      ? "<ul class=\"check-list\">" + procedure.documentsRequis.map(function (d) {
          return "<li><label class=\"checkbox\"><input type=\"checkbox\">" + escapeHtml(d.libelle)
            + (d.obligatoire ? "" : " <span class=\"text-small text-muted\">(facultatif)</span>") + "</label></li>";
        }).join("") + "</ul>"
      : "<p class=\"text-muted\">Pièces à préciser par le service concerné.</p>";

    var lienRdv = role === "citoyen"
      ? root + "pages/citoyen/prendre-rendez-vous.html"
      : root + "pages/auth/connexion.html";

    conteneur.innerHTML =
      '<header class="page-head"><div><h1>' + escapeHtml(procedure.nom) + '</h1>' +
      '<p class="text-muted">' + escapeHtml(procedure.service.nom) + '</p></div></header>' +
      '<div class="grid grid--main-aside">' +
      '<div class="card"><h3>Étapes</h3>' + etapesHtml +
      '<h3 class="mt-5">Pièces à fournir</h3>' + piecesHtml + '</div>' +
      '<div class="card"><h3 class="card__title">Informations pratiques</h3><dl class="info-list">' +
      '<dt>Délai indicatif</dt><dd>' + escapeHtml(procedure.delaiEstime || 'Non renseigné') + '</dd>' +
      '<dt>Frais indicatifs</dt><dd>' + escapeHtml(procedure.frais || 'Non renseignés') + '</dd>' +
      '</dl><div class="card__footer"><a class="btn btn--primary btn--block" href="' + lienRdv + '">Prendre rendez-vous</a></div></div>' +
      '</div><p class="text-small text-muted mt-5">Information indicative : le service compétent reste seul habilité à confirmer les modalités exactes.</p>';
  }).catch(function () {
    conteneur.innerHTML = "<p class=\"text-muted\">Cette procédure est introuvable ou n'est plus disponible.</p>";
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
})();
