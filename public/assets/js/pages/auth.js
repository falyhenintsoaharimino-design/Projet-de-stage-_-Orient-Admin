/* ==========================================================================
   Connexion et inscription — appelle le vrai backend (api.js) et redirige
   vers l'espace correspondant au rôle renvoyé par le serveur.
   ========================================================================== */
(function () {
  "use strict";

  var root = document.body.dataset.root || "./";
  var api = window.OrientAdmin.api;
  var nav = window.OrientAdmin.nav;

  function afficherErreur(message) {
    var box = document.getElementById("auth-error");
    if (!box) { return; }
    box.textContent = message;
    box.hidden = false;
  }

  function allerVersEspace(utilisateur) {
    var espace = api.roleEspace(utilisateur);
    window.location.href = root + (espace ? nav.roles[espace].home : "index.html");
  }

  var formConnexion = document.getElementById("form-connexion");
  if (formConnexion) {
    formConnexion.addEventListener("submit", function (event) {
      event.preventDefault();
      var email = document.getElementById("email").value.trim();
      var mdp = document.getElementById("mdp").value;

      api.login(email, mdp).then(allerVersEspace).catch(function (erreur) {
        afficherErreur(erreur.status === 401
          ? "E-mail ou mot de passe incorrect."
          : "Connexion impossible pour le moment (" + erreur.message + ").");
      });
    });
  }

  var formInscription = document.getElementById("form-inscription");
  if (formInscription) {
    formInscription.addEventListener("submit", function (event) {
      event.preventDefault();
      var prenom = document.getElementById("prenom").value.trim();
      var nom = document.getElementById("nom").value.trim();
      var email = document.getElementById("email").value.trim();
      var mdp = document.getElementById("mdp").value;

      api.register(nom, prenom, email, mdp).then(function () {
        // L'inscription ne connecte pas automatiquement : on enchaîne avec
        // une vraie connexion pour récupérer le jeton et le profil complet.
        return api.login(email, mdp);
      }).then(allerVersEspace).catch(function (erreur) {
        afficherErreur(erreur.status === 409
          ? "Un compte existe déjà avec cet e-mail."
          : "Inscription impossible (" + erreur.message + ").");
      });
    });
  }
})();
