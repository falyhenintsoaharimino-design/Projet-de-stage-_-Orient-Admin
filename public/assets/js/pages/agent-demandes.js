/* ==========================================================================
   Demandes orientées vers le service de l'agent connecté.
   GET /api/demandes (déjà filtré côté serveur sur le service de l'agent),
   PATCH .../reorienter et PATCH .../statut pour agir dessus.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var conteneur = document.getElementById("tableau-demandes-agent");
  if (!conteneur || !api) { return; }

  var STATUTS = [
    ["soumise", "Soumise"], ["orientee", "Orientée"], ["rendez_vous_pris", "RDV pris"],
    ["en_traitement", "En traitement"], ["cloturee", "Clôturée"], ["annulee", "Annulée"]
  ];

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function optionsStatut(actuel) {
    return STATUTS.map(function (s) {
      return '<option value="' + s[0] + '"' + (s[0] === actuel ? " selected" : "") + ">" + s[1] + "</option>";
    }).join("");
  }

  function optionsServices(services) {
    return services.map(function (s) {
      return '<option value="' + s.id + '">' + escapeHtml(s.nom) + "</option>";
    }).join("");
  }

  function ligne(d, services) {
    var service = d.serviceFinal || d.serviceRecommande;
    var citoyen = (d.citoyenPrenom || "") + " " + (d.citoyenNom || "");
    var confiance = d.confiance != null ? Math.round(d.confiance * 100) + " %" : "-";

    var tr = document.createElement("tr");
    tr.innerHTML =
      "<td>#" + d.id + "</td>"
      + "<td>" + escapeHtml(citoyen.trim() || "Citoyen #" + d.citoyenId) + "</td>"
      + "<td>" + escapeHtml(d.texte) + "</td>"
      + "<td>" + confiance + "</td>"
      + '<td><select class="select-statut">' + optionsStatut(d.statut) + "</select></td>"
      + '<td><select class="select-service">' + optionsServices(services) + "</select></td>"
      + '<td class="row"><button class="btn btn--outline btn--sm" data-action="statut">Mettre à jour</button>'
      + '<button class="btn btn--primary btn--sm" data-action="reorienter">Réorienter</button></td>';

    tr.querySelector('[data-action="statut"]').addEventListener("click", function (event) {
      var bouton = event.currentTarget;
      var statut = tr.querySelector(".select-statut").value;
      bouton.disabled = true;
      api.changerStatutDemande(d.id, statut).catch(function (e) { alert(e.message); }).finally(function () { bouton.disabled = false; });
    });
    tr.querySelector('[data-action="reorienter"]').addEventListener("click", function (event) {
      var bouton = event.currentTarget;
      var serviceId = tr.querySelector(".select-service").value;
      bouton.disabled = true;
      api.reorienterDemande(d.id, serviceId)
        .then(function () { window.location.reload(); })
        .catch(function (e) { alert(e.message); bouton.disabled = false; });
    });

    return tr;
  }

  Promise.all([api.listDemandes(), api.listServices()]).then(function (resultats) {
    var demandes = resultats[0];
    var services = resultats[1].filter(function (s) { return s.actif; });

    if (demandes.length === 0) {
      conteneur.innerHTML = '<p class="text-muted">Aucune demande orientée vers votre service pour le moment.</p>';
      return;
    }

    var wrap = document.createElement("div");
    wrap.className = "table-wrap";
    var table = document.createElement("table");
    table.className = "table";
    table.innerHTML = "<thead><tr><th>Réf.</th><th>Citoyen</th><th>Objet</th><th>Confiance</th>"
      + "<th>Statut</th><th>Réorienter vers</th><th>Actions</th></tr></thead>";
    var tbody = document.createElement("tbody");
    demandes.forEach(function (d) { tbody.appendChild(ligne(d, services)); });
    table.appendChild(tbody);
    wrap.appendChild(table);

    conteneur.innerHTML = "";
    conteneur.appendChild(wrap);
  }).catch(function () {
    conteneur.innerHTML = '<p class="text-muted">Impossible de charger les demandes pour le moment.</p>';
  });
})();
