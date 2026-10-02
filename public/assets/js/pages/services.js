/* ==========================================================================
   Catalogue des services — charge la liste réelle depuis GET /api/services,
   construit les filtres par catégorie à partir des données reçues (aucune
   catégorie n'est codée en dur ici), et filtre aussi par mot-clé recherché.
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var root = document.body.dataset.root || "./";
  var liste = document.getElementById("liste-services");
  var chips = document.getElementById("chips-categories");
  var recherche = document.getElementById("recherche-services");
  if (!liste || !api) { return; }

  var services = [];
  var categorieActive = "tous";

  function carte(service) {
    var div = document.createElement("div");
    div.className = "card";
    div.dataset.category = (service.categorie || "autre").toLowerCase();

    var tag = document.createElement("div");
    tag.className = "service-card__tag";
    tag.textContent = service.categorie || "Autre";

    var titre = document.createElement("h3");
    titre.className = "service-card__title";
    titre.textContent = service.nom;

    var texte = document.createElement("p");
    texte.className = "service-card__text";
    texte.textContent = service.mission || "Détails à venir.";

    var lien = document.createElement("a");
    lien.href = root + "pages/public/service-detail.html?id=" + service.id;
    lien.textContent = "Voir la fiche →";

    div.appendChild(tag);
    div.appendChild(titre);
    div.appendChild(texte);
    div.appendChild(lien);
    return div;
  }

  function appliquerFiltre() {
    var texte = recherche ? recherche.value.trim().toLowerCase() : "";
    var visibles = services.filter(function (s) {
      var categorieOk = categorieActive === "tous" || (s.categorie || "").toLowerCase() === categorieActive;
      var texteOk = texte === "" || s.nom.toLowerCase().indexOf(texte) !== -1
        || (s.mission || "").toLowerCase().indexOf(texte) !== -1;
      return categorieOk && texteOk;
    });

    liste.innerHTML = "";
    if (visibles.length === 0) {
      liste.appendChild(Object.assign(document.createElement("p"), {
        className: "text-muted", textContent: "Aucun service ne correspond à votre recherche."
      }));
      return;
    }
    visibles.forEach(function (s) { liste.appendChild(carte(s)); });
  }

  function construireChips() {
    if (!chips) { return; }
    var categories = [];
    services.forEach(function (s) {
      var c = s.categorie || "Autre";
      if (categories.indexOf(c) === -1) { categories.push(c); }
    });

    categories.forEach(function (c) {
      var chip = document.createElement("button");
      chip.type = "button";
      chip.className = "chip";
      chip.dataset.filter = c.toLowerCase();
      chip.textContent = c;
      chips.appendChild(chip);
    });

    chips.addEventListener("click", function (event) {
      var chip = event.target.closest("[data-filter]");
      if (!chip) { return; }
      chips.querySelectorAll(".chip").forEach(function (c) { c.classList.remove("is-active"); });
      chip.classList.add("is-active");
      categorieActive = chip.dataset.filter;
      appliquerFiltre();
    });
  }

  if (recherche) { recherche.addEventListener("input", appliquerFiltre); }

  api.listServices().then(function (data) {
    services = data.filter(function (s) { return s.actif; });
    construireChips();
    appliquerFiltre();
  }).catch(function () {
    liste.innerHTML = "";
    liste.appendChild(Object.assign(document.createElement("p"), {
      className: "text-muted", textContent: "Impossible de charger les services pour le moment."
    }));
  });
})();
