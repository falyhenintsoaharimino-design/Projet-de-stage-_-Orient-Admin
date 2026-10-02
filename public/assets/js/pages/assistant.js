/* ==========================================================================
   Assistant d'orientation — branché sur le vrai moteur du backend
   (App\Service\OrientationEngine, via /api/orientation/tester pour un
   visiteur et /api/demandes pour un citoyen connecté, qui garde une trace
   dans "Mes demandes").
   ========================================================================== */
(function () {
  "use strict";

  var root = document.body.dataset.root || "./";
  var role = document.body.dataset.role || "visiteur";
  var api = window.OrientAdmin.api;
  var form = document.getElementById("chat-form");
  var input = document.getElementById("chat-input");
  var list = document.getElementById("chat-messages");
  if (!form || !input || !list) { return; }

  function addBubble(kind, text) {
    var bubble = document.createElement("div");
    bubble.className = "bubble bubble--" + kind;
    var p = document.createElement("p");
    p.textContent = text;
    bubble.appendChild(p);
    list.appendChild(bubble);
    list.scrollTop = list.scrollHeight;
    return bubble;
  }

  function addResultCard(bubble, resultat) {
    var card = document.createElement("div");
    card.className = "result-card";

    var title = document.createElement("p");
    title.className = "result-card__title";
    title.textContent = resultat.service.nom;

    var meta = document.createElement("p");
    meta.className = "text-small text-muted";
    meta.textContent = "Confiance : " + Math.round(resultat.confiance * 100) + " %"
      + (resultat.service.adresse ? " · " + resultat.service.adresse : "");

    var actions = document.createElement("div");
    actions.className = "result-card__actions";

    var catalogue = document.createElement("a");
    catalogue.className = "btn btn--outline btn--sm";
    catalogue.href = root + "pages/public/services.html";
    catalogue.textContent = "Voir le catalogue des services";

    var rdv = document.createElement("a");
    rdv.className = "btn btn--primary btn--sm";
    rdv.href = role === "citoyen"
      ? root + "pages/citoyen/prendre-rendez-vous.html"
      : root + "pages/auth/connexion.html";
    rdv.textContent = "Prendre rendez-vous";

    actions.appendChild(catalogue);
    actions.appendChild(rdv);
    card.appendChild(title);
    card.appendChild(meta);
    card.appendChild(actions);
    bubble.appendChild(card);
    list.scrollTop = list.scrollHeight;
  }

  function handleMessage(text) {
    addBubble("user", text);
    var attente = addBubble("bot", "Je regarde ça...");

    // Visiteur : simple essai, rien n'est enregistré (pas encore de compte).
    // Citoyen : la demande est réellement créée et suivie dans "Mes demandes".
    var appel = role === "citoyen" ? api.creerDemande(text) : api.testerOrientation(text);

    appel.then(function (resultat) {
      attente.remove();
      if (resultat.service) {
        var bubble = addBubble("bot", "Je vous recommande le service suivant :");
        addResultCard(bubble, resultat);
      } else {
        addBubble("bot", "Je n'ai pas bien compris votre demande. Pouvez-vous préciser le document ou la démarche concernée ?");
      }
    }).catch(function (erreur) {
      attente.remove();
      addBubble("bot", "Désolé, une erreur est survenue (" + erreur.message + "). Réessayez dans un instant.");
    });
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    var text = input.value.trim();
    if (!text) { return; }
    input.value = "";
    handleMessage(text);
  });

  /* Question transmise depuis l'accueil : assistant.html?q=... */
  var initialQuestion = new URLSearchParams(window.location.search).get("q");
  if (initialQuestion) { handleMessage(initialQuestion); }

  /* Boutons de suggestion : <button class="suggestion" data-suggestion="..."> */
  document.querySelectorAll("[data-suggestion]").forEach(function (button) {
    button.addEventListener("click", function () { handleMessage(button.dataset.suggestion); });
  });
})();
