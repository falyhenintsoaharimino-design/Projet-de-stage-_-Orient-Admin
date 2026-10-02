/* ==========================================================================
   Comportements généraux, activés par des attributs data-* dans le HTML.
   Rien ici n'est propre à une page particulière.
   ========================================================================== */
(function () {
  "use strict";

  /* Filtres par pastilles :
     <div class="chips" data-filter-group="#liste">
       <button class="chip" data-filter="etat-civil">...</button>
     </div>
     <div id="liste"> <article data-category="etat-civil">...</article> </div> */
  document.querySelectorAll("[data-filter-group]").forEach(function (group) {
    var target = document.querySelector(group.dataset.filterGroup);
    if (!target) { return; }
    group.addEventListener("click", function (event) {
      var chip = event.target.closest("[data-filter]");
      if (!chip) { return; }
      group.querySelectorAll("[data-filter]").forEach(function (c) { c.classList.remove("is-active"); });
      chip.classList.add("is-active");
      var value = chip.dataset.filter;
      target.querySelectorAll("[data-category]").forEach(function (item) {
        item.hidden = !(value === "tous" || item.dataset.category === value);
      });
    });
  });

  /* Sélection d'un jour du calendrier ou d'un créneau (un seul à la fois).
     Le texte choisi est recopié dans l'élément désigné par data-output. */
  function singleSelect(selector) {
    document.querySelectorAll(selector).forEach(function (button) {
      button.addEventListener("click", function () {
        if (button.disabled) { return; }
        document.querySelectorAll(selector).forEach(function (b) { b.classList.remove("is-selected"); });
        button.classList.add("is-selected");
        var output = document.querySelector(button.dataset.output || "");
        if (output) { output.textContent = button.dataset.label || button.textContent; }
      });
    });
  }
  singleSelect(".cal-day[data-label]");
  singleSelect(".slot");
})();
