/* ==========================================================================
   Client API — point de passage unique entre le frontend et le backend
   Symfony. Toutes les pages qui parlent à l'API passent par les fonctions
   ci-dessous plutôt que d'appeler fetch() directement, pour que l'adresse du
   serveur, le jeton d'authentification et la gestion des erreurs restent à
   un seul endroit.

   Le frontend étant servi depuis public/ du projet Symfony (même origine),
   les chemins sont relatifs ("/api/...") : pas besoin de CORS en usage
   normal. Le sous-domaine peut être changé via window.OrientAdmin.apiBase
   si un jour le frontend est hébergé ailleurs que le backend.
   ========================================================================== */
(function () {
  "use strict";

  window.OrientAdmin = window.OrientAdmin || {};
  var API_BASE = window.OrientAdmin.apiBase || "/api";
  var TOKEN_KEY = "orientadmin_token";
  var USER_KEY = "orientadmin_user";

  /* ----- Stockage local (jeton + profil déjà récupéré) ----- */
  function getToken() { return localStorage.getItem(TOKEN_KEY); }
  function getUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || "null"); }
    catch (e) { return null; }
  }
  function setSession(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }
  function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  }
  function isLoggedIn() { return !!getToken(); }

  /* ----- Appel générique ----- */
  function request(path, options) {
    options = options || {};
    var headers = Object.assign({ "Content-Type": "application/json" }, options.headers || {});
    var token = getToken();
    if (token) { headers["Authorization"] = "Bearer " + token; }

    return fetch(API_BASE + path, {
      method: options.method || "GET",
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      // 204 No Content n'a pas de corps JSON à lire.
      if (response.status === 204) { return null; }
      return response.json().catch(function () { return null; }).then(function (data) {
        if (!response.ok) {
          var message = (data && (data.error || data.message)) || ("Erreur " + response.status);
          var error = new Error(message);
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  /* ----- Authentification ----- */
  function login(email, password) {
    return request("/login_check", { method: "POST", body: { email: email, password: password } })
      .then(function (data) {
        setSession(data.token, null);
        return request("/me").then(function (user) {
          setSession(data.token, user);
          return user;
        });
      });
  }

  function register(nom, prenom, email, password) {
    return request("/register", { method: "POST", body: { nom: nom, prenom: prenom, email: email, password: password } });
  }

  function logout() { clearSession(); }

  /* ----- Rôle Symfony (ROLE_CITOYEN...) -> espace du frontend (citoyen...) ----- */
  function roleEspace(user) {
    if (!user || !user.roles) { return null; }
    if (user.roles.indexOf("ROLE_ADMIN") !== -1) { return "admin"; }
    if (user.roles.indexOf("ROLE_RESPONSABLE") !== -1) { return "responsable"; }
    if (user.roles.indexOf("ROLE_AGENT") !== -1) { return "agent"; }
    if (user.roles.indexOf("ROLE_CITOYEN") !== -1) { return "citoyen"; }
    return null;
  }

  /* ----- Services et procédures (lecture publique) ----- */
  function listServices() { return request("/services"); }
  function getService(id) { return request("/services/" + id); }
  function listProcedures(serviceId) {
    return request("/procedures" + (serviceId ? "?serviceId=" + encodeURIComponent(serviceId) : ""));
  }
  function getProcedure(id) { return request("/procedures/" + id); }

  /* ----- Orientation et demandes ----- */
  function testerOrientation(texte) {
    return request("/orientation/tester", { method: "POST", body: { texte: texte } });
  }
  function creerDemande(texte) {
    return request("/demandes", { method: "POST", body: { texte: texte } });
  }
  function listDemandes() { return request("/demandes"); }
  function getDemande(id) { return request("/demandes/" + id); }
  function reorienterDemande(id, serviceId) {
    return request("/demandes/" + id + "/reorienter", { method: "PATCH", body: { serviceId: serviceId } });
  }
  function changerStatutDemande(id, statut) {
    return request("/demandes/" + id + "/statut", { method: "PATCH", body: { statut: statut } });
  }

  /* ----- Créneaux et rendez-vous ----- */
  function listCreneaux(serviceId, date) {
    var q = [];
    if (serviceId) { q.push("serviceId=" + encodeURIComponent(serviceId)); }
    if (date) { q.push("date=" + encodeURIComponent(date)); }
    return request("/creneaux" + (q.length ? "?" + q.join("&") : ""));
  }
  function creerRendezVous(demandeId, creneauId) {
    return request("/rendez-vous", { method: "POST", body: { demandeId: demandeId, creneauId: creneauId } });
  }
  function listRendezVous() { return request("/rendez-vous"); }
  function annulerRendezVous(id) { return request("/rendez-vous/" + id + "/annuler", { method: "PATCH" }); }

  window.OrientAdmin.api = {
    getToken: getToken, getUser: getUser, isLoggedIn: isLoggedIn, roleEspace: roleEspace,
    login: login, register: register, logout: logout,
    listServices: listServices, getService: getService,
    listProcedures: listProcedures, getProcedure: getProcedure,
    testerOrientation: testerOrientation, creerDemande: creerDemande,
    listDemandes: listDemandes, getDemande: getDemande,
    reorienterDemande: reorienterDemande, changerStatutDemande: changerStatutDemande,
    listCreneaux: listCreneaux, creerRendezVous: creerRendezVous,
    listRendezVous: listRendezVous, annulerRendezVous: annulerRendezVous
  };
})();
