/* ==========================================================================
   Génération de l'en-tête, du menu latéral, le pied de page et le bouton
   flottant vers l'assistant.
   Chaque page HTML contient seulement des emplacements vides :
     <div id="site-header"></div>, <aside id="site-sidebar"></aside>, <div id="site-footer"></div>
   et déclare son contexte sur la balise <body> :
     data-role="citoyen"   -> type d'utilisateur (voir nav-config.js)
     data-page="profil"    -> page active dans le menu
     data-root="../../"    -> chemin relatif vers la racine du projet
   ========================================================================== */
(function () {
  "use strict";

  var body = document.body;
  var role = body.dataset.role || "visiteur";
  var root = body.dataset.root || "./";
  var page = body.dataset.page || "";
  var nav = window.OrientAdmin.nav;
  var current = nav.roles[role];
  var api = window.OrientAdmin.api;

  /* ----- Garde d'accès : une page d'un espace connecté exige d'être
     authentifié avec le bon rôle, sinon on renvoie vers la connexion avant
     même d'afficher le contenu (évite l'effet "flash" du contenu protégé). */
  if (role !== "visiteur" && api) {
    var utilisateur = api.getUser();
    if (!api.isLoggedIn() || api.roleEspace(utilisateur) !== role) {
      window.location.replace(root + nav.authLinks.connexion);
      return;
    }
  }

  /* Fabrique un lien HTML à partir d'un élément de menu */
  function linkHtml(item, cssClass) {
    var active = item.id === page ? " is-active" : "";
    return '<a class="' + cssClass + active + '" href="' + root + item.href + '">' + item.label + "</a>";
  }

  function brandHtml(extraClass) {
    return '<a class="brand ' + (extraClass || "") + '" href="' + root + 'index.html">' +
      '<img class="brand__mark" src="' + root + nav.brandMark + '" alt="" width="28" height="36">' +
      '<span class="brand__word">' + nav.brand + "</span>" +
      "</a>";
  }

  /* ----- En-tête ----- */
  function renderHeader() {
    var target = document.getElementById("site-header");
    if (!target) { return; }

    var navHtml = "";
    var actionsHtml = "";

    if (role === "visiteur") {
      navHtml = '<nav class="site-nav" id="site-nav" aria-label="Navigation principale">' +
        current.items.map(function (i) { return linkHtml(i, ""); }).join("") + "</nav>";

      var utilisateurConnecte = api ? api.getUser() : null;
      var espaceConnecte = api ? api.roleEspace(utilisateurConnecte) : null;
      if (api && api.isLoggedIn() && espaceConnecte) {
        actionsHtml =
          '<a class="btn btn--outline btn--sm" href="' + root + nav.roles[espaceConnecte].home + '">Mon espace</a>' +
          '<button class="btn btn--primary btn--sm" type="button" data-logout>Déconnexion</button>';
      } else {
        actionsHtml =
          '<a class="btn btn--outline btn--sm" href="' + root + nav.authLinks.connexion + '">Connexion</a>' +
          '<a class="btn btn--primary btn--sm" href="' + root + nav.authLinks.inscription + '">S\'inscrire</a>';
      }
    } else {
      var prenom = utilisateur && utilisateur.prenom ? "Bonjour " + utilisateur.prenom + " · " : "";
      actionsHtml =
        '<span class="badge badge--info">' + prenom + current.label + "</span>" +
        '<button class="btn btn--outline btn--sm" type="button" data-logout>Quitter l\'espace</button>';
    }

    target.innerHTML =
      '<a class="skip-link" href="#contenu">Aller au contenu</a>' +
      '<header class="site-header"><div class="site-header__inner">' +
      '<button class="site-header__toggle" type="button" aria-label="Ouvrir le menu" data-toggle-menu><span class="burger"></span></button>' +
      brandHtml() +
      navHtml +
      '<div class="site-header__actions">' + actionsHtml + "</div>" +
      "</div></header>";
  }

  /* ----- Menu latéral (espaces connectés uniquement) ----- */
  function renderSidebar() {
    var target = document.getElementById("site-sidebar");
    if (!target || !current.groups) { return; }

    target.className = "sidebar";
    target.setAttribute("aria-label", "Menu de l'espace");
    target.innerHTML = current.groups.map(function (group) {
      return '<div class="sidebar__group"><p class="sidebar__title">' + group.title + "</p>" +
        group.items.map(function (i) { return linkHtml(i, "sidebar__link"); }).join("") + "</div>";
    }).join("");
  }

  /* ----- Pied de page ----- */
  function renderFooter() {
    var target = document.getElementById("site-footer");
    if (!target) { return; }
    target.innerHTML =
      '<footer class="site-footer"><div class="site-footer__inner container">' +
      '<div class="site-footer__brand">' + brandHtml("brand--footer") +
      "<p>Plateforme d'orientation des citoyens vers les services administratifs. " +
      "Informations indicatives ; la décision reste du ressort du service concerné.</p></div>" +
      '<div class="site-footer__col"><h4>Services</h4><ul>' +
      '<li><a href="' + root + 'pages/public/services.html">Catalogue des services</a></li>' +
      '<li><a href="' + root + 'pages/public/assistant.html">Assistant d\'orientation</a></li>' +
      '<li><a href="' + root + 'pages/public/comment-ca-marche.html">Comment ça marche</a></li>' +
      "</ul></div>" +
      '<div class="site-footer__col"><h4>Aide</h4><ul>' +
      '<li><a href="' + root + 'pages/public/faq.html">Questions fréquentes</a></li>' +
      '<li><a href="' + root + 'pages/public/contact.html">Contact</a></li>' +
      '<li><a href="' + root + nav.apercu + '">Plan du site</a></li>' +
      "</ul></div>" +
      '<div class="site-footer__col"><h4>Légal</h4><ul>' +
      '<li><a href="' + root + 'pages/public/mentions-legales.html">Mentions légales et confidentialité</a></li>' +
      "</ul></div>" +
      '</div><div class="site-footer__base container"><span>&copy; ' + nav.brand +
      " - Projet de licence</span><span>Données publiques à revalider auprès des administrations concernées.</span></div></footer>";
  }

  /* ----- Bouton flottant vers l'assistant ----- */
  function renderFab() {
    if (document.querySelector(".fab-assistant")) { return; }
    var target = current.groups
      ? (current.groups[0].items.filter(function (i) { return i.id === "assistant"; })[0] || {}).href
      : (current.items || []).filter(function (i) { return i.id === "assistant"; })[0].href;
    if (page === "assistant" || !target) { return; }
    var a = document.createElement("a");
    a.className = "fab-assistant";
    a.href = root + target;
    a.setAttribute("aria-label", "Ouvrir l'assistant d'orientation");
    a.title = "Assistant d'orientation";
    a.innerHTML = "💬";
    document.body.appendChild(a);
  }

  /* ----- Déconnexion ----- */
  function bindLogout() {
    var button = document.querySelector("[data-logout]");
    if (!button || !api) { return; }
    button.addEventListener("click", function () {
      api.logout();
      window.location.href = root + "index.html";
    });
  }

  /* ----- Bouton d'ouverture du menu sur mobile ----- */
  function bindToggle() {
    var button = document.querySelector("[data-toggle-menu]");
    if (!button) { return; }
    button.addEventListener("click", function () {
      var menu = document.getElementById("site-nav") || document.getElementById("site-sidebar");
      if (menu) { menu.classList.toggle("is-open"); }
    });
  }

  renderHeader();
  renderSidebar();
  renderFooter();
  renderFab();
  bindToggle();
  bindLogout();
})();
