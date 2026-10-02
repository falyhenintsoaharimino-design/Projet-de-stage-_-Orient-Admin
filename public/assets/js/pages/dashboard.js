/* ==========================================================================
   Tableau de bord citoyen — salutation réelle + résumé des demandes.
   Le "Prochain rendez-vous" reste un texte statique tant que le backend
   n'a pas d'endpoint de rendez-vous (voir le rapport d'avancement).
   ========================================================================== */
(function () {
  "use strict";

  var api = window.OrientAdmin.api;
  var utilisateur = api.getUser();

  var salutation = document.getElementById("salutation");
  if (salutation && utilisateur) {
    salutation.textContent = "Bonjour " + utilisateur.prenom + ", voici où vous en êtes.";
  }

  var resume = document.getElementById("resume-demandes");
  if (resume) {
    api.listDemandes().then(function (demandes) {
      var enCours = demandes.filter(function (d) {
        return d.statut !== "cloturee" && d.statut !== "annulee";
      });
      resume.textContent = enCours.length === 0
        ? "Aucune demande en cours."
        : enCours.length + " demande(s) en cours sur " + demandes.length + " au total.";
    }).catch(function () {
      resume.textContent = "Impossible de charger vos demandes pour le moment.";
    });
  }
})();
